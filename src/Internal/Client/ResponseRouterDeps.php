<?php

declare(strict_types=1);

namespace Arcp\Internal\Client;

use Arcp\Clock\ClockInterface;
use Arcp\Json\EnvelopeSerializer;
use Arcp\Runtime\PendingRegistry;
use Arcp\Runtime\Session;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;

/**
 * Bundle of collaborators used by {@see ResponseRouter}. Internal-only
 * parameter object so the router's constructor stays under the size budget.
 *
 * @internal
 */
final readonly class ResponseRouterDeps
{
    /**
     * @size-check-suppress parameter-object DTO; bundles ResponseRouter deps.
     */
    public function __construct(
        public Transport $transport,
        public Session $session,
        public PendingRegistry $pending,
        public EnvelopeSerializer $serializer,
        public ClockInterface $clock,
        public LoggerInterface $logger,
    ) {
    }
}
