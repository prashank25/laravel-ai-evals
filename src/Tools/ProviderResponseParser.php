<?php

namespace Prashank\AiEvals\Tools;

use Illuminate\Http\Client\Response as HttpResponse;

/**
 * Reads tool-related items out of a provider's raw step response, in the order they were produced.
 *
 * Only response shapes this parser understands count as evidence. Anything else returns null so the
 * trace can say "cannot evaluate" instead of silently reporting that no provider tool was called.
 */
final class ProviderResponseParser
{
    /**
     * @return array<int, array{provider: bool, id: string|null, name: string, arguments: array<string, mixed>|null}>|null
     */
    public static function parse(?HttpResponse $raw): ?array
    {
        $data = $raw?->json();

        if (! is_array($data)) {
            return null;
        }

        return match (true) {
            is_array($data['content'] ?? null) => self::anthropic($data['content']),
            is_array($data['output'] ?? null) => self::openAi($data['output']),
            default => null,
        };
    }

    /**
     * Anthropic Messages API: tool_use and server_tool_use content blocks.
     *
     * @param  array<int, array<string, mixed>>  $content
     * @return array<int, array{provider: bool, id: string|null, name: string, arguments: array<string, mixed>|null}>
     */
    private static function anthropic(array $content): array
    {
        $items = [];

        foreach ($content as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'tool_use') {
                $items[] = ['provider' => false, 'id' => $block['id'] ?? null, 'name' => $block['name'] ?? '', 'arguments' => null];
            } elseif ($type === 'server_tool_use') {
                $items[] = ['provider' => true, 'id' => null, 'name' => $block['name'] ?? '', 'arguments' => (array) ($block['input'] ?? [])];
            }
        }

        return $items;
    }

    /**
     * OpenAI Responses API: function_call plus provider items such as web_search_call and file_search_call.
     *
     * @param  array<int, array<string, mixed>>  $output
     * @return array<int, array{provider: bool, id: string|null, name: string, arguments: array<string, mixed>|null}>
     */
    private static function openAi(array $output): array
    {
        $items = [];

        foreach ($output as $item) {
            $type = $item['type'] ?? '';

            if ($type === 'function_call') {
                $items[] = ['provider' => false, 'id' => $item['id'] ?? null, 'name' => $item['name'] ?? '', 'arguments' => null];
            } elseif (str_ends_with($type, '_call')) {
                $items[] = ['provider' => true, 'id' => null, 'name' => $type, 'arguments' => self::openAiArguments($item)];
            }
        }

        return $items;
    }

    /**
     * OpenAI has no uniform arguments field for provider tools; pick up what each item type offers.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function openAiArguments(array $item): ?array
    {
        return match (true) {
            is_array($item['action'] ?? null) => $item['action'],
            is_array($item['queries'] ?? null) => ['queries' => $item['queries']],
            is_string($item['arguments'] ?? null) => json_decode($item['arguments'], true) ?? [],
            default => null,
        };
    }
}
