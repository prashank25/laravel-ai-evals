<?php

namespace Prashank\AiEvals\Tests;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Promptable;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Prashank\AiEvals\Attributes\AgentUnderTest;
use Prashank\AiEvals\EvaluatesAgents;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Scorers\Scorer;
use Prashank\AiEvals\Scorers\ScorerResult;
use Prashank\AiEvals\Support\ProviderHop;
use Prashank\AiEvals\Tools\ToolTrace;

#[AgentUnderTest(FailoverTestAgent::class)]
class ProviderChainEvalTest extends TestCase
{
    use EvaluatesAgents;

    #[Test]
    #[DataProvider('providerChain')]
    public function prompt_pins_the_run_to_the_hop_of_the_current_data_set(ProviderHop $hop)
    {
        FailoverTestAgent::fake(fn (string $prompt, Collection $attachments, TextProvider $provider, string $model): string => "{$provider->name()}/{$model}");

        $sample = $this->prompt(new FailoverTestAgent, 'Which model are you?');

        $this->assertSame($hop->label(), $sample->output);
        $this->assertSame($hop->label(), $sample->servedBy());
        $this->assertServedBy($sample, $sample->provider, $sample->model);
        $this->assertDidNotFailOver($sample);
    }

    #[Test]
    public function the_data_provider_lists_every_hop_of_the_agent_under_test()
    {
        $this->assertSame(['anthropic/claude-sonnet-5', 'openai/gpt-5.4'], array_keys(self::providerChain()));
    }

    #[Test]
    public function an_explicit_provider_wins_over_the_agent_configuration()
    {
        FailoverTestAgent::fake(fn (string $prompt, Collection $attachments, TextProvider $provider, string $model): string => "{$provider->name()}/{$model}");

        $sample = $this->prompt(new FailoverTestAgent, 'Which model are you?', provider: Lab::OpenAI, model: 'gpt-5.4');

        $this->assertSame('openai/gpt-5.4', $sample->output);
        $this->assertServedBy($sample, Lab::OpenAI, 'gpt-5.4');
        $this->assertServedBy($sample, 'openai');
    }

    #[Test]
    public function a_hop_can_be_passed_as_the_provider()
    {
        FailoverTestAgent::fake(fn (string $prompt, Collection $attachments, TextProvider $provider, string $model): string => "{$provider->name()}/{$model}");

        $sample = $this->prompt(new FailoverTestAgent, 'Which model are you?', provider: new ProviderHop('openai', 'gpt-5.4'));

        $this->assertSame('openai/gpt-5.4', $sample->output);
    }

    #[Test]
    public function a_pinned_hop_ignores_the_model_attribute_like_the_chain_does()
    {
        ModelAttributeAgent::fake(fn (string $prompt, Collection $attachments, TextProvider $provider, string $model): string => "{$provider->name()}/{$model}");

        $chain = $this->prompt(new ModelAttributeAgent, 'Which model are you?');
        $pinned = $this->prompt(new ModelAttributeAgent, 'Which model are you?', provider: new ProviderHop('anthropic'));

        $this->assertSame($chain->output, $pinned->output);
        $this->assertStringNotContainsString('agent-specific-model', $pinned->output);
    }

