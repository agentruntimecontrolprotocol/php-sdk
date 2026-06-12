<?php

declare(strict_types=1);

namespace Arcp\Auth;

use Arcp\Messages\Session\Auth;
use Arcp\Messages\Session\PeerInfo;

/**
 * Pluggable verification of an inbound `session.hello` auth block (§6.1).
 *
 * Implementations declare which scheme name(s) they handle and accept or
 * reject the credential. The {@see \Arcp\Runtime\ARCPRuntime} dispatches
 * through a {@see AuthRouter} that keeps one scheme per name.
 */
interface AuthScheme
{
    /** Wire scheme name (`bearer` or `anonymous`, §6.1). */
    public function name(): string;

    public function verify(Auth $auth, PeerInfo $client): AuthResult;
}
