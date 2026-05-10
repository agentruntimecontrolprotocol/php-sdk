<?php

declare(strict_types=1);

namespace Arcp\Client\Handlers;

use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;

/**
 * Application hook for `human.input.request` / `human.choice.request`
 * envelopes (RFC §12). The default implementation prints to stdout —
 * production clients should plug in chat, ntfy, etc.
 */
interface HumanInputHandler
{
    public function onInputRequest(HumanInputRequest $req): HumanInputResponse;

    public function onChoiceRequest(HumanChoiceRequest $req): HumanChoiceResponse;
}
