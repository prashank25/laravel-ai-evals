<?php

namespace Prashank\AiEvals\Tools;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebFetch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\McpTool;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Resolves what a tool is, seeing through the SDK's wrappers the same way the SDK normalises tools() entries.
 */
final class ToolIdentity
{
    /**
     * Provider tool classes keyed by the name each provider reports for them.
     *
     * @var array<string, class-string<ProviderTool>>
     */
    private const array PROVIDER_TOOL_NAMES = [
        'web_search' => WebSearch::class,
        'web_fetch' => WebFetch::class,
        'file_search' => FileSearch::class,
        'tool_search' => ToolSearch::class,
    ];

    /**
     * @var array<int, Closure(object): ?object>
     */
    private static array $unwrappers = [];

    /**
     * Register a callback that unwraps an application-specific tool wrapper to the object doing the work.
     * Return null from the callback for tools it does not recognise.
     *
     * @param  Closure(object): ?object  $unwrapper
     */
    public static function unwrapUsing(Closure $unwrapper): void
    {
        self::$unwrappers[] = $unwrapper;
    }

    public static function flushUnwrappers(): void
    {
        self::$unwrappers = [];
    }

    /**
     * Describe every entry of an agent's tools(), applying the SDK's own normalisation rules
     * (raw agents, MCP primitives, deferred tools inside tool search).
     *
     * @param  iterable<mixed>  $tools
     * @return array<int, ExposedTool>
     */
    public static function exposed(iterable $tools): array
    {
        $exposed = [];

        foreach ($tools as $tool) {
            $tool = self::normalise($tool);

            if ($tool instanceof ToolSearch) {
                $exposed[] = new ExposedTool(ToolSearch::class, name: null, fromProvider: true);

                array_push($exposed, ...self::exposed($tool->tools));
            } elseif ($tool instanceof ProviderTool) {
                $exposed[] = new ExposedTool($tool::class, name: null, fromProvider: true);
            } elseif ($tool instanceof Tool) {
                $exposed[] = self::identify($tool);
            }
        }

        return $exposed;
    }

    /**
     * Identify a tool the SDK actually invoked.
     */
    public static function identify(Tool $tool): ExposedTool
    {
        return new ExposedTool(self::unwrap($tool)::class, ToolNameResolver::resolve($tool));
    }

    /**
     * The provider tool class behind a provider-reported call name, if known.
     *
     * @return class-string<ProviderTool>|null
     */
    public static function providerToolClass(string $name): ?string
    {
        foreach (self::PROVIDER_TOOL_NAMES as $prefix => $class) {
            if ($name === $prefix || str_starts_with($name, $prefix.'_')) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Mirror of the SDK's GeneratesText::resolveTool().
     */
    private static function normalise(mixed $tool): mixed
    {
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool, $tool instanceof ProviderTool => $tool,
            McpTool::supports($tool) => new McpTool($tool),
            McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }

    private static function unwrap(object $tool): object
    {
        foreach (self::$unwrappers as $unwrapper) {
            if ($inner = $unwrapper($tool)) {
                return self::unwrap($inner);
            }
        }

        return match (true) {
            $tool instanceof AgentTool => $tool->agent(),
            $tool instanceof McpServerTool => (fn (): object => $this->tool)->call($tool),
            default => $tool,
        };
    }
}
