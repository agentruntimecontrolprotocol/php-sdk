<?php

declare(strict_types=1);

namespace Arcp\Envelope;

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
        \Arcp\Messages\Session\SessionOpen::class,
        \Arcp\Messages\Session\SessionChallenge::class,
        \Arcp\Messages\Session\SessionAuthenticate::class,
        \Arcp\Messages\Session\SessionAccepted::class,
        \Arcp\Messages\Session\SessionUnauthenticated::class,
        \Arcp\Messages\Session\SessionRejected::class,
        \Arcp\Messages\Session\SessionRefresh::class,
        \Arcp\Messages\Session\SessionEvicted::class,
        \Arcp\Messages\Session\SessionClose::class,
        // Control
        \Arcp\Messages\Control\Ping::class,
        \Arcp\Messages\Control\Pong::class,
        \Arcp\Messages\Control\Ack::class,
        \Arcp\Messages\Control\Nack::class,
        \Arcp\Messages\Control\Cancel::class,
        \Arcp\Messages\Control\CancelAccepted::class,
        \Arcp\Messages\Control\CancelRefused::class,
        \Arcp\Messages\Control\Interrupt::class,
        \Arcp\Messages\Control\Resume::class,
        \Arcp\Messages\Control\Backpressure::class,
        \Arcp\Messages\Control\CheckpointCreate::class,
        \Arcp\Messages\Control\CheckpointRestore::class,
        // Execution
        \Arcp\Messages\Execution\ToolInvoke::class,
        \Arcp\Messages\Execution\ToolResult::class,
        \Arcp\Messages\Execution\ToolError::class,
        \Arcp\Messages\Execution\JobAccepted::class,
        \Arcp\Messages\Execution\JobStarted::class,
        \Arcp\Messages\Execution\JobProgress::class,
        \Arcp\Messages\Execution\JobHeartbeat::class,
        \Arcp\Messages\Execution\JobCheckpoint::class,
        \Arcp\Messages\Execution\JobCompleted::class,
        \Arcp\Messages\Execution\JobFailed::class,
        \Arcp\Messages\Execution\JobCancelled::class,
        \Arcp\Messages\Execution\JobSchedule::class,
        \Arcp\Messages\Execution\WorkflowStart::class,
        \Arcp\Messages\Execution\WorkflowComplete::class,
        \Arcp\Messages\Execution\AgentDelegate::class,
        \Arcp\Messages\Execution\AgentHandoff::class,
        // Streaming
        \Arcp\Messages\Streaming\StreamOpen::class,
        \Arcp\Messages\Streaming\StreamChunk::class,
        \Arcp\Messages\Streaming\StreamClose::class,
        \Arcp\Messages\Streaming\StreamError::class,
        // Human
        \Arcp\Messages\Human\HumanInputRequest::class,
        \Arcp\Messages\Human\HumanInputResponse::class,
        \Arcp\Messages\Human\HumanChoiceRequest::class,
        \Arcp\Messages\Human\HumanChoiceResponse::class,
        \Arcp\Messages\Human\HumanInputCancelled::class,
        // Permissions
        \Arcp\Messages\Permissions\PermissionRequest::class,
        \Arcp\Messages\Permissions\PermissionGrant::class,
        \Arcp\Messages\Permissions\PermissionDeny::class,
        \Arcp\Messages\Permissions\LeaseGranted::class,
        \Arcp\Messages\Permissions\LeaseExtended::class,
        \Arcp\Messages\Permissions\LeaseRevoked::class,
        \Arcp\Messages\Permissions\LeaseRefresh::class,
        // Subscriptions
        \Arcp\Messages\Subscriptions\Subscribe::class,
        \Arcp\Messages\Subscriptions\SubscribeAccepted::class,
        \Arcp\Messages\Subscriptions\SubscribeEvent::class,
        \Arcp\Messages\Subscriptions\Unsubscribe::class,
        \Arcp\Messages\Subscriptions\SubscribeClosed::class,
        // Artifacts
        \Arcp\Messages\Artifacts\ArtifactPut::class,
        \Arcp\Messages\Artifacts\ArtifactFetch::class,
        \Arcp\Messages\Artifacts\ArtifactRef::class,
        \Arcp\Messages\Artifacts\ArtifactRelease::class,
        // Telemetry
        \Arcp\Messages\Telemetry\EventEmit::class,
        \Arcp\Messages\Telemetry\LogEvent::class,
        \Arcp\Messages\Telemetry\MetricEvent::class,
        \Arcp\Messages\Telemetry\TraceSpan::class,
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
