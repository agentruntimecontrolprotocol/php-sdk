<?php

declare(strict_types=1);

namespace Arcp\Transport;

use Amp\ByteStream\ReadableResourceStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\WritableResourceStream;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Arcp\Envelope\Envelope;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Errors\TransportClosedException;
use Arcp\Json\EnvelopeSerializer;

/**
 * Newline-delimited JSON transport over arbitrary readable/writable
 * streams (RFC §22 mandatory transport for stdio).
 *
 * Each envelope is encoded with {@see EnvelopeSerializer::encode()} and
 * appended with `\n`. The reader buffers input until it sees a newline,
 * decodes the envelope, and yields. EOF on read is reported as a
 * `null` return from {@see receive()}.
 */
final class StdioTransport implements Transport
{
    private string $readBuffer = '';
    private bool $closed = false;

    public function __construct(
        private readonly ReadableStream $reader,
        private readonly WritableStream $writer,
        private readonly EnvelopeSerializer $serializer,
    ) {
    }

    public static function fromResources(
        mixed $readResource,
        mixed $writeResource,
        EnvelopeSerializer $serializer,
    ): self {
        if (!\is_resource($readResource) || !\is_resource($writeResource)) {
            throw new InvalidArgumentException('stdio transport requires open resources');
        }
        return new self(
            new ReadableResourceStream($readResource),
            new WritableResourceStream($writeResource),
            $serializer,
        );
    }

    /** Wire the local process's stdin/stdout. */
    public static function localProcess(EnvelopeSerializer $serializer): self
    {
        $in = \STDIN;
        $out = \STDOUT;
        return self::fromResources($in, $out, $serializer);
    }

    #[\Override]
    public function send(Envelope $env, ?Cancellation $cancellation = null): void
    {
        if ($this->closed) {
            throw new TransportClosedException('stdio transport closed');
        }
        $line = $this->serializer->encode($env) . "\n";
        $this->writer->write($line);
    }

    #[\Override]
    public function receive(?Cancellation $cancellation = null): ?Envelope
    {
        while (true) {
            $newlinePos = strpos($this->readBuffer, "\n");
            if ($newlinePos !== false) {
                $line = substr($this->readBuffer, 0, $newlinePos);
                $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);
                $line = rtrim($line, "\r");
                if ($line === '') {
                    continue;
                }
                return $this->serializer->decode($line);
            }
            if ($this->closed) {
                return $this->drainResidualBuffer();
            }
            $chunk = $this->reader->read($cancellation);
            if ($chunk === null) {
                $this->closed = true;
                continue;
            }
            $this->readBuffer .= $chunk;
        }
    }

    /**
     * Decode and return any unterminated frame still in the buffer at EOF.
     * Tolerate non-conforming peers that close without a trailing newline;
     * their last envelope (typically tool.result or session.close) would
     * otherwise be lost.
     */
    private function drainResidualBuffer(): ?Envelope
    {
        $residual = rtrim($this->readBuffer, "\r");
        $this->readBuffer = '';
        if ($residual === '') {
            return null;
        }
        return $this->serializer->decode($residual);
    }

    #[\Override]
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        try {
            $this->writer->end();
        } catch (\Throwable) {
            // already closed; ignore
        }
        try {
            $this->reader->close();
        } catch (\Throwable) {
            // already closed; ignore
        }
    }

    #[\Override]
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
