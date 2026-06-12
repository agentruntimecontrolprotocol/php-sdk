<?php

declare(strict_types=1);

namespace Arcp\Envelope;

use Arcp\Messages\Artifacts\ArtifactFetch;
use Arcp\Messages\Artifacts\ArtifactPut;
use Arcp\Messages\Artifacts\ArtifactRef;
use Arcp\Messages\Artifacts\ArtifactRelease;
use Arcp\Messages\Artifacts\ArtifactReleased;
use Arcp\Messages\Control\Backpressure;
use Arcp\Messages\Control\CheckpointCreate;
use Arcp\Messages\Control\CheckpointRestore;
use Arcp\Messages\Control\Interrupt;
use Arcp\Messages\Control\Nack;
use Arcp\Messages\Execution\AgentDelegate;
use Arcp\Messages\Execution\AgentHandoff;
use Arcp\Messages\Execution\JobAccepted;
use Arcp\Messages\Execution\JobCancel;
use Arcp\Messages\Execution\JobCancelled;
use Arcp\Messages\Execution\JobCheckpoint;
use Arcp\Messages\Execution\JobError;
use Arcp\Messages\Execution\JobEvent;
use Arcp\Messages\Execution\JobHeartbeat;
use Arcp\Messages\Execution\JobResult;
use Arcp\Messages\Execution\JobSchedule;
use Arcp\Messages\Execution\JobSubmit;
use Arcp\Messages\Execution\ResultChunk;
use Arcp\Messages\Execution\WorkflowComplete;
use Arcp\Messages\Execution\WorkflowStart;
use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputCancelled;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;
use Arcp\Messages\Permissions\LeaseExtended;
use Arcp\Messages\Permissions\LeaseGranted;
use Arcp\Messages\Permissions\LeaseRefresh;
use Arcp\Messages\Permissions\LeaseRevoked;
use Arcp\Messages\Permissions\PermissionDeny;
use Arcp\Messages\Permissions\PermissionGrant;
use Arcp\Messages\Permissions\PermissionRequest;
use Arcp\Messages\Session\Jobs;
use Arcp\Messages\Session\ListJobs;
use Arcp\Messages\Session\SessionAck;
use Arcp\Messages\Session\SessionAuthenticate;
use Arcp\Messages\Session\SessionChallenge;
use Arcp\Messages\Session\SessionClose;
use Arcp\Messages\Session\SessionClosed;
use Arcp\Messages\Session\SessionEvicted;
use Arcp\Messages\Session\SessionHello;
use Arcp\Messages\Session\SessionPing;
use Arcp\Messages\Session\SessionPong;
use Arcp\Messages\Session\SessionRefresh;
use Arcp\Messages\Session\SessionRejected;
use Arcp\Messages\Session\SessionUnauthenticated;
use Arcp\Messages\Session\SessionWelcome;
use Arcp\Messages\Streaming\StreamChunk;
use Arcp\Messages\Streaming\StreamClose;
use Arcp\Messages\Streaming\StreamError;
use Arcp\Messages\Streaming\StreamOpen;
use Arcp\Messages\Subscriptions\JobSubscribe;
use Arcp\Messages\Subscriptions\JobSubscribed;
use Arcp\Messages\Subscriptions\JobUnsubscribe;
use Arcp\Messages\Subscriptions\SubscribeClosed;
use Arcp\Messages\Subscriptions\SubscribeEvent;
use Arcp\Messages\Telemetry\TraceSpan;

/**
 * Convenience builder that pre-registers every core ARCP v1.1 message-type
 * class (plus this SDK's extension surfaces) into a fresh
 * {@see MessageTypeRegistry}.
 *
 * Tests and samples that need the full catalog should call
 * {@see MessageCatalog::create()}; the runtime calls it during boot.
 */
final class MessageCatalog
{
    /** @var list<class-string<MessageType>> */
    private const array CORE_CLASSES = [
        // Session
        SessionHello::class,
        SessionChallenge::class,
        SessionAuthenticate::class,
        SessionWelcome::class,
        SessionUnauthenticated::class,
        SessionRejected::class,
        SessionRefresh::class,
        ListJobs::class,
        Jobs::class,
        SessionEvicted::class,
        SessionClose::class,
        SessionClosed::class,
        SessionPing::class,
        SessionPong::class,
        SessionAck::class,
        // Control
        Nack::class,
        JobCancel::class,
        Interrupt::class,
        Backpressure::class,
        CheckpointCreate::class,
        CheckpointRestore::class,
        // Jobs (§7, §8)
        JobSubmit::class,
        JobAccepted::class,
        JobEvent::class,
        JobResult::class,
        JobError::class,
        JobCancelled::class,
        JobHeartbeat::class,
        JobCheckpoint::class,
        ResultChunk::class,
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
        JobSubscribe::class,
        JobSubscribed::class,
        SubscribeEvent::class,
        JobUnsubscribe::class,
        SubscribeClosed::class,
        // Artifacts
        ArtifactPut::class,
        ArtifactFetch::class,
        ArtifactRef::class,
        ArtifactRelease::class,
        ArtifactReleased::class,
        // Telemetry
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
