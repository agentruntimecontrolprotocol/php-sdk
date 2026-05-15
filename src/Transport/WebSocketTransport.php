<?php

declare(strict_types=1);

namespace Arcp\Transport;

use Amp\Cancellation;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketMessage;
use Arcp\Envelope\Envelope;
use Arcp\Json\EnvelopeSerializer;

/**
 * RFC §22 mandatory WebSocket transport. Wraps an Amp v3
 * {@see WebsocketClient} (server-side or client-side) and routes each
 * envelope through a single text frame containing the JSON-encoded body.
 *
 * Sidecar binary frames are deferred to v0.2; v0.1 advertises only
 * `binary_encoding: ["base64"]`, so binary streams travel inline.
 */
final readonly class WebSocketTransport implements Transport
{
    public function __construct(
        private WebsocketClient $ws,
        private EnvelopeSerializer $serializer,
    ) {
    }

    #[\Override]
    public function send(Envelope $env, ?Cancellation $cancellation = null): void
    {
        if ($this->ws->isClosed()) {
            throw new \RuntimeException('websocket closed');
        }
        $this->ws->sendText($this->serializer->encode($env));
    }

    #[\Override]
    public function receive(?Cancellation $cancellation = null): ?Envelope
    {
        $message = $this->ws->receive($cancellation);
        if (!$message instanceof WebsocketMessage) {
            return null;
        }
        $payload = $message->buffer($cancellation);
        return $this->serializer->decode($payload);
    }

    #[\Override]
    public function close(): void
    {
        if (!$this->ws->isClosed()) {
            $this->ws->close();
        }
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->ws->isClosed();
    }
}
