<?php

namespace Prashank\AiEvals\Scorers;

use Closure;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Tools\IncompleteToolTraceException;
use Prashank\AiEvals\Tools\RecordedCall;

/**
 * Scores the fraction of expected tools that were called with matching arguments.
 */
final readonly class ToolCallMatch implements Scorer
{
    /**
     * @param  array<string, array<string, mixed>|Closure>  $tools  Tool name or class => ['arg' => 'value'] | fn (array $arguments): bool
     */
    public function __construct(
        private array $tools,
        private bool $strict = false,
    ) {
        //
    }

    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        $matched = [];
        $missing = [];

        foreach ($this->tools as $tool => $expectedArguments) {
            $sample->tools->ensureEvaluable($tool);

            if ($this->anyCallMatches($sample->tools->matching($tool), $expectedArguments)) {
                $matched[] = class_basename($tool);
            } else {
                $sample->tools->ensureDecidable($tool);

                $missing[] = class_basename($tool);
            }
        }

        $actual = implode(', ', $sample->toolNames()) ?: 'none';

        return new ScorerResult(
            score: count($matched) / count($this->tools),
            reasoning: $missing === []
                ? 'All expected tool calls matched.'
                : 'Missing tool calls: '.implode(', ', $missing).". Actual calls: {$actual}.",
            scorer: self::class,
        );
    }

    /**
     * @param  array<int, RecordedCall>  $calls
     * @param  array<string, mixed>|Closure  $expectedArguments
     */
    private function anyCallMatches(array $calls, array|Closure $expectedArguments): bool
    {
        $withoutArguments = null;

        foreach ($calls as $call) {
            if ($call->arguments === null) {
                $withoutArguments ??= $call;

                continue;
            }

            $matches = match (true) {
                $expectedArguments instanceof Closure => (bool) $expectedArguments($call->arguments),
                $this->strict => $call->arguments === $expectedArguments,
                default => $this->containsSubset($call->arguments, $expectedArguments),
            };

            if ($matches) {
                return true;
            }
        }

        if ($withoutArguments !== null) {
            throw new IncompleteToolTraceException(
                "The provider did not report arguments for the [{$withoutArguments->name}] call, so its arguments cannot be asserted. Assert the call by trajectory instead."
            );
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $haystack
     * @param  array<string, mixed>  $needle
     */
    private function containsSubset(array $haystack, array $needle): bool
    {
        foreach ($needle as $key => $value) {
            if (! array_key_exists($key, $haystack)) {
                return false;
            }

            if (is_array($value) && is_array($haystack[$key])) {
                if (! $this->containsSubset($haystack[$key], $value)) {
                    return false;
                }
            } elseif ($haystack[$key] !== $value) {
                return false;
            }
        }

        return true;
    }
}
