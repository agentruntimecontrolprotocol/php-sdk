<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Errors\InvalidRequestException;
use Arcp\Ids\ArtifactId;
use Arcp\Ids\Id;
use Arcp\Ids\IdempotencyKey;
use Arcp\Ids\JobId;
use Arcp\Ids\LeaseId;
use Arcp\Ids\MessageId;
use Arcp\Ids\SessionId;
use Arcp\Ids\SpanId;
use Arcp\Ids\StreamId;
use Arcp\Ids\SubscriptionId;
use Arcp\Ids\TraceId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdsTest extends TestCase
{
    public function testEmptyValueRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        new SessionId('');
    }

    public function testWhitespaceOnlyValueRejected(): void
    {
        $this->expectException(InvalidRequestException::class);
        new SessionId("   \t\n");
    }

    public function testStringableAndJsonSerializable(): void
    {
        $id = new SessionId('sess_abc');
        self::assertSame('sess_abc', (string) $id);
        self::assertSame('sess_abc', $id->jsonSerialize());
        self::assertSame('"sess_abc"', json_encode($id));
    }

    public function testEqualsRequiresSameTypeAndValue(): void
    {
        $a = new SessionId('sess_x');
        $b = new SessionId('sess_x');
        self::assertTrue($a->equals($b));

        $c = new SessionId('sess_y');
        self::assertFalse($a->equals($c));
    }

    public function testEqualsRejectsDifferentSubclass(): void
    {
        $a = new SessionId('id_x');
        $b = new JobId('id_x');
        // Same value, different concrete class → not equal.
        self::assertFalse($a->equals($b));
    }

    public function testFromJsonRejectsNonString(): void
    {
        $this->expectException(InvalidRequestException::class);
        SessionId::fromJson(42);
    }

    public function testFromJsonAcceptsString(): void
    {
        $id = SessionId::fromJson('sess_x');
        self::assertSame('sess_x', $id->value);
    }

    /** @return iterable<string, array{0: \Closure(): Id, 1: non-empty-string}> */
    public static function randomFactories(): iterable
    {
        yield 'session' => [SessionId::random(...), 'sess_'];
        yield 'message' => [MessageId::random(...), 'msg_'];
        yield 'job' => [JobId::random(...), 'job_'];
        yield 'stream' => [StreamId::random(...), 'str_'];
        yield 'subscription' => [SubscriptionId::random(...), 'sub_'];
        yield 'span' => [SpanId::random(...), 'span_'];
        yield 'lease' => [LeaseId::random(...), 'lease_'];
        yield 'artifact' => [ArtifactId::random(...), 'art_'];
    }

    /**
     * @param \Closure(): Id $factory
     * @param non-empty-string $prefix
     */
    #[DataProvider('randomFactories')]
    public function testRandomReturnsPrefixedUlid(\Closure $factory, string $prefix): void
    {
        $first = $factory();
        $second = $factory();

        self::assertStringStartsWith($prefix, (string) $first);
        self::assertStringStartsWith($prefix, (string) $second);
        self::assertNotSame((string) $first, (string) $second);
    }

    public function testTraceIdRandomIsValidW3CTraceparent(): void
    {
        $first = (string) TraceId::random();
        $second = (string) TraceId::random();

        $pattern = '/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/';
        self::assertMatchesRegularExpression($pattern, $first);
        self::assertMatchesRegularExpression($pattern, $second);
        self::assertNotSame($first, $second);
    }

    public function testFromTraceparentRoundTrips(): void
    {
        $tp = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $id = TraceId::fromTraceparent($tp);
        self::assertSame($tp, (string) $id);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $id->traceComponent());
    }

    public function testFromTraceparentRejectsMalformed(): void
    {
        $this->expectException(InvalidRequestException::class);
        TraceId::fromTraceparent('trace_01J');
    }

    public function testFromTraceparentRejectsAllZeroId(): void
    {
        $this->expectException(InvalidRequestException::class);
        TraceId::fromTraceparent('00-00000000000000000000000000000000-00f067aa0ba902b7-01');
    }

    public function testIdempotencyKeyHasNoRandomFactory(): void
    {
        // No `random()` factory by design (RFC §6.4): the key is always
        // provided by the caller. ReflectionClass is the runtime probe;
        // PHPStan knows the answer at analyze time, so we fence the call.
        $reflection = new \ReflectionClass(IdempotencyKey::class);
        self::assertFalse(
            $reflection->hasMethod('random'),
            'IdempotencyKey must not expose a random() factory',
        );
    }
}
