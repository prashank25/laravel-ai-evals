<?php

namespace Prashank\AiEvals\Support;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use LogicException;
use Prashank\AiEvals\Attributes\AgentUnderTest;
use ReflectionClass;
use Throwable;

/**
 * Resolves the provider / model chain an agent fails over through.
 *
 * The SDK owns the resolution rules (the Provider and Model attributes, provider() and model() methods,
 * and the ai.default fallback), so this reuses Promptable::getProvidersAndModels() instead of copying
 * them. It runs from PHPUnit data providers, before the application boots, so the agent is created
 * without its constructor and anything that needs the container (config, injected services) fails
 * with an explanation rather than a stray error.
 */
final class ProviderChain
{
    private const string RESOLVER = 'getProvidersAndModels';

    /**
     * @param  class-string<Agent>  $agentClass
     * @return array<int, ProviderHop>
     */
    public static function for(string $agentClass): array
    {
        if (! is_subclass_of($agentClass, Agent::class)) {
            throw new InvalidArgumentException("{$agentClass} is not a laravel/ai agent.");
        }

        $reflection = new ReflectionClass($agentClass);

        if (! $reflection->hasMethod(self::RESOLVER)) {
            throw new LogicException(
                "{$agentClass} has no ".self::RESOLVER.'() method; the installed laravel/ai version is not supported by the provider chain resolver.',
            );
        }

        try {
            $chain = $reflection->getMethod(self::RESOLVER)->invoke($reflection->newInstanceWithoutConstructor(), null, null);
        } catch (Throwable $exception) {
            throw new LogicException(
                "Could not resolve the provider chain of {$agentClass} outside a booted application. "
                .'Declare it with the #[Provider] attribute or make provider() and model() independent of the constructor and the container.',
                previous: $exception,
            );
        }

        $hops = [];

        foreach ($chain as $provider => $model) {
            $hops[] = new ProviderHop($provider, $model);
        }

        return $hops;
    }

    /**
     * The data sets for a test class, whose agent is named by #[AgentUnderTest] on it or one of its parents.
     *
     * @param  class-string  $testClass
     * @return array<string, array{ProviderHop}>
     */
    public static function dataSetsForTestClass(string $testClass): array
    {
        for ($class = new ReflectionClass($testClass); $class !== false; $class = $class->getParentClass()) {
            $attributes = $class->getAttributes(AgentUnderTest::class);

            if ($attributes !== []) {
                return self::dataSets($attributes[0]->newInstance()->agent);
            }
        }

        throw new LogicException("Add #[AgentUnderTest(MyAgent::class)] to {$testClass} to use the providerChain data provider.");
    }

    /**
     * The chain as PHPUnit data sets, keyed by "provider/model" so each hop is named in the output and filterable.
     *
     * @param  class-string<Agent>  $agentClass
     * @return array<string, array{ProviderHop}>
     */
    public static function dataSets(string $agentClass): array
    {
        $dataSets = [];

        foreach (self::for($agentClass) as $hop) {
            $dataSets[$hop->label()] = [$hop];
        }

        return $dataSets;
    }
}
