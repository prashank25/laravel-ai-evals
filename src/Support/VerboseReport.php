<?php

namespace Prashank\AiEvals\Support;

use Laravel\Ai\Responses\Data\Usage;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Scorers\ScorerResult;

/**
 * Prints one scored sample to STDERR when AI_EVALS_VERBOSE is on.
 */
final class VerboseReport
{
    public static function write(string $scorer, Sample $sample, ScorerResult $result, float $threshold, int $index, int $total): void
    {
        if (! config('ai-evals.verbose')) {
            return;
        }

        $status = $result->passed($threshold) ? 'PASS' : 'FAIL';
        $sampleSuffix = $total > 1 ? " (sample {$index}/{$total})" : '';
        $toolNames = implode(', ', $sample->toolNames()) ?: 'none';
        $failovers = $sample->failedOver()
            ? ' (failed over from '.implode(', ', array_map(fn (Failover $failover): string => $failover->label(), $sample->failovers)).')'
            : '';

        fwrite(STDERR, implode("\n", [
            '',
            str_repeat('─', 72),
            "{$status} {$scorer} {$result->score} / threshold {$threshold}{$sampleSuffix}",
            'Input:     '.mb_strimwidth($sample->input, 0, 160, '…'),
            'Model:     '.($sample->servedBy() ?? 'unknown').$failovers,
            'Usage:     '.($sample->usage === null ? 'unknown' : self::describeUsage($sample->usage)),
            'Tools:     '.$toolNames,
            'Output:    '.mb_strimwidth(str_replace("\n", ' ', $sample->output), 0, 240, '…'),
            'Reasoning: '.$result->reasoning,
            str_repeat('─', 72),
            '',
        ]));
    }

    /**
     * "1,234 in / 56 out", followed by the cache and reasoning counts that are non-zero.
     */
    public static function describeUsage(Usage $usage): string
    {
        $extras = array_filter([
            'cache read' => $usage->cacheReadInputTokens,
            'cache write' => $usage->cacheWriteInputTokens,
            'reasoning' => $usage->reasoningTokens,
        ]);

        $description = number_format($usage->promptTokens).' in / '.number_format($usage->completionTokens).' out';

        foreach ($extras as $label => $tokens) {
            $description .= ', '.number_format($tokens)." {$label}";
        }

        return $description;
    }
}
