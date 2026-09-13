<?php

namespace Prashank\AiEvals\Scorers;

use Laravel\Ai\Providers\Tools\ProviderTool;
use Prashank\AiEvals\Sample;

/**
 * Scores whether the agent called the expected tools, optionally in order.
 */
final readonly class AgentTrajectory implements Scorer
{
    /**
     * @param  array<int, string>  $sequence  Expected tool names or classes
     */
    public function __construct(
        private array $sequence,
        private bool $strictOrder = true,
    ) {
        //
    }

    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        foreach ($this->sequence as $tool) {
            $sample->tools->ensureEvaluable($tool);
        }

        return $this->strictOrder
            ? $this->scoreStrictOrder($sample)
            : $this->scoreSubset($sample);
    }

    private function scoreStrictOrder(Sample $sample): ScorerResult
    {
        $sequenceIndex = $this->matchedSteps($sample, unidentifiedMatchAnyClass: false);

        // If treating unidentified calls as any class would have got further, the verdict depends on them...
        if ($sequenceIndex < count($this->sequence) && $this->matchedSteps($sample, unidentifiedMatchAnyClass: true) > $sequenceIndex) {
            $sample->tools->ensureDecidable($this->firstClassStep());
        }

        $score = $sequenceIndex / count($this->sequence);
        $expectedSequence = implode(' -> ', array_map(class_basename(...), $this->sequence));
        $actualSequence = implode(' -> ', $sample->toolNames()) ?: 'none';

        return new ScorerResult(
            score: $score,
            reasoning: $score >= 1.0
                ? "Tool sequence matches: {$expectedSequence}"
                : "Expected sequence: {$expectedSequence}. Actual: {$actualSequence}. Matched {$sequenceIndex}/".count($this->sequence).'.',
            scorer: self::class,
        );
    }

    /**
     * Greedy subsequence walk: how many expected steps appear in order among the calls.
     */
    private function matchedSteps(Sample $sample, bool $unidentifiedMatchAnyClass): int
    {
        $sequenceIndex = 0;

        foreach ($sample->tools->calls as $call) {
            if ($sequenceIndex >= count($this->sequence)) {
                break;
            }

            $step = $this->sequence[$sequenceIndex];
            $wildcard = $unidentifiedMatchAnyClass && $call->class === null && ! $call->fromProvider && $this->isRegularClass($step);

            if ($wildcard || $call->matches($step)) {
                $sequenceIndex++;
            }
        }

        return $sequenceIndex;
    }

    private function firstClassStep(): string
    {
        foreach ($this->sequence as $step) {
            if ($this->isRegularClass($step)) {
                return $step;
            }
        }

        return $this->sequence[0];
    }

    private function isRegularClass(string $step): bool
    {
        return class_exists($step) && ! is_subclass_of($step, ProviderTool::class);
    }

    private function scoreSubset(Sample $sample): ScorerResult
    {
        $missing = [];

        foreach ($this->sequence as $tool) {
            if ($sample->tools->matching($tool) === []) {
                $sample->tools->ensureDecidable($tool);

                $missing[] = $tool;
            }
        }
        $actualSequence = implode(', ', $sample->toolNames()) ?: 'none';

        return new ScorerResult(
            score: (count($this->sequence) - count($missing)) / count($this->sequence),
            reasoning: $missing === []
                ? 'All expected tools were called.'
                : 'Missing tools: '.implode(', ', array_map(class_basename(...), $missing)).". Actual calls: {$actualSequence}.",
            scorer: self::class,
        );
    }
}
