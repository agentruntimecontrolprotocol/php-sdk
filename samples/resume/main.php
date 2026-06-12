<?php

declare(strict_types=1);

/*
 * resume — §6.3 token-based session resume.
 *
 * The welcome carries a `resume_token` (rotated on every welcome) and
 * `resume_window_sec`. After a transport drop the session parks: the
 * job keeps running and sequenced events buffer. The client reconnects
 * with `session.hello {resume_token, last_event_seq}` and receives the
 * buffered events past that sequence, then continues live.
 *
 * ARCP v1.1 §6.3 (resume), §6.5 (ack), §8.3 (event_seq), §12
 * (RESUME_WINDOW_EXPIRED).
 */

require __DIR__ . '/../../vendor/autoload.php';

use Amp\Cancellation;

use function Amp\delay;

use Arcp\Auth\AnonymousAuth;
use Arcp\Auth\AuthRouter;
use Arcp\Client\ARCPClient;
use Arcp\Errors\ResumeWindowExpiredException;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Runtime\JobContext;
use Arcp\Runtime\ToolHandler;
use Arcp\Transport\MemoryTransport;

// §6.3/§14: resume is same-principal only. A router-less runtime mints a
// fresh opaque principal per anonymous hello, which would (correctly)
// reject every resume — so this demo pins one anonymous principal.
$runtime = new ARCPRuntime(authRouter: new AuthRouter([new AnonymousAuth('demo-user')]));
$runtime->registerTool('research', new class () implements ToolHandler {
    #[\Override]
    public function invoke(array $arguments, JobContext $ctx, ?Cancellation $cancellation = null): mixed
    {
        // A slow job: it outlives the first connection on purpose.
        foreach (['plan', 'gather', 'synthesize', 'critique', 'finalize'] as $i => $step) {
            $ctx->emitLog('info', "step: {$step}");
            delay(0.05);
        }
        return ['report' => 'CRDT survey 2026'];
    }
});

// First connection: open, submit, then drop mid-job (no session.close).
[$serverT1, $clientT1] = MemoryTransport::pair();
$serve1 = $runtime->serveAsync($serverT1);
$client1 = new ARCPClient($clientT1);
$welcome1 = $client1->open(Auth::anonymous(), new PeerInfo('resume-demo', '0.1'), new Capabilities());
printf("resume_token=%s window=%ds\n", (string) $welcome1->resumeToken, (int) $welcome1->resumeWindowSec);

// Submit in the background; we will not stay around for the result.
\Amp\async(static function () use ($client1): void {
    try {
        $client1->invokeTool('research', ['topic' => 'CRDT collaborative editing']);
    } catch (\Throwable) {
        // the transport drops mid-flight; the resumed session sees the result
    }
});
delay(0.12); // a few steps in...
$lastSeen = $client1->session->lastReceivedEventSeq ?? 0;
$clientT1->close(); // simulated crash: NOT session.close — the job keeps running
$serve1->await();   // the connection is gone; the session parks for the window
printf("dropped after event_seq=%d\n", $lastSeen);

// Second connection: resume with the token + last_event_seq. The runtime
// reattaches the same session, replays events we missed, and the job's
// terminal job.result arrives on the new transport.
[$serverT2, $clientT2] = MemoryTransport::pair();
$runtime->serveAsync($serverT2);
$client2 = new ARCPClient($clientT2);
$welcome2 = $client2->open(
    Auth::anonymous(),
    new PeerInfo('resume-demo', '0.1'),
    new Capabilities(),
    resumeToken: (string) $welcome1->resumeToken,
    lastEventSeq: $lastSeen,
);
printf(
    "resumed session=%s (same=%s), rotated_token=%s\n",
    (string) $welcome2->sessionId,
    (string) $welcome1->sessionId === (string) $welcome2->sessionId ? 'yes' : 'no',
    (string) $welcome2->resumeToken,
);

delay(0.3); // let the job finish and its buffered events stream in
printf("caught up to event_seq=%d\n", $client2->session->lastReceivedEventSeq ?? 0);

// A stale token (the pre-rotation one) is rejected: RESUME_WINDOW_EXPIRED.
[$serverT3, $clientT3] = MemoryTransport::pair();
$runtime->serveAsync($serverT3);
$client3 = new ARCPClient($clientT3);
try {
    $client3->open(
        Auth::anonymous(),
        new PeerInfo('resume-demo', '0.1'),
        new Capabilities(),
        resumeToken: (string) $welcome1->resumeToken, // rotated away above
        lastEventSeq: 0,
    );
} catch (ResumeWindowExpiredException $e) {
    printf("stale token rejected: %s\n", $e->getMessage());
}
$client3->close();

$client2->close();
