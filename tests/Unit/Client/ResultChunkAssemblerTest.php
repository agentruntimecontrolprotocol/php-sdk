<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Client;

use Arcp\Client\ResultChunkAssembler;
use Arcp\Errors\InvalidRequestException;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\ResultChunkEncoding;
use PHPUnit\Framework\TestCase;

final class ResultChunkAssemblerTest extends TestCase
{
    private static function chunk(
        string $resultId,
        int $seq,
        string $data,
        ResultChunkEncoding $encoding = ResultChunkEncoding::Utf8,
        bool $more = true,
    ): JobEvent {
        // §8.4: chunks ride as job.event kind result_chunk.
        return new JobEvent('result_chunk', new \DateTimeImmutable('2026-05-09T12:00:00Z'), [
            'result_id' => $resultId,
            'chunk_seq' => $seq,
            'data' => $data,
            'encoding' => $encoding->value,
            'more' => $more,
        ]);
    }

    public function testNonChunkKindsAreIgnored(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new JobEvent('status', new \DateTimeImmutable(), ['phase' => 'running']));
        $this->expectException(InvalidRequestException::class);
        $a->assemble('res_missing');
    }

    public function testAssembleHappyPath(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'hello, '));
        $a->push(self::chunk('res_x', 1, 'world', more: false));
        self::assertTrue($a->isComplete('res_x'));
        self::assertSame('hello, world', $a->assemble('res_x'));
    }

    public function testOutOfOrderAssemblyStillContiguous(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 1, 'world', more: false));
        $a->push(self::chunk('res_x', 0, 'hello, '));
        self::assertSame('hello, world', $a->assemble('res_x'));
    }

    public function testAssembleWithoutTerminalChunkFails(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'partial'));
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('terminal chunk');
        $a->assemble('res_x');
    }

    public function testMissingMiddleChunkIsRejected(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'a'));
        $a->push(self::chunk('res_x', 2, 'c', more: false));
        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('contiguous');
        $a->assemble('res_x');
    }

    public function testDuplicateConflictingChunkIsRejected(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'hello'));
        $this->expectException(InvalidRequestException::class);
        $a->push(self::chunk('res_x', 0, 'HELLO'));
    }

    public function testDuplicateIdenticalChunkIsAccepted(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'hello, '));
        $a->push(self::chunk('res_x', 0, 'hello, '));
        $a->push(self::chunk('res_x', 1, 'world', more: false));
        self::assertSame('hello, world', $a->assemble('res_x'));
    }

    public function testBase64ChunkDecoded(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, base64_encode('hello'), encoding: ResultChunkEncoding::Base64, more: false));
        self::assertSame('hello', $a->assemble('res_x'));
    }

    public function testUnknownResultIdIsRejected(): void
    {
        $a = new ResultChunkAssembler();
        $this->expectException(InvalidRequestException::class);
        $a->assemble('res_missing');
    }

    public function testAssembleReleasesBufferedChunks(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'hello, '));
        $a->push(self::chunk('res_x', 1, 'world', more: false));
        self::assertSame('hello, world', $a->assemble('res_x'));
        // After assembly the result is forgotten; a fresh push starts over.
        self::assertFalse($a->isComplete('res_x'));
        $a->push(self::chunk('res_x', 0, 'fresh', more: false));
        self::assertSame('fresh', $a->assemble('res_x'));
    }

    public function testForgetClearsPendingStream(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(self::chunk('res_x', 0, 'partial'));
        $a->forget('res_x');
        $this->expectException(InvalidRequestException::class);
        $a->assemble('res_x');
    }
}
