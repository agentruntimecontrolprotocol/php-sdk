<?php

declare(strict_types=1);

namespace Arcp\Client\Handlers;

use Arcp\Messages\Human\HumanChoiceRequest;
use Arcp\Messages\Human\HumanChoiceResponse;
use Arcp\Messages\Human\HumanInputRequest;
use Arcp\Messages\Human\HumanInputResponse;

/** Closure-backed handler. Ergonomic for tests and small samples. */
final class CallbackHumanInputHandler implements HumanInputHandler
{
    /**
     * @param \Closure(HumanInputRequest): HumanInputResponse $onInput
     * @param \Closure(HumanChoiceRequest): HumanChoiceResponse $onChoice
     */
    public function __construct(
        private readonly \Closure $onInput,
        private readonly \Closure $onChoice,
    ) {
    }

    #[\Override]
    public function onInputRequest(HumanInputRequest $req): HumanInputResponse
    {
        return ($this->onInput)($req);
    }

    #[\Override]
    public function onChoiceRequest(HumanChoiceRequest $req): HumanChoiceResponse
    {
        return ($this->onChoice)($req);
    }
}
