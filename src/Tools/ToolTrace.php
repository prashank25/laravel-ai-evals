<?php

namespace Prashank\AiEvals\Tools;

use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Throwable;

/**
 * Everything known about tools for one run: what the agent exposed and what the model called.
 *
 * Identity of the tools that ran comes from the SDK's InvokingTool events, so it reflects the actual
 * run rather than a second read of tools(). Regular calls are the SDK's ToolCall objects. Provider tools
 * (web search, file search, tool search) execute on the provider's side and only appear in each step's
 * raw response, so they are read from there when the shape is one the parser understands.
 */
final readonly class ToolTrace
{
    /**
     * @param  array<int, ExposedTool>|null  $exposed  Null when the agent's tool list could not be read after the run.
     * @param  array<int, RecordedCall>  $calls
     * @param  bool  $providerDataAvailable  Whether every step carried a raw response the parser understands.
     */
    public function __construct(
        public ?array $exposed,
        public array $calls,
        public bool $providerDataAvailable,
    ) {
        //
    }

    /**
     * @param  array<int, Tool>  $invokedTools  Tool instances the SDK reported invoking during this run.
     */
    public static function record(?Agent $agent, TextResponse $response, array $invokedTools = []): self
    {
        $invoked = array_map(ToolIdentity::identify(...), $invokedTools);
        $exposed = self::exposedBy($agent, $invoked);
        $classesByName = [];

        // What actually ran is listed last so it wins over what tools() reports after the fact...
        foreach ([...($exposed ?? []), ...$invoked] as $tool) {
            if ($tool->name !== null) {
                $classesByName[$tool->name] = $tool->class;
            }
        }

        $steps = $response->steps->all();
        $parsedSteps = array_map(fn (Step $step): ?array => ProviderResponseParser::parse($step->raw), $steps);
        $rawAvailable = $steps !== [] && ! in_array(null, $parsedSteps, true);

        $calls = $rawAvailable
            ? self::callsFromRawSteps($steps, $parsedSteps, $classesByName)
            : self::callsFromSdk($response->toolCalls->all(), $classesByName);

        return new self($exposed, $calls, $rawAvailable);
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return array_map(fn (RecordedCall $call): string => $call->name, $this->calls);
    }

    /**
     * @return array<int, RecordedCall>
     */
    public function matching(string $nameOrClass): array
    {
        return array_values(array_filter($this->calls, fn (RecordedCall $call): bool => $call->matches($nameOrClass)));
    }

    /**
     * Fail fast when an expectation cannot be evaluated: the tool was never exposed, the class is
     * exposed under several names, or the provider did not return data that shows provider tool calls.
     */
    public function ensureEvaluable(string $nameOrClass): void
    {
        $isClass = class_exists($nameOrClass);
        $isProviderTool = $this->isProviderTool($nameOrClass);

        if ($this->exposed !== null) {
            $matches = array_values(array_filter($this->exposed, fn (ExposedTool $tool): bool => match (true) {
                $isClass => $tool->class === $nameOrClass,
                $isProviderTool => $tool->class === ToolIdentity::providerToolClass($nameOrClass),
                default => $tool->name === $nameOrClass,
            }));

            if ($matches === []) {
                throw new InvalidArgumentException("The agent does not expose [{$nameOrClass}] as a tool.");
            }

            if (count($matches) > 1 && $isClass) {
                $names = implode(', ', array_map(fn (ExposedTool $tool): string => (string) $tool->name, $matches));

                throw new InvalidArgumentException("[{$nameOrClass}] is exposed under several names ({$names}). Use the tool name instead.");
            }
        }

        if ($isProviderTool && ! $this->providerDataAvailable) {
            throw new IncompleteToolTraceException(
                "[{$nameOrClass}] is a provider tool and the response carried no raw step data this trace can read. Provider tool assertions need a real, non-streamed response from a supported provider."
            );
        }
    }

    /**
     * Call once a class expectation has found no proven match: if the run also holds calls this trace
     * could not identify, one of them may be that class, so the verdict cannot be a clean miss.
     */
    public function ensureDecidable(string $nameOrClass): void
    {
        if (! class_exists($nameOrClass) || $this->isProviderTool($nameOrClass)) {
            return;
        }

        $unidentified = $this->unidentifiedCalls();

        if ($unidentified === []) {
            return;
        }

        $names = implode(', ', array_unique(array_map(fn (RecordedCall $call): string => $call->name, $unidentified)));

        throw new IncompleteToolTraceException(
            "[{$nameOrClass}] cannot be matched by class: the model requested [{$names}] but the SDK never executed it, so this trace cannot tell which class it maps to. Assert by tool name instead."
        );
    }

    /**
     * A provider tool class, or a provider-reported name no regular tool in this run answers to.
     * A regular tool may legitimately be named web_search_documents; what the agent exposed or called wins.
     */
    private function isProviderTool(string $nameOrClass): bool
    {
        if (class_exists($nameOrClass)) {
            return is_subclass_of($nameOrClass, ProviderTool::class);
        }

        if (ToolIdentity::providerToolClass($nameOrClass) === null) {
            return false;
        }

        foreach ($this->exposed ?? [] as $tool) {
            if ($tool->name === $nameOrClass) {
                return false;
            }
        }

        foreach ($this->calls as $call) {
            if (! $call->fromProvider && $call->name === $nameOrClass) {
                return false;
            }
        }

        return true;
    }

    /**
     * Regular calls whose class is unknown: the model requested them but no InvokingTool event
     * identified them (for example a run cut short by MaxSteps) and tools() could not fill the gap.
     *
     * @return array<int, RecordedCall>
     */
    public function unidentifiedCalls(): array
    {
        return array_values(array_filter($this->calls, fn (RecordedCall $call): bool => ! $call->fromProvider && $call->class === null));
    }

    /**
     * Read the agent's tool list for exposure checks and add whatever ran, since executing a tool may
     * change what tools() returns afterwards. A generator already consumed by the SDK cannot be read
     * again, in which case exposure is unknown and those checks are skipped.
     *
     * @param  array<int, ExposedTool>  $invoked
     * @return array<int, ExposedTool>|null
     */
    private static function exposedBy(?Agent $agent, array $invoked): ?array
    {
        try {
            $exposed = $agent instanceof HasTools ? ToolIdentity::exposed($agent->tools()) : [];
        } catch (Throwable) {
            return null;
        }

        foreach ($invoked as $tool) {
            $alreadyListed = array_filter(
                $exposed,
                fn (ExposedTool $listed): bool => $listed->class === $tool->class && $listed->name === $tool->name,
            );

            if ($alreadyListed === []) {
                $exposed[] = $tool;
            }
        }

        return $exposed;
    }

    /**
     * Walk each step's parsed raw items in order so provider calls keep their place among regular calls.
     *
     * @param  array<int, Step>  $steps
     * @param  array<int, array<int, array{provider: bool, id: string|null, name: string, arguments: array<string, mixed>|null}>>  $parsedSteps
     * @param  array<string, class-string>  $classesByName
     * @return array<int, RecordedCall>
     */
    private static function callsFromRawSteps(array $steps, array $parsedSteps, array $classesByName): array
    {
        $calls = [];

        foreach ($steps as $index => $step) {
            $sdkCallsById = [];

            foreach ($step->toolCalls as $toolCall) {
                $sdkCallsById[$toolCall->id] = $toolCall;
            }

            $matchedIds = [];

            foreach ($parsedSteps[$index] as $item) {
                if ($item['provider']) {
                    $calls[] = new RecordedCall(
                        name: $item['name'],
                        class: ToolIdentity::providerToolClass($item['name']),
                        arguments: $item['arguments'],
                        position: count($calls),
                        fromProvider: true,
                    );

                    continue;
                }

                $toolCall = $sdkCallsById[$item['id']] ?? null;

                if ($toolCall === null) {
                    continue;
                }

                $matchedIds[] = $toolCall->id;
                $calls[] = self::fromSdkCall($toolCall, $classesByName, count($calls));
            }

            foreach ($step->toolCalls as $toolCall) {
                if (! in_array($toolCall->id, $matchedIds, true)) {
                    $calls[] = self::fromSdkCall($toolCall, $classesByName, count($calls));
                }
            }
        }

        return $calls;
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<string, class-string>  $classesByName
     * @return array<int, RecordedCall>
     */
    private static function callsFromSdk(array $toolCalls, array $classesByName): array
    {
        return array_map(
            fn (ToolCall $toolCall, int $position): RecordedCall => self::fromSdkCall($toolCall, $classesByName, $position),
            $toolCalls,
            array_keys($toolCalls),
        );
    }

    /**
     * @param  array<string, class-string>  $classesByName
     */
    private static function fromSdkCall(ToolCall $toolCall, array $classesByName, int $position): RecordedCall
    {
        return new RecordedCall(
            name: $toolCall->name,
            class: $classesByName[$toolCall->name] ?? null,
            arguments: $toolCall->arguments,
            position: $position,
        );
    }
}
