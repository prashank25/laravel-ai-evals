<?php

namespace Prashank\AiEvals\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\StructuredAnonymousAgent;

/**
 * LLM-as-judge. Uses structured output so there is no JSON parsing of free text.
 */
final readonly class Judge
{
    private function __construct(
        private string $scorer,
        private string $instructions,
    ) {
        //
    }

    public static function using(string $scorer): self
    {
        return new self($scorer, '');
    }

    public function instructions(string $instructions): self
    {
        return new self($this->scorer, $instructions);
    }

    /**
     * @param  array<string, string>  $extraFields  Extra string fields to ask the judge for, name => description.
     */
    public function prompt(string $prompt, array $extraFields = []): JudgeResponse
    {
        $agent = new StructuredAnonymousAgent(
            instructions: $this->instructions,
            messages: [],
            tools: [],
            schema: function (JsonSchema $schema) use ($extraFields): array {
                $fields = [
                    'score' => $schema->number()->min(0)->max(1)->description('Score between 0.0 and 1.0.')->required(),
                    'reasoning' => $schema->string()->description('Brief explanation of the score.')->required(),
                ];

                foreach ($extraFields as $name => $description) {
                    $fields[$name] = $schema->string()->description($description)->required();
                }

                return $fields;
            },
        );

        $response = $agent->prompt(
            $prompt,
            provider: config('ai-evals.judge.provider'),
            model: config('ai-evals.judge.model'),
        );

        return new JudgeResponse($this->scorer, $response->toArray());
    }
}
