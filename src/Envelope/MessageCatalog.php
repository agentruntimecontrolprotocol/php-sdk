<?php

declare(strict_types=1);

namespace Arcp\Envelope;

use Arcp\Messages\Session\SessionOpen;
use Arcp\Messages\Session\SessionChallenge;
use Arcp\Messages\Session\SessionAuthenticate;
use Arcp\Messages\Session\SessionAccepted;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionRefresh;
use Arcp\Messages\Session\SessionEvicted;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Control\Ping;
use Arcp\Messages\Control\Pong;
use Arcp\Messages\Control\Ack;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Control\Cancel;
use Arcp\Messages\Control\CancelAccepted;
use Arcp\Messages\Control\CancelRefused;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Resume;
use Arcp\Messages\Control\Backpressure;
use Arcp\Messages\Control\CheckpointCreate;
use Arcp\Messages\Control\CheckpointRestore;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Messages\Execution\ToolResult;
use Arcp\Messages\Execution\ToolError;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobStarted;
use Arcp\Messages\Execution\JobProgress;
use Arcp\Messages\Execution\JobHeartbeat;
use Arcp\Messages\Execution\JobCheckpoint;
use Arcp\Messages\Execution\JobCompleted;
use Arcp\Messages\Execution\JobFailed;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Execution\WorkflowStart;
use Arcp\Messages\Execution\WorkflowComplete;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\AgentHandoff;
use Arcp\Messages\Streaming\StreamOpen;
use Arcp\Messages\Streaming\StreamChunk;
use Arcp\Messages\Streaming\StreamClose;
use Arcp\Messages\Streaming\StreamError;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputCancelled;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Permissions\PermissionGrant;
use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\LeaseExtended;
use Arcp\Messages\Permissions\LeaseRevoked;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Subscriptions\Subscribe;
use Arcp\Messages\Subscriptions\SubscribeAccepted;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Messages\Subscriptions\Unsubscribe;
use Arcp\Messages\Subscriptions\SubscribeClosed;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Telemetry\EventEmit;
use Arcp\Messages\Telemetry\LogEvent;
use Arcp\Messages\Telemetry\MetricEvent;
use Arcp\Messages\Telemetry\TraceSpan;

/**
 * Convenience builder that pre-registers every core RFC §6.2 message-type
 * class into a fresh {@see MessageTypeRegistry}.
 *
 * Tests and samples that need the full catalog should call
 * {@see MessageCatalog::create()}; the runtime calls it during boot.
 */
final class MessageCatalog
{
    /** @var list<class-string<MessageType>> */
    private const array CORE_CLASSES = [
        // Session
        SessionOpen::class,
        SessionChallenge::class,
        SessionAuthenticate::class,
        SessionAccepted::class,
        SessionUnauthenticated::class,
        SessionRejected::class,
        SessionRefresh::class,
        SessionEvicted::class,
        SessionClose::class,
        // Control
        Ping::class,
        Pong::class,
        Ack::class,
        Nack::class,
        Cancel::class,
        CancelAccepted::class,
        CancelRefused::class,
        Interrupt::class,
        Resume::class,
        Backpressure::class,
        CheckpointCreate::class,
        CheckpointRestore::class,
        // Execution
        ToolInvoke::class,
        ToolResult::class,
        ToolError::class,
        JobAccepted::class,
        JobStarted::class,
        JobProgress::class,
        JobHeartbeat::class,
        JobCheckpoint::class,
        JobCompleted::class,
        JobFailed::class,
        JobCancelled::class,
        JobSchedule::class,
        WorkflowStart::class,
        WorkflowComplete::class,
        AgentDelegate::class,
        AgentHandoff::class,
        // Streaming
        StreamOpen::class,
        StreamChunk::class,
        StreamClose::class,
        StreamError::class,
        // Human
        HumanInputRequest::class,
        HumanInputResponse::class,
        HumanChoiceRequest::class,
        HumanChoiceResponse::class,
        HumanInputCancelled::class,
        // Permissions
        PermissionRequest::class,
        PermissionGrant::class,
        PermissionDeny::class,
        LeaseGranted::class,
        LeaseExtended::class,
        LeaseRevoked::class,
        LeaseRefresh::class,
        // Subscriptions
        Subscribe::class,
        SubscribeAccepted::class,
        SubscribeEvent::class,
        Unsubscribe::class,
        SubscribeClosed::class,
        // Artifacts
        ArtifactPut::class,
        ArtifactFetch::class,
        ArtifactRef::class,
        ArtifactRelease::class,
        // Telemetry
        EventEmit::class,
        LogEvent::class,
        MetricEvent::class,
        TraceSpan::class,
    ];

    private function __construct()
    {
    }

    public static function create(): MessageTypeRegistry
    {
        $registry = new MessageTypeRegistry();
        foreach (self::CORE_CLASSES as $class) {
            $registry->register($class);
        }
        return $registry;
    }

    /** @return list<class-string<MessageType>> */
    public static function classes(): array
    {
        return self::CORE_CLASSES;
    }
}
