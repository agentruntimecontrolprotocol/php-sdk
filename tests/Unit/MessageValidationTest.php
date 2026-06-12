<?php

declare(strict_types=1);

namespace Arcp\Tests\Unit;

use Arcp\Errors\InvalidRequestException;
use Arcp\Messages\Session\SessionAck;
use Arcp\Messages\Session\SessionPing;
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
    public function testSessionAckRejectsNegativeSeq(): void
    {
        $this->expectException(InvalidRequestException::class);
        new SessionAck(-1);
    }

    public function testSessionAckFromArrayRequiresInt(): void
    {
        $this->expectException(InvalidRequestException::class);
        SessionAck::fromArray(['last_processed_seq' => 'lots']);
    }

    public function testSessionPingFromArrayRequiresSentAt(): void
    {
        $this->expectException(InvalidRequestException::class);
        SessionPing::fromArray(['nonce' => 'p_1']);
    }

    public function testCheckpointRestoreRequiresId(): void
    {
        $this->expectException(InvalidRequestException::class);
        new CheckpointRestore('');
    }

    public function testCheckpointRestoreFromArrayRequiresId(): void
    {
        $this->expectException(InvalidRequestException::class);
        CheckpointRestore::fromArray([]);
    }

    public function testSessionChallengeRejectsBlank(): void
    {
        $this->expectException(InvalidRequestException::class);
        new SessionChallenge('');
    }

    public function testToolInvokeRejectsEmptyName(): void
    {
        $this->expectException(InvalidRequestException::class);
        new ToolInvoke('');
    }

    public function testToolInvokeFromArrayRejectsBadArguments(): void
    {
        $this->expectException(InvalidRequestException::class);
        ToolInvoke::fromArray(['tool' => 't', 'arguments' => 'not-an-object']);
    }

    public function testJobProgressRejectsOutOfRange(): void
    {
        $this->expectException(InvalidRequestException::class);
        new JobProgress(101);
    }

    public function testHumanChoiceRequestRejectsEmptyOptions(): void
    {
        $this->expectException(InvalidRequestException::class);
        new HumanChoiceRequest('p', [], new \DateTimeImmutable());
    }

    public function testSubscribeEventRejectsEmpty(): void
    {
        $this->expectException(InvalidRequestException::class);
        new SubscribeEvent([]);
    }

    public function testLogEventRejectsBadLevel(): void
    {
        $this->expectException(InvalidRequestException::class);
        new LogEvent('verbose', 'oh no');
    }

    public function testLogEventRejectsEmptyMessage(): void
    {
        $this->expectException(InvalidRequestException::class);
        new LogEvent('info', '');
    }

    public function testMetricEventRejectsEmptyName(): void
    {
        $this->expectException(InvalidRequestException::class);
        new MetricEvent('', 0, 'count');
    }

    public function testMetricEventRejectsEmptyUnit(): void
    {
        $this->expectException(InvalidRequestException::class);
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
        $this->expectException(InvalidRequestException::class);
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
        $this->expectException(InvalidRequestException::class);
        new PeerInfo('', '0.1');
    }

    public function testPeerInfoFromArrayRejectsMissing(): void
    {
        $this->expectException(InvalidRequestException::class);
        PeerInfo::fromArray(['kind' => 'cli']);
    }
}
