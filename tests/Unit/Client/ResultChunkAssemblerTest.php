<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit\Client;

use Arcp\Client\ResultChunkAssembler;
use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Execution\ResultChunk;
use PHPUnit\Framework\TestCase;

final class ResultChunkAssemblerTest extends TestCase
{
    public function testAssembleHappyPath(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 0, 'hello, '));
        $a->push(new ResultChunk('res_x', 1, 'world', more: false));
        self::assertTrue($a->isComplete('res_x'));
        self::assertSame('hello, world', $a->assemble('res_x'));
    }

    public function testOutOfOrderAssemblyStillContiguous(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 1, 'world', more: false));
        $a->push(new ResultChunk('res_x', 0, 'hello, '));
        self::assertSame('hello, world', $a->assemble('res_x'));
    }

    public function testAssembleWithoutTerminalChunkFails(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 0, 'partial'));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('terminal chunk');
        $a->assemble('res_x');
    }

    public function testMissingMiddleChunkIsRejected(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 0, 'a'));
        $a->push(new ResultChunk('res_x', 2, 'c', more: false));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('contiguous');
        $a->assemble('res_x');
    }

    public function testDuplicateConflictingChunkIsRejected(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 0, 'hello'));
        $this->expectException(InvalidArgumentException::class);
        $a->push(new ResultChunk('res_x', 0, 'HELLO'));
    }

    public function testDuplicateIdenticalChunkIsAccepted(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 0, 'hello, '));
        $a->push(new ResultChunk('res_x', 0, 'hello, '));
        $a->push(new ResultChunk('res_x', 1, 'world', more: false));
        self::assertSame('hello, world', $a->assemble('res_x'));
    }

    public function testBase64ChunkDecoded(): void
    {
        $a = new ResultChunkAssembler();
        $a->push(new ResultChunk('res_x', 0, base64_encode('hello'), encoding: 'base64', more: false));
        self::assertSame('hello', $a->assemble('res_x'));
    }

    public function testUnknownResultIdIsRejected(): void
    {
        $a = new ResultChunkAssembler();
        $this->expectException(InvalidArgumentException::class);
        $a->assemble('res_missing');
    }
}
