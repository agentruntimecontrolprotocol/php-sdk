<?php

declare(strict_types=1);

namespace Arcp\Transport;

use Amp\Cancellation;
use Arcp\Envelope\Envelope;

/**
 * Transport-agnostic message channel (RFC §22).
 *
 * Both directions are async: `send()` returns a future-style void, and
 * `receive()` blocks the calling fiber until an envelope arrives. The
 * implementation MAY drop the connection at any time; consumers see this
 * as `receive()` returning `null`.
 */
interface Transport
{
    /**
     * Push `$env` onto the wire.
     *
     * @throws \Arcp\Errors\TransportClosedException when the transport is
     *                                               no longer writable.
     *
     * Implementations MAY honor `$cancellation` and throw
     * `\Amp\CancelledException` if it fires before the write completes; the
     * bundled transports complete writes synchronously and do not.
     */
    public function send(Envelope $env, ?Cancellation $cancellation = null): void;

    /**
     * Pull the next envelope. Returns `null` if the peer closed cleanly.
     *
     * @throws \Arcp\Errors\TransportClosedException on unexpected
     *                                               transport failure (distinct from a clean close, which returns null).
     * @throws \Arcp\Errors\InvalidRequestException when an incoming frame
     *                                               is malformed and cannot be decoded.
     * @throws \Arcp\Errors\InvalidRequestException when an incoming frame
     *                                             carries an unknown message type.
     * @throws \Amp\CancelledException when `$cancellation` fires.
     */
    public function receive(?Cancellation $cancellation = null): ?Envelope;

    /** Close the transport. Idempotent. */
    public function close(): void;

    /** True iff the transport has been closed by either side. */
    public function isClosed(): bool;
}
