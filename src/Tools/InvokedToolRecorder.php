<?php

namespace Prashank\AiEvals\Tools;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;

/**
 * Collects the tools the SDK invokes, keyed by run, so a trace can identify what actually executed.
 *
 * One instance lives in the container per application and listens once, so repeated prompts never
 * stack listeners. Each prompt reads its own run's tools and flushes the buffer when it is done.
 */
final class InvokedToolRecorder
{
    /**
     * @var array<string, array<string, Tool>>
     */
    private array $invoked = [];

    public static function for(Application $app): self
    {
        if (! $app->bound(self::class)) {
            $app->instance(self::class, $recorder = new self);
            $app->make(Dispatcher::class)->listen(InvokingTool::class, $recorder->record(...));
        }

        return $app->make(self::class);
    }

    public function record(InvokingTool $event): void
    {
        $this->invoked[$event->invocationId][$event->toolInvocationId] = $event->tool;
    }

    /**
     * @return array<int, Tool>
     */
    public function invokedDuring(string $invocationId): array
    {
        return array_values($this->invoked[$invocationId] ?? []);
    }

    public function flush(): void
    {
        $this->invoked = [];
    }
}
