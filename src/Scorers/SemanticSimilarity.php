<?php

namespace Prashank\AiEvals\Scorers;

use InvalidArgumentException;
use Laravel\Ai\Embeddings;
use Prashank\AiEvals\Sample;

/**
 * Scores cosine similarity between embeddings of the output and a reference text.
 */
final readonly class SemanticSimilarity implements Scorer
{
    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        if ($expected === null) {
            throw new InvalidArgumentException('The SemanticSimilarity scorer requires an expected output to compare against.');
        }

        [$outputVector, $expectedVector] = Embeddings::for([$sample->output, $expected])
            ->generate(config('ai-evals.embeddings.provider'), config('ai-evals.embeddings.model'))
            ->embeddings;

        $score = max(0.0, min(1.0, $this->cosineSimilarity(array_values($outputVector), array_values($expectedVector))));

        return new ScorerResult(
            score: $score,
            reasoning: 'Cosine similarity: '.number_format($score, 4),
            scorer: self::class,
        );
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $index => $value) {
            $dotProduct += $value * $b[$index];
            $normA += $value * $value;
            $normB += $b[$index] * $b[$index];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator === 0.0 ? 0.0 : $dotProduct / $denominator;
    }
}
