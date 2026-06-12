<?php

declare(strict_types=1);

namespace Arcp\Internal\Runtime;

use Arcp\Envelope\Envelope;
use Arcp\Messages\Execution\JobSubmit;
use Arcp\Runtime\Job;
use Arcp\Runtime\Session;

/**
 * Parameter object bundling the per-submission context that the
 * {@see JobSubmitHandler} fiber and its terminal helpers thread through
 * together (session, inbound envelope, parsed payload, job).
 *
 * @internal
 */
final readonly class SubmitJobContextSpec
{
    public function __construct(
        public Session $session,
        public Envelope $env,
        public JobSubmit $msg,
        public Job $job,
    ) {
    }
}
