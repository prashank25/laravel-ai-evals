<?php

namespace Prashank\AiEvals;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Prashank\AiEvals\Scorers\AgentTrajectory;
use Prashank\AiEvals\Scorers\Factuality;
use Prashank\AiEvals\Scorers\LlmJudge;
use Prashank\AiEvals\Scorers\Relevance;
use Prashank\AiEvals\Scorers\Safety;
use Prashank\AiEvals\Scorers\Scorer;
use Prashank\AiEvals\Scorers\SemanticSimilarity;
use Prashank\AiEvals\Scorers\ToolCallMatch;
use Prashank\AiEvals\Tools\InvokedToolRecorder;

/**
 * Runs agents against real providers and scores what they produce.
 *
 * Use this trait on a PHPUnit test case. Evals must be able to reach the providers, so keep them out
 * of the default run (a dedicated directory or group) and make sure outbound HTTP is not blocked.
 * Add AI_EVALS_VERBOSE=1 to print every score and the judge's reasoning.
 *
 * The judge defaults to openai / gpt-5.6-luna and embeddings to openai / text-embedding-3-small.
 * Override with AI_EVALS_JUDGE_PROVIDER, AI_EVALS_JUDGE_MODEL, AI_EVALS_EMBEDDINGS_PROVIDER
 * and AI_EVALS_EMBEDDINGS_MODEL, or publish and edit config/ai-evals.php.
 */
trait EvaluatesAgents
{
    /**
     * Run the agent once and capture what it produced.
     */
    protected function prompt(Agent $agent, string $prompt, array $attachments = []): Sample
    {
        $recorder = InvokedToolRecorder::for(app());

        try {
            $response = $agent->prompt($prompt, $attachments);

            return Sample::fromResponse($prompt, $response, $agent, $recorder->invokedDuring($response->invocationId));
        } finally {
            $recorder->flush();
        }
    }

    /**
     * Run the agent several times with the same prompt to measure consistency.
     *
     * @return array<int, Sample>
     */
    protected function promptRepeatedly(Agent $agent, string $prompt, int $times, array $attachments = []): array
    {
        if ($times < 1) {
            throw new InvalidArgumentException("promptRepeatedly() needs at least one run, {$times} given.");
        }

        return array_map(fn (): Sample => $this->prompt($agent, $prompt, $attachments), range(1, $times));
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertPassesJudge(Sample|array $samples, string $criteria, float $threshold = Scorer::DEFAULT_THRESHOLD): void
    {
        $this->assertPassesScorer($samples, new LlmJudge($criteria), $threshold);
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertRelevant(Sample|array $samples, float $threshold = Scorer::DEFAULT_THRESHOLD): void
    {
        $this->assertPassesScorer($samples, new Relevance, $threshold);
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertSafe(Sample|array $samples, float $threshold = Scorer::DEFAULT_THRESHOLD): void
    {
        $this->assertPassesScorer($samples, new Safety, $threshold);
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertFactual(Sample|array $samples, string $expected, float $threshold = Scorer::DEFAULT_THRESHOLD): void
    {
        $this->assertPassesScorer($samples, new Factuality, $threshold, $expected);
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertSimilar(Sample|array $samples, string $expected, float $threshold = Scorer::DEFAULT_THRESHOLD): void
    {
        $this->assertPassesScorer($samples, new SemanticSimilarity, $threshold, $expected);
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     * @param  array<string, array<string, mixed>|Closure>  $tools  Tool name or class => ['arg' => 'value'] | fn (array $arguments): bool
     */
    protected function assertToolCalls(Sample|array $samples, array $tools, float $threshold = 1.0): void
    {
        $this->assertPassesScorer($samples, new ToolCallMatch($tools), $threshold);
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     * @param  array<int, string>  $steps  Expected tool names or classes
     */
    protected function assertTrajectory(Sample|array $samples, array $steps, bool $strictOrder = true, float $threshold = 1.0): void
    {
        $this->assertPassesScorer($samples, new AgentTrajectory($steps, $strictOrder), $threshold);
    }

    /**
     * Every sample must contain every needle. Case-sensitive.
     *
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputContains(Sample|array $samples, string ...$needles): void
    {
        foreach ($this->samples($samples) as $index => $sample) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $sample->output, 'Sample #'.($index + 1)." does not contain '{$needle}'.");
            }
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputEquals(Sample|array $samples, string $expected): void
    {
        foreach ($this->samples($samples) as $index => $sample) {
            $this->assertSame($expected, $sample->output, 'Sample #'.($index + 1).' does not match the expected output.');
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputIsJson(Sample|array $samples): void
    {
        foreach ($this->samples($samples) as $index => $sample) {
            $this->assertJson($sample->output, 'Sample #'.($index + 1).' is not valid JSON.');
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputMatches(Sample|array $samples, string $pattern): void
    {
        foreach ($this->samples($samples) as $index => $sample) {
            $this->assertMatchesRegularExpression($pattern, $sample->output, 'Sample #'.($index + 1)." does not match '{$pattern}'.");
        }
    }

    /**
     * Score every sample; each one must reach the threshold.
     *
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertPassesScorer(Sample|array $samples, Scorer $scorer, float $threshold = Scorer::DEFAULT_THRESHOLD, ?string $expected = null): void
    {
        $samples = $this->samples($samples);
        $scorerName = class_basename($scorer);

        foreach ($samples as $index => $sample) {
            $result = $scorer->score($sample, $expected);
            $passed = $result->passed($threshold);

            $this->report($scorerName, $sample, $result->score, $threshold, $passed, $result->reasoning, $index + 1, count($samples));

            $prefix = count($samples) > 1 ? 'Sample #'.($index + 1).': ' : '';

            $this->assertGreaterThanOrEqual(
                $threshold,
                $result->score,
                "{$prefix}{$scorerName} scored {$result->score} (threshold {$threshold}). {$result->reasoning}",
            );
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     * @return array<int, Sample>
     */
    private function samples(Sample|array $samples): array
    {
        $samples = array_values($samples instanceof Sample ? [$samples] : $samples);

        $this->assertNotEmpty($samples, 'No samples to evaluate.');

        return $samples;
    }

    private function report(string $scorer, Sample $sample, float $score, float $threshold, bool $passed, string $reasoning, int $index, int $total): void
    {
        if (! config('ai-evals.verbose')) {
            return;
        }

        $status = $passed ? 'PASS' : 'FAIL';
        $sampleSuffix = $total > 1 ? " (sample {$index}/{$total})" : '';
        $toolNames = implode(', ', $sample->toolNames()) ?: 'none';

        fwrite(STDERR, implode("\n", [
            '',
            str_repeat('─', 72),
            "{$status} {$scorer} {$score} / threshold {$threshold}{$sampleSuffix}",
            'Input:     '.mb_strimwidth($sample->input, 0, 160, '…'),
            'Tools:     '.$toolNames,
            'Output:    '.mb_strimwidth(str_replace("\n", ' ', $sample->output), 0, 240, '…'),
            'Reasoning: '.$reasoning,
            str_repeat('─', 72),
            '',
        ]));
    }
}
