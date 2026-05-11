<?php

declare(strict_types=1);

namespace Arcp\Samples\PermissionChallenge;

use Arcp\Envelope\Envelope;

final class Patch
{
    public function __construct(public readonly string $diff)
    {
    }
}

final class ReviewVerdict
{
    public function __construct(
        public readonly bool $grant,
        public readonly string $reason,
    ) {
    }
}

function propose(string $ticket, ?string $priorDenial): Patch
{
    throw new \RuntimeException('not implemented');
}

function review(string $ticket, Envelope $request): ReviewVerdict
{
    // Reviewer parses the patch out of `request.payload['resource']`
    // or by looking it up by fingerprint, then runs the LLM.
    throw new \RuntimeException('not implemented');
}
