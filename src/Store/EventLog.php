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
        $this->migrateEventSeqColumn();
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
        if ($this->hasEventsColumn('outbound')) {
            return;
        }
        $this->pdo->exec('ALTER TABLE events ADD COLUMN outbound INTEGER NOT NULL DEFAULT 1');
        $placeholders = implode(',', array_fill(0, \count(self::INBOUND_TYPES), '?'));
        $stmt = $this->pdo->prepare(
            'UPDATE events SET outbound = 0 WHERE type IN (' . $placeholders . ')',
        );
        $stmt->execute(self::INBOUND_TYPES);
    }

    /**
     * Add the `event_seq` column (§6.3 resume / §6.5 ack release) to
     * pre-existing databases. New databases already have it via
     * schema.sql; legacy rows stay NULL (unsequenced) and are never
     * replayed by sequence.
     */
    private function migrateEventSeqColumn(): void
    {
        if ($this->hasEventsColumn('event_seq')) {
            return;
        }
        $this->pdo->exec('ALTER TABLE events ADD COLUMN event_seq INTEGER');
    }

    private function hasEventsColumn(string $name): bool
    {
        $cols = $this->pdo->query('PRAGMA table_info(events)');
        if ($cols === false) {
            return false;
        }
        foreach ($cols->fetchAll(\PDO::FETCH_ASSOC) as $col) {
            /** @var array<string, mixed>|false $col */
            if (\is_array($col) && ($col['name'] ?? null) === $name) {
                return true;
            }
        }
        return false;
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
                timestamp, payload_json, outbound, event_seq
            ) VALUES (
                :message_id, :session_id, :job_id, :stream_id, :trace_id,
                :type, :priority, :correlation_id, :idempotency_key,
                :timestamp, :payload_json, :outbound, :event_seq
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
            ':event_seq' => $env->eventSeq,
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
     * §6.3 resume replay: outbound sequenced envelopes for `$sessionId`
     * with `event_seq > $lastEventSeq`, in sequence order.
     *
     * @return iterable<Envelope>
     */
    public function replaySince(SessionId $sessionId, int $lastEventSeq): iterable
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT payload_json FROM events
            WHERE session_id = :session_id AND outbound = 1
              AND event_seq IS NOT NULL AND event_seq > :seq
            ORDER BY event_seq ASC
            SQL);
        $stmt->bindValue(':session_id', (string) $sessionId, \PDO::PARAM_STR);
        $stmt->bindValue(':seq', $lastEventSeq, \PDO::PARAM_INT);
        $stmt->execute();
        while (($json = $stmt->fetchColumn()) !== false) {
            if (!\is_string($json)) {
                throw new InternalErrorException('event log row has non-string payload_json');
            }
            yield $this->serializer->decode($json);
        }
    }

    /**
     * The smallest buffered `event_seq` for a session, used to detect a
     * §6.3 resume that falls outside the buffer (RESUME_WINDOW_EXPIRED).
     * Returns null when nothing sequenced is buffered.
     */
    public function earliestBufferedSeq(SessionId $sessionId): ?int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            SELECT MIN(event_seq) FROM events
            WHERE session_id = :session_id AND outbound = 1 AND event_seq IS NOT NULL
            SQL);
        $stmt->execute([':session_id' => (string) $sessionId]);
        $min = $stmt->fetchColumn();
        return \is_int($min) || (\is_string($min) && $min !== '') ? (int) $min : null;
    }

    /**
     * §6.5: free buffered events the client has acknowledged. Deletes
     * outbound sequenced envelopes with `event_seq <= $lastProcessedSeq`
     * for the session; later resumes only need events above the
     * watermark.
     *
     * @return int number of rows released
     */
    public function releaseAcked(SessionId $sessionId, int $lastProcessedSeq): int
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            DELETE FROM events
            WHERE session_id = :session_id AND outbound = 1
              AND event_seq IS NOT NULL AND event_seq <= :seq
            SQL);
        $stmt->bindValue(':session_id', (string) $sessionId, \PDO::PARAM_STR);
        $stmt->bindValue(':seq', $lastProcessedSeq, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
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