    #[Test]
    public function a_model_without_a_provider_is_rejected()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->prompt(new FailoverTestAgent, 'Which model are you?', model: 'gpt-5.4');
    }

    #[Test]
    public function without_a_data_set_the_agent_fails_over_through_its_own_chain()
    {
        FailoverTestAgent::fake(function (string $prompt, Collection $attachments, TextProvider $provider, string $model): string {
            if ($provider->name() === 'anthropic') {
                throw RateLimitedException::forProvider('anthropic');
            }

            return "{$provider->name()}/{$model}";
        });

        $sample = $this->prompt(new FailoverTestAgent, 'Which model are you?');

        $this->assertSame('openai/gpt-5.4', $sample->output);
        $this->assertServedBy($sample, Lab::OpenAI, 'gpt-5.4');
        $this->assertFailedOver($sample);
        $this->assertFailedOver($sample, from: Lab::Anthropic);
        $this->assertCount(1, $sample->failovers);
        $this->assertSame('anthropic/claude-sonnet-5', $sample->failovers[0]->label());
        $this->assertInstanceOf(RateLimitedException::class, $sample->failovers[0]->exception);
    }

    #[Test]
    public function failovers_are_scoped_to_their_own_run()
    {
        $calls = 0;
        FailoverTestAgent::fake(function (string $prompt, Collection $attachments, TextProvider $provider, string $model) use (&$calls): string {
            if ($provider->name() === 'anthropic' && ++$calls === 1) {
                throw RateLimitedException::forProvider('anthropic');
            }

            return "{$provider->name()}/{$model}";
        });

        $first = $this->prompt(new FailoverTestAgent, 'first');
        $second = $this->prompt(new FailoverTestAgent, 'second');

        $this->assertFailedOver($first, from: Lab::Anthropic);
        $this->assertDidNotFailOver($second);
        $this->assertServedBy($second, Lab::Anthropic, 'claude-sonnet-5');
    }

    #[Test]
    public function served_by_failures_name_the_provider_that_answered()
    {
        FailoverTestAgent::fake(['answer']);

        $sample = $this->prompt(new FailoverTestAgent, 'prompt', provider: Lab::OpenAI, model: 'gpt-5.4');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 [openai/gpt-5.4] was served by openai/gpt-5.4, expected anthropic/claude-sonnet-5.');

        $this->assertServedBy($sample, Lab::Anthropic, 'claude-sonnet-5');
    }

    #[Test]
    public function served_by_accepts_a_model_without_a_provider()
    {
        FailoverTestAgent::fake(['answer']);

        $sample = $this->prompt(new FailoverTestAgent, 'prompt', provider: Lab::OpenAI, model: 'gpt-5.4');

        $this->assertServedBy($sample, model: 'gpt-5.4');
    }

    #[Test]
    public function served_by_needs_a_provider_or_a_model()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->assertServedBy($this->sampleServedBy('openai', 'gpt-5.4'));
    }

    #[Test]
    public function served_by_matches_dated_snapshots_of_the_model()
    {
        $this->assertServedBy($this->sampleServedBy('openai', 'gpt-5.4-2026-03-05'), Lab::OpenAI, 'gpt-5.4');
        $this->assertServedBy($this->sampleServedBy('anthropic', 'claude-sonnet-4-20250514'), model: 'claude-sonnet-4');
        $this->assertServedBy($this->sampleServedBy('openai', 'gpt-5.4-2026-03-05'), model: 'gpt-5.4-2026-03-05');
    }

    #[Test]
    public function served_by_rejects_a_sibling_model_that_shares_the_prefix()
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 [openai/gpt-5.4-mini] was served by openai/gpt-5.4-mini, expected openai/gpt-5.4 or a dated snapshot of it.');

        $this->assertServedBy($this->sampleServedBy('openai', 'gpt-5.4-mini'), Lab::OpenAI, 'gpt-5.4');
    }

    #[Test]
    public function served_by_rejects_a_different_snapshot_when_one_is_named()
    {
        $this->expectException(AssertionFailedError::class);

        $this->assertServedBy($this->sampleServedBy('openai', 'gpt-5.4-2026-03-05'), model: 'gpt-5.4-2025-11-20');
    }

    #[Test]
    public function did_not_fail_over_failures_list_the_skipped_hops()
    {
        FailoverTestAgent::fake(function (string $prompt, Collection $attachments, TextProvider $provider, string $model): string {
            if ($provider->name() === 'anthropic') {
                throw RateLimitedException::forProvider('anthropic');
            }

            return 'answer';
        });

        $sample = $this->prompt(new FailoverTestAgent, 'prompt');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 [openai/gpt-5.4] failed over from anthropic/claude-sonnet-5.');

        $this->assertDidNotFailOver($sample);
    }

    #[Test]
    public function failed_over_failures_explain_which_provider_was_expected()
    {
        FailoverTestAgent::fake(['answer']);

        $sample = $this->prompt(new FailoverTestAgent, 'prompt');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 [anthropic/claude-sonnet-5] did not fail over.');

        $this->assertFailedOver($sample, from: Lab::Anthropic);
    }

    #[Test]
    public function scorer_failures_are_labelled_with_the_serving_model()
    {
        FailoverTestAgent::fake(['answer']);

        $sample = $this->prompt(new FailoverTestAgent, 'prompt');

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Sample #1 [anthropic/claude-sonnet-5]: ');

        $this->assertPassesScorer($sample, new FailingScorer, 1.0);
    }

    #[Test]
    public function the_data_provider_requires_the_agent_under_test_attribute()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Add #[AgentUnderTest(MyAgent::class)] to '.WithoutAgentUnderTest::class);

        WithoutAgentUnderTest::providerChain();
    }

    private function sampleServedBy(string $provider, string $model): Sample
    {
        return new Sample(input: 'prompt', output: 'answer', tools: new ToolTrace(exposed: [], calls: [], providerDataAvailable: true), provider: $provider, model: $model);
    }

    #[Test]
    #[TestWith(['openai', 'gpt-5.4'])]
    public function a_data_set_that_is_not_a_hop_leaves_the_agent_configuration_alone(string $provider, string $model)
    {
        FailoverTestAgent::fake(fn (string $prompt, Collection $attachments, TextProvider $textProvider, string $textModel): string => "{$textProvider->name()}/{$textModel}");

        $sample = $this->prompt(new FailoverTestAgent, 'Which model are you?');

        $this->assertSame('anthropic/claude-sonnet-5', $sample->output);
    }
}

class FailingScorer implements Scorer
{
    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        return new ScorerResult(0.0, 'nope', self::class);
    }
}

#[Provider([Lab::Anthropic->value => 'claude-sonnet-5', Lab::OpenAI->value => 'gpt-5.4'])]
class FailoverTestAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[Provider([Lab::Anthropic->value => null, Lab::OpenAI->value => 'gpt-5.4'])]
#[Model('agent-specific-model')]
class ModelAttributeAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

class WithoutAgentUnderTest extends TestCase
{
    use EvaluatesAgents;
}
