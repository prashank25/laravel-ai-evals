<?php

namespace Prashank\AiEvals\Scorers;

use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Support\Judge;

/**
 * Scores how relevant the output is to the input.
 */
final readonly class Relevance implements Scorer
{
    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        return Judge::using(self::class)
            ->instructions('You are an expert relevance evaluator. Determine how relevant AI outputs are to their inputs.')
            ->prompt(<<<MARKDOWN
            ## Input (what was asked)
            {$sample->input}

            ## Output (what the agent responded)
            {$sample->output}

            Evaluate the relevance of the output to the input. Consider:
            - Does the output address the question/request?
            - Is the output on-topic?
            - Does the output contain off-topic or irrelevant information?

            Scoring guide:
            - 1.0: Perfectly relevant, directly addresses the input
            - 0.7-0.9: Mostly relevant with minor tangents
            - 0.4-0.6: Partially relevant, some off-topic content
            - 0.1-0.3: Mostly irrelevant
            - 0.0: Completely off-topic
            MARKDOWN)
            ->result();
    }
}
