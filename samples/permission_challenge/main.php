<?php

declare(strict_types=1);

/*
 * permission_challenge — generator proposes; reviewer holds veto via
 * permission.request.
 *
 * RFC §15.4 (permission challenge), §6.4 (idempotency).
 */

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/agents.php';

use Arcp\Client\ARCPClient;
use Arcp\Errors\ARCPException;
use Arcp\Errors\ErrorCode;
use Arcp\Ids\IdempotencyKey;
use Arcp\Samples\PermissionChallenge\Patch;

use function Arcp\Samples\PermissionChallenge\propose;
use function Arcp\Samples\PermissionChallenge\review;

const MAX_REVISIONS = 4;

function fingerprint(string $diff): string
{
    return substr(hash('sha256', $diff), 0, 16);
}

/**
 * Generator: ask for a `repo.write` lease scoped to this exact diff.
 * Returns the granted lease id.
 */
function requestApply(ARCPClient $client, string $ticketId, Patch $patch): string
{
    $fp = fingerprint($patch->diff);
    // Same key per (ticket, diff): identical patch dedupes at runtime.
    $key = new IdempotencyKey("review:{$ticketId}:{$fp}");
    // Real impl: send `permission.request` with the key, await
    // `lease.granted` / `permission.deny`, throw PermissionDeniedException.
    throw new \RuntimeException('not implemented');
}

/**
 * Reviewer side: drain inbound permission.request envelopes, ask the
 * reviewer LLM, send back permission.grant or permission.deny.
 */
function reviewerLoop(ARCPClient $reviewer, string $ticket): void
{
    // Real impl uses $reviewer->onPermissionRequest(static function ($req) {
    //   $verdict = review(...); send permission.grant|permission.deny;
    // }).
    throw new \RuntimeException('not implemented');
}

function main(): void
{
    // Two sessions, one per agent. In production they'd be in different
    // processes on different runtimes; the message contract is identical.
    /** @var ARCPClient $generator */
    $generator = elided(); // transport, identity, auth elided
    /** @var ARCPClient $reviewer */
    $reviewer = elided();

    $ticketId = 'JIRA-4812';
    $ticket = 'Reject JWTs whose `aud` does not match the configured audience. Add a unit test.';

    // Reviewer drains in the background.
    \Amp\async(static fn () => reviewerLoop($reviewer, $ticket));

    $priorDenial = null;
    try {
        for ($i = 0; $i < MAX_REVISIONS; $i++) {
            $patch = propose($ticket, $priorDenial);
            try {
                $lease = requestApply($generator, $ticketId, $patch);
            } catch (ARCPException $exc) {
                if ($exc->code() !== ErrorCode::PermissionDenied) {
                    throw $exc;
                }
                $priorDenial = $exc->getMessage();
                continue;
            }
            printf("applied %s lease=%s\n", fingerprint($patch->diff), $lease);
            return;
        }
        echo "abandoned after max_revisions\n";
    } finally {
        $generator->close();
        $reviewer->close();
    }
}

function elided(): ARCPClient
{
    throw new \RuntimeException('not implemented');
}

main();
