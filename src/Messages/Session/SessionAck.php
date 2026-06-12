<?php

declare(strict_types=1);

namespace Arcp\Messages\Session;

use Arcp\Envelope\MessageType;
use Arcp\Errors\InvalidRequestException;

/**
 * ARCP v1.1 §6.5 — advisory event acknowledgement. The client declares
 * its highest processed session-scoped `event_seq`; the runtime MAY use
 * it to free buffered events earlier than the resume window.
 */
final readonly class SessionAck extends MessageType
{
    public function __construct(public int $lastProcessedSeq)
    {
        if ($lastProcessedSeq < 0) {
            throw new InvalidRequestException(
                'session.ack last_processed_seq must be non-negative',
            );
        }
    }

    #[\Override]
    public static function typeName(): string
    {
        return 'session.ack';
    }

    #[\Override]
    public function toArray(): array
    {
        return ['last_processed_seq' => $this->lastProcessedSeq];
    }

    #[\Override]
    public static function fromArray(array $data): static
    {
        $seq = $data['last_processed_seq']
            ?? throw new InvalidRequestException('session.ack last_processed_seq missing');
        if (!\is_int($seq)) {
            throw new InvalidRequestException('session.ack last_processed_seq must be integer');
        }
        return new self($seq);
    }
}
