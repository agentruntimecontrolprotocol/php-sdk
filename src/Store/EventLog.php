<?php

declare(strict_types=1);

namespace Arcp\Store;

use Arcp\Clock\ClockInterface;
use Arcp\Clock\SystemClock;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InternalErrorException;
use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\StreamId;
use Arcp\Ids\TraceId;
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
final readonly class EventLog
{
    private ClockInterface $clock;

    public function __construct(
        private \PDO $pdo,
        private EnvelopeSerializer $serializer,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();

        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = (string) file_get_contents(__DIR__ . '/schema.sql');
        $this->pdo->exec($schema);
        $this->migrateDirectionColumn();
    }

    /**
     * Add the `outbound` direction column to pre-existing databases that
     * were created before the resume/backfill direction fix. New databases
     * already have it via schema.sql. Existing rows are backfilled with a
     * type-prefix heuristic: known client→runtime command types are marked
     * inbound (0), everything else defaults to outbound (1).
     */
    private function migrateDirectionColumn(): void
    {
        $cols = $this->pdo->query('PRAGMA table_info(events)');
        if ($cols === false) {
            return;
        }
        foreach ($cols->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            /** @var array<string, mixed>|false $col */
            if (\is_array($col) && ($col['name'] ?? null) === 'outbound') {
                return;
            }
        }
        $this->pdo->exec('ALTER TABLE events ADD COLUMN outbound INTEGER NOT NULL DEFAULT 1');
        $placeholders = implode(',', array_fill(0, \count(self::INBOUND_TYPES), '?'));
        $stmt = $this->pdo->prepare(
            'UPDATE events SET outbound = 0 WHERE type IN (' . $placeholders . ')',
        );
        $stmt->execute(self::INBOUND_TYPES);
    }

    /**
     * Wire types that only travel client→runtime, used by the migration
     * backfill heuristic.
     *
     * @var list<string>
     */
    private const array INBOUND_TYPES = [
        'job.submit', 'session.ping', 'session.ack', 'session.close',
        'job.cancel', 'interrupt', 'session.resume', 'lease.refresh',
        'job.subscribe', 'job.unsubscribe', 'session.list_jobs',
        'artifact.put', 'artifact.fetch', 'artifact.release',
        'job.schedule', 'workflow.start', 'agent.delegate', 'agent.handoff',
    ];

    public static function inMemory(
        EnvelopeSerializer $serializer,
        ?ClockInterface $clock = null,
    ): self {
        return new self(new \PDO('sqlite::memory:'), $serializer, $clock);
    }

    public static function fromFile(
        string $path,
        EnvelopeSerializer $serializer,
        ?ClockInterface $clock = null,
    ): self {
        return new self(new \PDO('sqlite:' . $path), $serializer, $clock);
    }

    /**
     * Append `$env` to the log. Returns `true` if the envelope was inserted,
     * `false` if a row with the same `id` already exists (dedup hit).
     */
    public function append(Envelope $env, bool $outbound = true): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO events (
                message_id, session_id, job_id, stream_id, trace_id,
                type, priority, correlation_id, idempotency_key,
                timestamp, payload_json, outbound
            ) VALUES (
                :message_id, :session_id, :job_id, :stream_id, :trace_id,
                :type, :priority, :correlation_id, :idempotency_key,
                :timestamp, :payload_json, :outbound
            )
            SQL);
        $stmt->execute($this->bindRow($env, $outbound));
        return $stmt->rowCount() === 1;
    }

    /** @return array<string, string|int|null> */
    private function bindRow(Envelope $env, bool $outbound): array
    {
        return [
            ':outbound' => $outbound ? 1 : 0,
            ':message_id' => (string) $env->id,
            ':session_id' => $env->sessionId instanceof SessionId ? (string) $env->sessionId : null,
            ':job_id' => $env->jobId instanceof JobId ? (string) $env->jobId : null,
            ':stream_id' => $env->streamId instanceof StreamId ? (string) $env->streamId : null,
            ':trace_id' => $env->traceId instanceof TraceId ? (string) $env->traceId : null,
            ':type' => $env->type(),
            ':priority' => $env->priority->value,
            ':correlation_id' => $env->correlationId instanceof MessageId
                ? (string) $env->correlationId
                : null,
            ':idempotency_key' => $env->idempotencyKey instanceof IdempotencyKey
                ? (string) $env->idempotencyKey
                : null,
            ':timestamp' => $env->timestamp->format(\DateTimeInterface::RFC3339_EXTENDED),
            ':payload_json' => $this->serializer->encode($env),
        ];
    }

    /** True iff the message id has already been logged. */
    public function hasMessageId(string $messageId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM events WHERE message_id = :id LIMIT 1');
        $stmt->execute([':id' => $messageId]);
        return $stmt->fetchColumn() !== false;
    }

    /** Fetch a single envelope by message id, or null if unknown. */
    public function findByMessageId(string $messageId): ?Envelope
    {
        $stmt = $this->pdo->prepare(
            'SELECT payload_json FROM events WHERE message_id = :id LIMIT 1',
        );
        $stmt->execute([':id' => $messageId]);
        $json = $stmt->fetchColumn();
        if ($json === false) {
            return null;
        }
        if (!\is_string($json)) {
            throw new InternalErrorException('event log row has non-string payload_json');
        }
        return $this->serializer->decode($json);
    }

    /**
     * Replay envelopes after `$afterMessageId` in insertion order. Pass an
     * empty string to start from the beginning.
     *
     * @return iterable<Envelope>
     */
    public function replayAfter(string $afterMessageId, ?int $limit = null): iterable
    {
        $startRowId = $afterMessageId === '' ? 0 : $this->rowIdFor($afterMessageId);
        $stmt = $this->prepareReplayQuery($startRowId, $limit);
        while (($json = $stmt->fetchColumn()) !== false) {
            if (!\is_string($json)) {
                throw new InternalErrorException('event log row has non-string payload_json');
            }
            yield $this->serializer->decode($json);
        }
    }

    /**
     * Replay envelopes after `$afterMessageId` scoped to a single session.
     * This is the resume primitive: each session may only see its own
     * envelopes (RFC §19 invariant: only resume sessions under the same
     * authenticated principal).
     *
     * If `$afterMessageId` is non-empty and references an envelope that
     * does not belong to `$sessionId`, throws `InvalidRequestException`.
     *
     * @return iterable<Envelope>
     */
    public function replayAfterForSession(
        string $afterMessageId,
        SessionId $sessionId,
        ?int $limit = null,
    ): iterable {
        if ($afterMessageId !== '') {
            $rowSessionId = $this->sessionIdFor($afterMessageId);
            if ($rowSessionId !== (string) $sessionId) {
                throw new InvalidRequestException(
                    'after_message_id does not belong to the requesting session',
                    ['after_message_id' => $afterMessageId],
                );
            }
        }
        $startRowId = $afterMessageId === '' ? 0 : $this->rowIdFor($afterMessageId);
        $stmt = $this->prepareReplayQueryForSession($startRowId, (string) $sessionId, $limit);
        while (($json = $stmt->fetchColumn()) !== false) {
            if (!\is_string($json)) {
                throw new InternalErrorException('event log row has non-string payload_json');
            }
            yield $this->serializer->decode($json);
        }
    }

    private function sessionIdFor(string $messageId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT session_id FROM events WHERE message_id = :id LIMIT 1');
        $stmt->execute([':id' => $messageId]);
        /** @var string|false|null $value */
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new InvalidRequestException(
                'after_message_id not present in log',
                ['after_message_id' => $messageId],
            );
        }
        return $value;
    }

    private function prepareReplayQueryForSession(
        int $startRowId,
        string $sessionId,
        ?int $limit,
    ): \PDOStatement {
        // Replay only runtime-originated (outbound) envelopes: a resuming
        // client / subscriber must never receive its own past commands back
        // (RFC §6.3). Inbound rows remain for dedup/audit only.
        $sql = 'SELECT payload_json FROM events
            WHERE rowid > :rowid AND session_id = :session_id AND outbound = 1
            ORDER BY rowid ASC';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':rowid', $startRowId, \PDO::PARAM_INT);
        $stmt->bindValue(':session_id', $sessionId, \PDO::PARAM_STR);
        if ($limit !== null) {
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt;
    }

    private function rowIdFor(string $messageId): int
    {
        $stmt = $this->pdo->prepare('SELECT rowid FROM events WHERE message_id = :id LIMIT 1');
        $stmt->execute([':id' => $messageId]);
        /** @var int|string|false $rowIdValue */
        $rowIdValue = $stmt->fetchColumn();
        if ($rowIdValue === false) {
            throw new InvalidRequestException(
                'after_message_id not present in log',
                ['after_message_id' => $messageId],
            );
        }
        return (int) $rowIdValue;
    }

    private function prepareReplayQuery(int $startRowId, ?int $limit): \PDOStatement
    {
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
        return $stmt;
    }

    /**
     * Cache a `(principal, idempotency_key) → message_id` mapping with a
     * retention horizon (RFC §6.4). Returns the previously cached outcome
     * message id if one exists, else `null`.
     */
    public function rememberIdempotent(IdempotencyRecord $record): ?string
    {
        $existing = $this->lookupIdempotent($record->principal, $record->idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }
        // Upsert: lookupIdempotent() returns null for an expired row without
        // deleting it (#110), so refresh any stale entry in place rather than
        // colliding with the (principal, idempotency_key) primary key.
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO idempotency_cache
                (principal, idempotency_key, outcome_message_id, expires_at)
            VALUES (:principal, :key, :outcome, :expires)
            ON CONFLICT(principal, idempotency_key) DO UPDATE SET
                outcome_message_id = excluded.outcome_message_id,
                expires_at = excluded.expires_at
            SQL);
        $stmt->execute([
            ':principal' => $record->principal,
            ':key' => $record->idempotencyKey,
            ':outcome' => $record->outcomeMessageId,
            ':expires' => $record->expiresAt->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
        return null;
    }

    /**
     * Delete idempotency-cache rows whose retention horizon has passed.
     * Intended to be driven by a periodic sweep rather than read paths.
     *
     * @return int number of rows purged
     */
    public function purgeExpiredIdempotent(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM idempotency_cache WHERE expires_at <= :now',
        );
        $stmt->execute([
            ':now' => $this->clock->now()->format(\DateTimeInterface::RFC3339_EXTENDED),
        ]);
        return $stmt->rowCount();
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
            throw new InternalErrorException('idempotency_cache row malformed');
        }
        $expiresStr = $row['expires_at'];
        $outcome = $row['outcome_message_id'];
        if (!\is_string($expiresStr) || !\is_string($outcome)) {
            throw new InternalErrorException('idempotency_cache row column types unexpected');
        }
        $expires = new \DateTimeImmutable($expiresStr);
        if ($expires <= $this->clock->now()) {
            // Treat an expired entry as absent. Deletion is deferred to
            // purgeExpiredIdempotent() so this lookup stays side-effect free.
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
