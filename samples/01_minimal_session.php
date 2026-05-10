<?php

declare(strict_types=1);

/*
 * 01 — Minimal session.
 *
 * Spins up an in-process runtime with the `none` auth scheme, opens a
 * client connection, performs the four-message handshake, and closes.
 * Demonstrates RFC §8 (session establishment) end-to-end.
 */

require __DIR__ . '/../vendor/autoload.php';

use Arcp\Auth\AuthRouter;
use Arcp\Auth\NoneAuth;
use Arcp\Client\ARCPClient;
use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\Capabilities;
use Arcp\Messages\Session\PeerInfo;
use Arcp\Runtime\ARCPRuntime;
use Arcp\Transport\MemoryTransport;

$runtime = new ARCPRuntime(authRouter: new AuthRouter([new NoneAuth()]));
[$serverT, $clientT] = MemoryTransport::pair();
$serverFuture = $runtime->serveAsync($serverT);

$client = new ARCPClient($clientT);
$accepted = $client->open(
    Auth::none(),
    new PeerInfo('arcp-sample', '0.1', principal: 'demo@example.com'),
    new Capabilities(streaming: true, anonymous: true),
);

printf("Session established: %s\n", (string) $accepted->sessionId);
$runtime = $accepted->runtime;
printf("Runtime identity:    %s/%s\n", $runtime !== null ? $runtime->kind : '?', $runtime !== null ? $runtime->version : '?');

$client->close();
$serverFuture->await();
echo "Session closed cleanly.\n";
