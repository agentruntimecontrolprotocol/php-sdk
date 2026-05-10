<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Errors\InvalidArgumentException;
use Arcp\Messages\Control\CancelRefused;
use Arcp\Messages\Control\CheckpointRestore;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Messages\Session\SessionChallenge;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Messages\Telemetry\LogEvent;
use Arcp\Messages\Telemetry\MetricEvent;
use PHPUnit\Framework\TestCase;

/**
 * Failure-path coverage for the message-class fromArray() validators.
 * Round-trip happy paths live in {@see MessageCatalogRoundTripTest}.
 */
final class MessageValidationTest extends TestCase
{
    public function testCancelRefusedRequiresReason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CancelRefused('');
    }

    public function testCancelRefusedFromArrayRejectsNonString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CancelRefused::fromArray(['reason' => 42]);
    }

    public function testCheckpointRestoreRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CheckpointRestore('');
    }

    public function testCheckpointRestoreFromArrayRequiresId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CheckpointRestore::fromArray([]);
    }

    public function testSessionChallengeRejectsBlank(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SessionChallenge('');
    }

    public function testToolInvokeRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ToolInvoke('');
    }

    public function testToolInvokeFromArrayRejectsBadArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ToolInvoke::fromArray(['tool' => 't', 'arguments' => 'not-an-object']);
    }

    public function testJobProgressRejectsOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new JobProgress(101);
    }

    public function testHumanChoiceRequestRejectsEmptyOptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new HumanChoiceRequest('p', [], new \DateTimeImmutable());
    }

    public function testSubscribeEventRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SubscribeEvent([]);
    }

    public function testLogEventRejectsBadLevel(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LogEvent('verbose', 'oh no');
    }

    public function testLogEventRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LogEvent('info', '');
    }

    public function testMetricEventRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricEvent('', 0, 'count');
    }

    public function testMetricEventRejectsEmptyUnit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MetricEvent('m', 0, '');
    }

    public function testAuthRoundTripWithToken(): void
    {
        $a = Auth::bearer('t');
        self::assertSame(['scheme' => 'bearer', 'token' => 't'], $a->toArray());
        $b = Auth::fromArray(['scheme' => 'bearer', 'token' => 't']);
        self::assertEquals($a, $b);
    }

    public function testAuthFromArrayRequiresScheme(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Auth::fromArray([]);
    }

    public function testPeerInfoRoundTrip(): void
    {
        $p = new PeerInfo('cli', '0.1', fingerprint: 'sha256:x', principal: 'me', trustLevel: 'trusted');
        $back = PeerInfo::fromArray($p->toArray());
        self::assertEquals($p, $back);
    }

    public function testPeerInfoRequiresKindAndVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PeerInfo('', '0.1');
    }

    public function testPeerInfoFromArrayRejectsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PeerInfo::fromArray(['kind' => 'cli']);
    }
}
