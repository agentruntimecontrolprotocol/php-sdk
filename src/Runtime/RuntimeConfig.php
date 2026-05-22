<?php

declare(strict_types=1);

namespace Arcp\Runtime;

use Arcp\Auth\AuthRouter;
use Arcp\Clock\ClockInterface;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Extensions\ExtensionRegistry;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\Credentials\CredentialProvisioner;
use Arcp\Runtime\Credentials\CredentialStore;
use Arcp\Store\EventLog;
use Psr\Log\LoggerInterface;

/**
 * Bundle of optional dependencies for {@see ARCPRuntime}, used as a single
 * parameter object via {@see ARCPRuntime::withConfig()} so callers can name
 * dependencies without piling positional arguments on the constructor.
 */
final readonly class RuntimeConfig
{
    /**
     * @size-check-suppress parameter-object DTO; bundles ARCPRuntime deps.
     */
    public function __construct(
        public ?MessageTypeRegistry $registry = null,
        public ?EventLog $eventLog = null,
        public ?ClockInterface $clock = null,
        public ?LoggerInterface $logger = null,
        public ?Capabilities $capabilities = null,
        public ?AuthRouter $authRouter = null,
        public ?ExtensionRegistry $extensions = null,
        public ?PeerInfo $runtimeIdentity = null,
        public ?CredentialProvisioner $credentialProvisioner = null,
        public ?CredentialStore $credentialStore = null,
    ) {
    }
}
