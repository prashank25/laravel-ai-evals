<?php

namespace Prashank\AiEvals\Support;

use InvalidArgumentException;
use Laravel\Ai\Enums\Lab;
use PHPUnit\Framework\TestCase;

/**
 * One provider / model pair of an agent's failover chain.
 *
 * The data provider yields these so prompt() can tell a chain hop apart from any other data set.
 */
final readonly class ProviderHop
{
    /**
     * @param  string|null  $model  Null when the agent leaves the model to the provider's default.
     */
    public function __construct(
        public string $provider,
        public ?string $model = null,
    ) {
        //
    }

    public static function from(Lab|string $provider, ?string $model = null): self
    {
        return new self($provider instanceof Lab ? $provider->value : $provider, $model);
    }

    /**
     * The provider / model to pin a test's run to: an explicit choice, else the test's providerChain data set, else none.
     */
    public static function resolve(TestCase $test, self|Lab|string|null $provider, ?string $model): ?self
    {
        if ($provider instanceof self) {
            return $model === null ? $provider : new self($provider->provider, $model);
        }

        if ($provider !== null) {
            return self::from($provider, $model);
        }

        if ($model !== null) {
            throw new InvalidArgumentException('A model was given without a provider.');
        }

        if (! $test->usesDataProvider()) {
            return null;
        }

        $hop = $test->providedData()[0] ?? null;

        return $hop instanceof self ? $hop : null;
    }

    /**
     * The "provider/model" label used for data set names and reports.
     */
    public function label(): string
    {
        return $this->model === null ? $this->provider : "{$this->provider}/{$this->model}";
    }
}
