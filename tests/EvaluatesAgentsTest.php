<?php

namespace Prashank\AiEvals\Tests;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;
use Prashank\AiEvals\EvaluatesAgents;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Scorers\Scorer;
use Prashank\AiEvals\Scorers\ScorerResult;
use Prashank\AiEvals\Tools\ToolTrace;

class EvaluatesAgentsTest extends TestCase
{
    use EvaluatesAgents;

    #[Test]
    public function prompt_captures_the_input_and_output_of_one_run()
    {
        AssertionTestAgent::fake(['Paris']);

        $sample = $this->prompt(new AssertionTestAgent, 'Capital of France?');

        $this->assertSame('Capital of France?', $sample->input);
        $this->assertSame('Paris', $sample->output);
        $this->assertSame([], $sample->toolNames());
        $this->assertNull($sample->structured);
    }

    #[Test]
    public function prompt_repeatedly_runs_the_agent_the_requested_number_of_times()
    {
        AssertionTestAgent::fake(['one', 'two', 'three']);

        $samples = $this->promptRepeatedly(new AssertionTestAgent, 'count', times: 3);

        $this->assertSame(['one', 'two', 'three'], array_map(fn (Sample $sample): string => $sample->output, $samples));
        AssertionTestAgent::assertPromptedTimes(3);
    }

    #[Test]
    public function prompt_repeatedly_rejects_counts_below_one_without_prompting()
    {
        AssertionTestAgent::fake(['unused']);

        try {
            $this->promptRepeatedly(new AssertionTestAgent, 'count', times: 0);
            $this->fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at least one run', $exception->getMessage());
        }

        AssertionTestAgent::assertNeverPrompted();
    }

    #[Test]
    public function output_contains_is_case_sensitive()
    {
        $sample = $this->sample('userid');

        $this->assertOutputContains($sample, 'userid');
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Sample #1 does not contain 'UserID'.");

        $this->assertOutputContains($sample, 'UserID');
    }

    #[Test]
    public function output_contains_requires_every_needle_in_every_sample()
    {
        $samples = [$this->sample('alpha beta'), $this->sample('beta gamma')];

        $this->assertOutputContains($samples, 'beta');
        $this->assertOutputContains($samples[0], 'alpha', 'beta');
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage("Sample #2 does not contain 'alpha'.");

        $this->assertOutputContains($samples, 'beta', 'alpha');
    }

    #[Test]
    public function output_matches_checks_a_regular_expression_against_every_sample()
    {
        $this->assertOutputMatches([$this->sample('Order #12'), $this->sample('Order #7')], '/^Order #\d+$/');
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #2 does not match');

        $this->assertOutputMatches([$this->sample('Order #12'), $this->sample('Order 7')], '/^Order #\d+$/');
    }

    #[Test]
    public function output_equals_requires_an_exact_match()
    {
        $this->assertOutputEquals($this->sample('yes'), 'yes');
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 does not match the expected output.');

        $this->assertOutputEquals($this->sample('Yes'), 'yes');
    }

    #[Test]
    public function output_is_json_accepts_only_valid_json()
    {
        $this->assertOutputIsJson([$this->sample('{"ok":true}'), $this->sample('[1,2]')]);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 is not valid JSON.');

        $this->assertOutputIsJson($this->sample('{ok:true}'));
    }

    #[Test]
    public function passes_scorer_fails_with_the_score_threshold_and_reasoning()
    {
        $scorer = $this->scorerReturning(new ScorerResult(0.4, 'Missed the point.', ScorersTestScorer::class));

        $this->assertPassesScorer($this->sample('x'), $scorer, threshold: 0.4);
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('ScorersTestScorer scored 0.4 (threshold 0.7). Missed the point.');

        $this->assertPassesScorer($this->sample('x'), $scorer);
    }

    #[Test]
    public function passes_scorer_names_the_failing_sample_when_given_several()
    {
        $scorer = $this->scorerReturning(
            new ScorerResult(0.9, 'fine', ScorersTestScorer::class),
            new ScorerResult(0.1, 'weak', ScorersTestScorer::class),
        );

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #2: ScorersTestScorer scored 0.1 (threshold 0.7). weak');

        $this->assertPassesScorer([$this->sample('a'), $this->sample('b')], $scorer);
    }

    #[Test]
    public function passes_scorer_rejects_an_empty_sample_list()
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('No samples to evaluate.');

        $this->assertPassesScorer([], $this->scorerReturning(new ScorerResult(1.0, 'ok', ScorersTestScorer::class)));
    }

    #[Test]
    public function passes_scorer_hands_the_expected_value_to_the_scorer()
    {
        $received = null;
        $scorer = new ScorersTestScorer(function (Sample $sample, ?string $expected) use (&$received): ScorerResult {
            $received = $expected;

            return new ScorerResult(1.0, 'ok', ScorersTestScorer::class);
        });

        $this->assertPassesScorer($this->sample('x'), $scorer, expected: 'reference');

        $this->assertSame('reference', $received);
    }

    private function sample(string $output): Sample
    {
        return new Sample('input', $output, new ToolTrace([], [], false));
    }

    /**
     * A scorer that returns the given results in order, repeating the last one.
     */
    private function scorerReturning(ScorerResult ...$results): ScorersTestScorer
    {
        return new ScorersTestScorer(function () use (&$results): ScorerResult {
            return count($results) > 1 ? array_shift($results) : $results[0];
        });
    }
}

class AssertionTestAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

class ScorersTestScorer implements Scorer
{
    public function __construct(private \Closure $score)
    {
        //
    }

    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        return ($this->score)($sample, $expected);
    }
}
