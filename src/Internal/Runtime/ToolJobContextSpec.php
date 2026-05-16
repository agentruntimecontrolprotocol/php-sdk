<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Messages\Execution\ToolInvoke;
use Arcp\Runtime\Job;
use Arcp\Runtime\Session;

/**
 * Parameter object bundling the per-invocation context that the
 * {@see ToolInvocationHandler} fiber and its terminal helpers thread
 * through together (session, inbound envelope, parsed payload, job).
 *
 * @internal
 */
final readonly class ToolJobContextSpec
{
    public function __construct(
        public Session $session,
        public Envelope $env,
        public ToolInvoke $msg,
        public Job $job,
    ) {
    }
}
