<?php

namespace Prashank\AiEvals\Scorers;

use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Support\Judge;

/**
 * Scores the output against free-form natural language criteria.
 */
final readonly class LlmJudge implements Scorer
{
    public function __construct(private string $criteria)
    {
        //
    }

    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        return Judge::using(self::class)
            ->instructions('You are an expert AI evaluation judge. You evaluate AI agent responses against specific criteria.')
            ->prompt($this->buildPrompt($sample, $expected))
            ->result();
    }

    private function buildPrompt(Sample $sample, ?string $expected): string
    {
        $prompt = <<<MARKDOWN
        ## Input (what was asked)
        {$sample->input}

        ## Output (what the agent responded)
        {$sample->output}
        MARKDOWN;

        if ($expected !== null) {
            $prompt .= <<<MARKDOWN


            ## Expected Output (reference answer)
            {$expected}
            MARKDOWN;
        }

        return $prompt.<<<MARKDOWN


        ## Evaluation Criteria
        {$this->criteria}

        Evaluate the output against the criteria above.

        Scoring guide:
        - 1.0: Fully meets all criteria
        - 0.7-0.9: Mostly meets criteria with minor gaps
        - 0.4-0.6: Partially meets criteria
        - 0.1-0.3: Mostly fails to meet criteria
        - 0.0: Completely fails to meet criteria
        MARKDOWN;
    }
}
