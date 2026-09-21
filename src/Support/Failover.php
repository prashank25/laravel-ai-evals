<?php

namespace Prashank\AiEvals\Support;

use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Exceptions\FailoverableException;

/**
 * One hop the SDK abandoned during a run: the provider / model that failed and why.
 */
final readonly class Failover
{
    public function __construct(
        public string $provider,
        public string $model,
        public FailoverableException $exception,
    ) {
        //
    }

    public static function fromEvent(AgentFailedOver $event): self
    {
        return new self($event->provider->name(), $event->model, $event->exception);
    }

    public function label(): string
    {
        return "{$this->provider}/{$this->model}";
    }
}
