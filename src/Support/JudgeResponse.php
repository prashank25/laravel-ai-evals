<?php

namespace Prashank\AiEvals\Support;

use Prashank\AiEvals\Scorers\ScorerResult;

final readonly class JudgeResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private string $scorer,
        private array $data,
    ) {
        //
    }

    public function score(): float
    {
        return max(0.0, min(1.0, (float) ($this->data['score'] ?? 0.0)));
    }

    public function reasoning(): string
    {
        return (string) ($this->data['reasoning'] ?? 'No reasoning provided.');
    }

    public function string(string $key, string $default = ''): string
    {
        return is_string($this->data[$key] ?? null) ? $this->data[$key] : $default;
    }

    public function result(): ScorerResult
    {
        return new ScorerResult($this->score(), $this->reasoning(), $this->scorer);
    }
}
