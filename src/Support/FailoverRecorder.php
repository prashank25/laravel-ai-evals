<?php

namespace Prashank\AiEvals\Support;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\Events\AgentFailedOver;

/**
 * Collects the failovers the SDK performs, keyed by run, so a sample knows which hops were skipped.
 *
 * One instance lives in the container per application and listens once, so repeated prompts never
 * stack listeners. Each prompt reads its own run's failovers and flushes the buffer when it is done.
 */
final class FailoverRecorder
{
    /**
     * @var array<string, array<int, Failover>>
     */
    private array $failovers = [];

    public static function for(Application $app): self
    {
        if (! $app->bound(self::class)) {
            $app->instance(self::class, $recorder = new self);
            $app->make(Dispatcher::class)->listen(AgentFailedOver::class, $recorder->record(...));
        }

        return $app->make(self::class);
    }

    public function record(AgentFailedOver $event): void
    {
        $this->failovers[$event->invocationId][] = Failover::fromEvent($event);
    }

    /**
     * @return array<int, Failover>
     */
    public function during(string $invocationId): array
    {
        return $this->failovers[$invocationId] ?? [];
    }

    public function flush(): void
    {
        $this->failovers = [];
    }
}
