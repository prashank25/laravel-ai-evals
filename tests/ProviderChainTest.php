<?php

namespace Prashank\AiEvals\Tests;

use InvalidArgumentException;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Prashank\AiEvals\Support\ProviderChain;
use Prashank\AiEvals\Support\ProviderHop;

/**
 * Runs without the application, exactly as PHPUnit data providers do.
 */
class ProviderChainTest extends TestCase
{
    #[Test]
    public function resolves_a_multi_provider_chain_in_declared_order()
    {
        $hops = ProviderChain::for(ChainedAgent::class);

        $this->assertSame(
            ['anthropic/claude-sonnet-5', 'openai/gpt-5.4'],
            array_map(fn (ProviderHop $hop): string => $hop->label(), $hops),
        );
        $this->assertSame('anthropic', $hops[0]->provider);
        $this->assertSame('claude-sonnet-5', $hops[0]->model);
    }

    #[Test]
    public function resolves_a_single_provider_with_its_model_attribute()
    {
        $hops = ProviderChain::for(SingleProviderAgent::class);

        $this->assertCount(1, $hops);
        $this->assertSame('openai/gpt-5.4', $hops[0]->label());
    }

    #[Test]
    public function leaves_the_model_null_when_the_agent_relies_on_the_provider_default()
    {
        $hops = ProviderChain::for(DefaultModelAgent::class);

        $this->assertSame('anthropic', $hops[0]->label());
        $this->assertNull($hops[0]->model);
    }

    #[Test]
    public function resolves_agents_with_constructor_dependencies_without_constructing_them()
    {
        $hops = ProviderChain::for(DependentAgent::class);

        $this->assertSame(['openai/gpt-5.4'], array_map(fn (ProviderHop $hop): string => $hop->label(), $hops));
    }

    #[Test]
    public function resolves_a_provider_method_that_needs_no_state()
    {
        $hops = ProviderChain::for(ProviderMethodAgent::class);

        $this->assertSame(['openai/gpt-5.4'], array_map(fn (ProviderHop $hop): string => $hop->label(), $hops));
    }

    #[Test]
    public function explains_when_the_chain_cannot_be_resolved_without_the_application()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Could not resolve the provider chain of '.UnconfiguredAgent::class);

        ProviderChain::for(UnconfiguredAgent::class);
    }

    #[Test]
    public function rejects_classes_that_are_not_agents()
    {
        $this->expectException(InvalidArgumentException::class);

        ProviderChain::for(self::class);
    }

    #[Test]
    public function data_sets_are_keyed_by_provider_and_model()
    {
        $dataSets = ProviderChain::dataSets(ChainedAgent::class);

        $this->assertSame(['anthropic/claude-sonnet-5', 'openai/gpt-5.4'], array_keys($dataSets));
        $this->assertInstanceOf(ProviderHop::class, $dataSets['openai/gpt-5.4'][0]);
        $this->assertSame('gpt-5.4', $dataSets['openai/gpt-5.4'][0]->model);
    }
}

#[Provider([Lab::Anthropic->value => 'claude-sonnet-5', Lab::OpenAI->value => 'gpt-5.4'])]
class ChainedAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[Provider(Lab::OpenAI)]
#[Model('gpt-5.4')]
class SingleProviderAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[Provider(Lab::Anthropic)]
class DefaultModelAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}

#[Provider([Lab::OpenAI->value => 'gpt-5.4'])]
class DependentAgent implements Agent
{
    use Promptable;

    public function __construct(private ChainedAgent $dependency)
    {
        //
    }

    public function instructions(): string
    {
        return 'test';
    }
}

class ProviderMethodAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }

    public function provider(): array
    {
        return [Lab::OpenAI->value => 'gpt-5.4'];
    }
}

class UnconfiguredAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'test';
    }
}
