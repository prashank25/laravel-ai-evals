<?php

namespace Prashank\AiEvals\Scorers;

use InvalidArgumentException;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Support\Judge;

/**
 * Scores factual agreement between the output and a reference answer.
 */
final readonly class Factuality implements Scorer
{
    private const array CATEGORY_SCORES = [
        'equal' => 1.0,
        'approximately_equal' => 0.9,
        'superset' => 0.8,
        'subset' => 0.6,
        'disagreement' => 0.0,
    ];

    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        if ($expected === null) {
            throw new InvalidArgumentException('The Factuality scorer requires a reference answer to compare against.');
        }

        $response = Judge::using(self::class)
            ->instructions('You are an expert factuality evaluator. Compare AI outputs against reference answers for factual consistency.')
            ->prompt(<<<MARKDOWN
            ## Question/Input
            {$sample->input}

            ## AI Output (to evaluate)
            {$sample->output}

            ## Reference Answer (ground truth)
            {$expected}

            Classify the factual relationship between the AI output and reference answer into one of these categories:
            - "equal": Output contains the same facts as reference (score: 1.0)
            - "approximately_equal": Output is very close, minor wording differences (score: 0.9)
            - "superset": Output contains all reference facts plus additional correct facts (score: 0.8)
            - "subset": Output contains some but not all reference facts (score: 0.6)
            - "disagreement": Output contradicts the reference answer (score: 0.0)
            MARKDOWN, [
                'category' => 'One of: equal, approximately_equal, superset, subset, disagreement.',
            ]);

        $category = $response->string('category', 'unknown');

        return new ScorerResult(
            score: self::CATEGORY_SCORES[$category] ?? $response->score(),
            reasoning: "[{$category}] {$response->reasoning()}",
            scorer: self::class,
        );
    }
}
