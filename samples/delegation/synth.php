<?php

declare(strict_types=1);

namespace Arcp\Samples\Delegation;

/**
 * Final-pass synthesizer. Real version: an Anthropic call that folds
 * successful subagent outputs into prose, ignoring failed peers.
 *
 * @param list<\Job> $jobs
 */
function synthesize(string $request, array $jobs): string
{
    throw new \RuntimeException('not implemented');
}
