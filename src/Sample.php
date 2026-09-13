<?php

namespace Prashank\AiEvals;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Prashank\AiEvals\Tools\ToolTrace;

/**
 * One agent run: the prompt that was sent and everything the agent produced.
 */
final readonly class Sample
{
    /**
     * @param  array<string, mixed>|null  $structured
     */
    public function __construct(
        public string $input,
        public string $output,
        public ToolTrace $tools,
        public ?array $structured = null,
    ) {
        //
    }

    /**
     * @param  array<int, Tool>  $invokedTools  Tool instances the SDK reported invoking during this run.
     */
    public static function fromResponse(string $input, AgentResponse $response, ?Agent $agent = null, array $invokedTools = []): self
    {
        return new self(
            input: $input,
            output: (string) $response,
            tools: ToolTrace::record($agent, $response, $invokedTools),
            structured: $response instanceof StructuredAgentResponse ? $response->toArray() : null,
        );
    }

    /**
     * @return array<int, string>
     */
    public function toolNames(): array
    {
        return $this->tools->names();
    }
}
