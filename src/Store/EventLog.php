<?php

declare(strict_types=1);

namespace Arcp\Store;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InternalException;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Json\EnvelopeSerializer;

/**
 * Append-only event log over PDO/SQLite (RFC §6.4 dedup, §19 resume).
 *
 * The log is the source of truth for:
 *   - transport idempotency (RFC §6.4) — duplicate `id` is silently ignored.
 *   - logical idempotency (RFC §6.4) — `(principal, idempotency_key)` →
 *     prior outcome message id.
 *   - resume (RFC §19) — replay envelopes after a given message id, in
 *     deterministic insertion order.
 *
 * Synchronous PDO is acceptable for v0.1: writes are short, the database
 * is local, and the event loop does not block beyond a single insert.
 * v0.2 may move to `amphp/sqlite` if/when the package becomes stable.
 */
final class EventLog
{
    private \PDO $pdo;
    private ClockInterface $clock;
    private EnvelopeSerializer $serializer;

    public function __construct(
        \PDO $pdo,
        EnvelopeSerializer $serializer,
        ?ClockInterface $clock = null,
    ) {
        $this->pdo = $pdo;
        $this->serializer = $serializer;
        $this->clock = $clock ?? new SystemClock();

        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/schema.sql');
        $this->pdo->exec($schema);
    }

    public static function inMemory(EnvelopeSerializer $serializer, ?ClockInterface $clock = null): self
    {
        return new self(new \PDO('sqlite::memory:'), $serializer, $clock);
    }

    public static function fromFile(string $path, EnvelopeSerializer $serializer, ?ClockInterface $clock = null): self
    {
        return new self(new \PDO('sqlite:' . $path), $serializer, $clock);
    }

    /**
     * Append `$env` to the log. Returns `true` if the envelope was inserted,
     * `false` if a row with the same `id` already exists (dedup hit).
     */
    public function append(Envelope $env): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO events (
                message_id, session_id, job_id, stream_id, trace_id,
                type, priority, correlation_id, idempotency_key,
                timestamp, payload_json
            ) VALUES (
                :message_id, :session_id, :job_id, :stream_id, :trace_id,
                :type, :priority, :correlation_id, :idempotency_key,
                :timestamp, :payload_json
            )
            SQL);

        $stmt->execute([
            ':message_id' => (string) $env->id,
            ':session_id' => $env->sessionId !== null ? (string) $env->sessionId : null,
            ':job_id' => $env->jobId !== null ? (string) $env->jobId : null,
            ':stream_id' => $env->streamId !== null ? (string) $env->streamId : null,
            ':trace_id' => $env->traceId !== null ? (string) $env->traceId : null,
            ':type' => $env->type(),
            ':priority' => $env->priority->value,
            ':correlation_id' => $env->correlationId !== null ? (string) $env->correlationId : null,
            ':idempotency_key' => $env->idempotencyKey !== null ? (string) $env->idempotencyKey : null,
            ':timestamp' => $env->timestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            ':payload_json' => $this->serializer->encode($env),
        ]);

        return $stmt->rowCount() === 1;
    }

    /** True iff the message id has already been logged. */
    public function hasMessageId(string $messageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM events WHERE message_id = :id LIMIT 1');
        $stmt->execute([':id' => $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Replay envelopes after `$afterMessageId` in insertion order. Pass an
     * empty string to start from the beginning.
     *
     * @return iterable<Envelope>
     */
    public function replayAfter(string $afterMessageId, ?int $limit = null): iterable
    {
        $startRowId = 0;
        if ($afterMessageId !== '') {
            $stmt = $this->pdo->prepare('SELECT rowid FROM events WHERE message_id = :id LIMIT 1');
            $stmt->execute([':id' => $afterMessageId]);
            /** @var int|string|false $rowIdValue */
            $rowIdValue = $stmt->fetchColumn();
            if ($rowIdValue === false) {
                throw new InvalidArgumentException(
                    'after_message_id not present in log',
                    ['after_message_id' => $afterMessageId],
                );
            }
            $startRowId = (int) $rowIdValue;
        }

        $sql = 'SELECT payload_json FROM events WHERE rowid > :rowid ORDER BY rowid ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':rowid', $startRowId, \PDO::PARAM_INT);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();

        while (($json = $stmt->fetchColumn()) !== false) {
            if (!\is_string($json)) {
                throw new InternalException('event log row has non-string payload_json');
            }
            yield $this->serializer->decode($json);
        }
    }

    /**
     * Cache a `(principal, idempotency_key) → message_id` mapping with a
     * retention horizon (RFC §6.4). Returns the previously cached outcome
     * message id if one exists, else `null`.
     */
    public function rememberIdempotent(
        string $principal,
        string $idempotencyKey,
        string $outcomeMessageId,
        \DateTimeImmutable $expiresAt,
    ): ?string {
        $existing = $this->lookupIdempotent($principal, $idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO idempotency_cache (principal, idempotency_key, outcome_message_id, expires_at)
            VALUES (:principal, :key, :outcome, :expires)
            SQL);
        $stmt->execute([
            ':principal' => $principal,
            ':key' => $idempotencyKey,
            ':outcome' => $outcomeMessageId,
            ':expires' => $expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
        return null;
    }

    public function lookupIdempotent(string $principal, string $idempotencyKey): ?string
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT outcome_message_id, expires_at FROM idempotency_cache
            WHERE principal = :principal AND idempotency_key = :key
            LIMIT 1
            SQL);
        $stmt->execute([':principal' => $principal, ':key' => $idempotencyKey]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!isset($row['outcome_message_id'], $row['expires_at'])) {
            throw new InternalException('idempotency_cache row malformed');
        }
        $expiresStr = $row['expires_at'];
        $outcome = $row['outcome_message_id'];
        if (!\is_string($expiresStr) || !\is_string($outcome)) {
            throw new InternalException('idempotency_cache row column types unexpected');
        }
        $expires = new \DateTimeImmutable($expiresStr);
        if ($expires <= $this->clock->now()) {
            // Lazy GC of an expired entry.
            $del = $this->pdo->prepare(<<<'SQL'
                DELETE FROM idempotency_cache
                WHERE principal = :principal AND idempotency_key = :key
                SQL);
            $del->execute([':principal' => $principal, ':key' => $idempotencyKey]);
            return null;
        }
        return $outcome;
    }

    /** Total number of envelopes in the log. */
    public function count(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM events');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
