<?php

declare(strict_types=1);

namespace Arcp\Client;

use Arcp\Client\Handlers\HumanInputHandler;
use Arcp\Client\Handlers\PermissionHandler;
use Arcp\Clock\ClockInterface;
use Arcp\Envelope\MessageTypeRegistry;
use Arcp\Transport\Transport;
use Psr\Log\LoggerInterface;

/**
 * Bundle of optional dependencies for {@see ARCPClient}, used as a single
 * parameter object via {@see ARCPClient::withConfig()}.
 */
final readonly class ClientConfig
{
    /**
     * @size-check-suppress parameter-object DTO; bundles ARCPClient deps.
     */
    public function __construct(
        public Transport $transport,
        public ?MessageTypeRegistry $registry = null,
        public ?ClockInterface $clock = null,
        public ?LoggerInterface $logger = null,
        public ?HumanInputHandler $humanInputHandler = null,
        public ?PermissionHandler $permissionHandler = null,
    ) {
    }
}
