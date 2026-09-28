<?php

namespace Prashank\AiEvals;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Prashank\AiEvals\Support\Failover;
use Prashank\AiEvals\Tools\ToolTrace;

/**
 * One agent run: the prompt that was sent and everything the agent produced.
 */
final readonly class Sample
{
    /**
     * @param  array<string, mixed>|null  $structured
     * @param  string|null  $provider  The provider that produced the output, when the response reports it.
     * @param  string|null  $model  The model that produced the output, when the response reports it.
     * @param  array<int, Failover>  $failovers  Hops the SDK abandoned before this output was produced, in order.
     * @param  TextUsage|null  $usage  Token usage summed across every step of the run, when the response reports it.
     */
    public function __construct(
        public string $input,
        public string $output,
        public ToolTrace $tools,
        public ?array $structured = null,
        public ?string $provider = null,
        public ?string $model = null,
        public array $failovers = [],
        public ?TextUsage $usage = null,
    ) {
        //
    }

    /**
     * @param  array<int, Tool>  $invokedTools  Tool instances the SDK reported invoking during this run.
     * @param  array<int, Failover>  $failovers  Failovers the SDK reported during this run.
     */
    public static function fromResponse(string $input, AgentResponse $response, ?Agent $agent = null, array $invokedTools = [], array $failovers = []): self
    {
        return new self(
            input: $input,
            output: (string) $response,
            tools: ToolTrace::record($agent, $response, $invokedTools),
            structured: $response instanceof StructuredAgentResponse ? $response->toArray() : null,
            provider: $response->meta->provider,
            model: $response->meta->model,
            failovers: $failovers,
            usage: $response->usage,
        );
    }

    /**
     * @return array<int, string>
     */
    public function toolNames(): array
    {
        return $this->tools->names();
    }

    /**
     * The "provider/model" that served this run, or null when the response did not report it.
     */
    public function servedBy(): ?string
    {
        if ($this->provider === null) {
            return null;
        }

        return $this->model === null ? $this->provider : "{$this->provider}/{$this->model}";
    }

    /**
     * Whether the run was served by the given model, or by a dated snapshot of it.
     *
     * Providers often report the snapshot an alias resolved to, such as gpt-5.4-2026-03-05 for gpt-5.4.
     * Only a date suffix counts, so a sibling like gpt-5.4-mini is a different model.
     */
    public function servedByModel(string $model): bool
    {
        if ($this->model === null) {
            return false;
        }

        if ($this->model === $model) {
            return true;
        }

        return Str::startsWith($this->model, $model)
            && preg_match('/^-(\d{4}-\d{2}-\d{2}|\d{8})$/', Str::after($this->model, $model)) === 1;
    }

    /**
     * "Sample #n" for assertion messages, plus the provider / model that served it when known.
     *
     * @param  int  $index  Zero-based position among the samples being asserted on.
     */
    public function label(int $index): string
    {
        $label = 'Sample #'.($index + 1);

        return $this->servedBy() === null ? $label : "{$label} [{$this->servedBy()}]";
    }

    public function failedOver(): bool
    {
        return $this->failovers !== [];
    }
}
