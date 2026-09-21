<?php

namespace Prashank\AiEvals;

use Closure;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Prashank\AiEvals\Scorers\AgentTrajectory;
use Prashank\AiEvals\Scorers\Factuality;
use Prashank\AiEvals\Scorers\LlmJudge;
use Prashank\AiEvals\Scorers\Relevance;
use Prashank\AiEvals\Scorers\Safety;
use Prashank\AiEvals\Scorers\Scorer;
use Prashank\AiEvals\Scorers\SemanticSimilarity;
use Prashank\AiEvals\Scorers\ToolCallMatch;
use Prashank\AiEvals\Support\Failover;
use Prashank\AiEvals\Support\FailoverRecorder;
use Prashank\AiEvals\Support\ProviderChain;
use Prashank\AiEvals\Support\ProviderHop;
use Prashank\AiEvals\Support\SampleList;
use Prashank\AiEvals\Support\VerboseReport;
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
 *
 * To run an eval against every provider in the agent's failover chain, tag the class with
 * #[AgentUnderTest(MyAgent::class)], the test with #[DataProvider('providerChain')] and give the test a
 * ProviderHop $hop parameter. The body stays the same: prompt() picks the hop up from the data set and
 * pins the run to that provider, so the SDK's own failover is out of the picture for that sample.
 */
trait EvaluatesAgents
{
    /**
     * One data set per provider / model in the agent under test's failover chain, named "provider/model".
     *
     * @return array<string, array{ProviderHop}>
     */
    public static function providerChain(): array
    {
        return ProviderChain::dataSetsForTestClass(static::class);
    }

    /**
     * Run the agent once and capture what it produced.
     *
     * Without an explicit provider the run comes from a providerChain data set when there is one, and
     * otherwise from the agent's own configuration, failing over as it would in production.
     */
    protected function prompt(Agent $agent, string $prompt, array $attachments = [], ProviderHop|Lab|string|null $provider = null, ?string $model = null): Sample
    {
        $hop = ProviderHop::resolve($this, $provider, $model);
        $tools = InvokedToolRecorder::for(app());
        $failovers = FailoverRecorder::for(app());

        try {
            // A single-entry map keeps the SDK on the same resolution branch as the agent's chain: #[Model]
            // is ignored and a null model means the provider's default, exactly as it would in production.
            $response = $agent->prompt($prompt, $attachments, $hop === null ? null : [$hop->provider => $hop->model]);

            return Sample::fromResponse(
                $prompt,
                $response,
                $agent,
                $tools->invokedDuring($response->invocationId),
                $failovers->during($response->invocationId),
            );
        } finally {
            $tools->flush();
            $failovers->flush();
        }
    }

    /**
     * Run the agent several times with the same prompt to measure consistency.
     *
     * @return array<int, Sample>
     */
    protected function promptRepeatedly(Agent $agent, string $prompt, int $times, array $attachments = [], ProviderHop|Lab|string|null $provider = null, ?string $model = null): array
    {
        if ($times < 1) {
            throw new InvalidArgumentException("promptRepeatedly() needs at least one run, {$times} given.");
        }

        return array_map(fn (): Sample => $this->prompt($agent, $prompt, $attachments, $provider, $model), range(1, $times));
    }

    /**
     * Every sample was served by the given provider, the given model, or both.
     *
     * A model also matches a dated snapshot of itself (gpt-5.4 matches gpt-5.4-2026-03-05), since providers
     * often report the snapshot an alias resolved to. Pass the snapshot name to require that exact one.
     *
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertServedBy(Sample|array $samples, Lab|string|null $provider = null, ?string $model = null): void
    {
        if ($provider === null && $model === null) {
            throw new InvalidArgumentException('assertServedBy() needs a provider, a model, or both.');
        }

        $provider = $provider instanceof Lab ? $provider->value : $provider;
        $expected = implode('/', array_filter([$provider, $model]));

        foreach (SampleList::from($samples) as $index => $sample) {
            $actual = $sample->servedBy() ?? 'an unknown provider';

            if ($provider !== null) {
                $this->assertSame($provider, $sample->provider, $sample->label($index)." was served by {$actual}, expected {$expected}.");
            }

            if ($model !== null) {
                $this->assertTrue($sample->servedByModel($model), $sample->label($index)." was served by {$actual}, expected {$expected} or a dated snapshot of it.");
            }
        }
    }

    /**
     * No sample needed the SDK to fail over to another provider.
     *
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertDidNotFailOver(Sample|array $samples): void
    {
        foreach (SampleList::from($samples) as $index => $sample) {
            $skipped = implode(', ', array_map(fn (Failover $failover): string => $failover->label(), $sample->failovers));

            $this->assertFalse($sample->failedOver(), $sample->label($index)." failed over from {$skipped}.");
        }
    }

    /**
     * Every sample failed over at least once, from the given provider when one is given.
     *
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertFailedOver(Sample|array $samples, Lab|string|null $from = null): void
    {
        $from = $from instanceof Lab ? $from->value : $from;

        foreach (SampleList::from($samples) as $index => $sample) {
            $this->assertTrue($sample->failedOver(), $sample->label($index).' did not fail over.');

            if ($from === null) {
                continue;
            }

            $skipped = array_map(fn (Failover $failover): string => $failover->provider, $sample->failovers);

            $this->assertContains($from, $skipped, $sample->label($index)." did not fail over from {$from}; it failed over from ".implode(', ', $skipped).'.');
        }
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
        foreach (SampleList::from($samples) as $index => $sample) {
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $sample->output, $sample->label($index)." does not contain '{$needle}'.");
            }
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputEquals(Sample|array $samples, string $expected): void
    {
        foreach (SampleList::from($samples) as $index => $sample) {
            $this->assertSame($expected, $sample->output, $sample->label($index).' does not match the expected output.');
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputIsJson(Sample|array $samples): void
    {
        foreach (SampleList::from($samples) as $index => $sample) {
            $this->assertJson($sample->output, $sample->label($index).' is not valid JSON.');
        }
    }

    /**
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertOutputMatches(Sample|array $samples, string $pattern): void
    {
        foreach (SampleList::from($samples) as $index => $sample) {
            $this->assertMatchesRegularExpression($pattern, $sample->output, $sample->label($index)." does not match '{$pattern}'.");
        }
    }

    /**
     * Score every sample; each one must reach the threshold.
     *
     * @param  Sample|array<int, Sample>  $samples
     */
    protected function assertPassesScorer(Sample|array $samples, Scorer $scorer, float $threshold = Scorer::DEFAULT_THRESHOLD, ?string $expected = null): void
    {
        $samples = SampleList::from($samples);
        $scorerName = class_basename($scorer);

        foreach ($samples as $index => $sample) {
            $result = $scorer->score($sample, $expected);

            VerboseReport::write($scorerName, $sample, $result, $threshold, $index + 1, count($samples));

            $prefix = count($samples) > 1 || $sample->servedBy() !== null ? $sample->label($index).': ' : '';

            $this->assertGreaterThanOrEqual(
                $threshold,
                $result->score,
                "{$prefix}{$scorerName} scored {$result->score} (threshold {$threshold}). {$result->reasoning}",
            );
        }
    }
}
