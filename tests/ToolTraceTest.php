<?php

namespace Prashank\AiEvals\Tests;

use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\FileSearch;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Tools\AgentTool;
use Laravel\Ai\Tools\McpServerTool;
use Laravel\Ai\Tools\Request;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use Laravel\Mcp\Server\Tool as McpTool;
use PHPUnit\Framework\Attributes\Test;
use Prashank\AiEvals\EvaluatesAgents;
use Prashank\AiEvals\Sample;
use Prashank\AiEvals\Scorers\AgentTrajectory;
use Prashank\AiEvals\Scorers\ToolCallMatch;
use Prashank\AiEvals\Tools\IncompleteToolTraceException;
use Prashank\AiEvals\Tools\InvokedToolRecorder;
use Prashank\AiEvals\Tools\ToolIdentity;
use Prashank\AiEvals\Tools\ToolTrace;
use Stringable;

class ToolTraceTest extends TestCase
{
    use EvaluatesAgents;

    protected function setUp(): void
    {
        parent::setUp();

        ToolIdentity::flushUnwrappers();
    }

    #[Test]
    public function identifies_every_sdk_tool_type_the_agent_exposes()
    {
        $agent = new TraceTestAgent([
            new TraceTestTool,
            new AgentTool(new TraceTestAgent([])),
            new McpServerTool(new TraceTestMcpTool),
            new WebSearch,
            new ToolSearch([new TraceTestDeferredTool]),
        ]);

        $exposed = ToolIdentity::exposed($agent->tools());

        $this->assertEquals([
            [TraceTestTool::class, 'trace_test_tool', false],
            [TraceTestAgent::class, 'TraceTestAgent', false],
            [TraceTestMcpTool::class, 'trace_test_mcp_tool', false],
            [WebSearch::class, null, true],
            [ToolSearch::class, null, true],
            [TraceTestDeferredTool::class, 'deferred_tool', false],
        ], array_map(fn ($tool) => [$tool->class, $tool->name, $tool->fromProvider], $exposed));
    }

    #[Test]
    public function unwraps_application_wrappers_through_the_registered_hook()
    {
        ToolIdentity::unwrapUsing(fn (object $tool): ?object => $tool instanceof TraceTestPrefixedTool ? $tool->inner() : null);
        $agent = new TraceTestAgent([new TraceTestPrefixedTool(new TraceTestMcpTool, prefix: 'team')]);

        $exposed = ToolIdentity::exposed($agent->tools());

        $this->assertEquals(TraceTestMcpTool::class, $exposed[0]->class);
        $this->assertEquals('team__trace_test_mcp_tool', $exposed[0]->name);
    }

    #[Test]
    public function records_anthropic_provider_calls_in_order_with_regular_calls()
    {
        $agent = new TraceTestAgent([new TraceTestTool, new WebSearch]);
        $response = $this->responseWithSteps([
            $this->step(['content' => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'laravel ai']],
                ['type' => 'web_search_tool_result', 'tool_use_id' => 'srvtoolu_1', 'content' => []],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'trace_test_tool', 'input' => ['id' => 'abc']],
            ]], [new ToolCall('toolu_1', 'trace_test_tool', ['id' => 'abc'])]),
        ]);

        $trace = ToolTrace::record($agent, $response);

        $this->assertTrue($trace->providerDataAvailable);
        $this->assertEquals(['web_search', 'trace_test_tool'], $trace->names());
        $this->assertEquals(WebSearch::class, $trace->calls[0]->class);
        $this->assertEquals(['query' => 'laravel ai'], $trace->calls[0]->arguments);
        $this->assertTrue($trace->calls[0]->fromProvider);
        $this->assertEquals(TraceTestTool::class, $trace->calls[1]->class);
        $this->assertEquals(['id' => 'abc'], $trace->calls[1]->arguments);
    }

    #[Test]
    public function records_openai_provider_calls_in_order_with_regular_calls()
    {
        $agent = new TraceTestAgent([new TraceTestTool, new WebSearch, new FileSearch(['store'])]);
        $response = $this->responseWithSteps([
            $this->step(['output' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => []],
                ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'trace_test_tool', 'arguments' => '{"id":"abc"}'],
            ]], [new ToolCall('fc_1', 'trace_test_tool', ['id' => 'abc'], 'call_1')]),
            $this->step(['output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'laravel ai']],
                ['type' => 'file_search_call', 'id' => 'fs_1', 'status' => 'completed', 'queries' => ['pricing']],
                ['type' => 'message', 'id' => 'msg_1', 'content' => [['type' => 'output_text', 'text' => 'done']]],
            ]], []),
        ]);

        $trace = ToolTrace::record($agent, $response);

        $this->assertEquals(['trace_test_tool', 'web_search_call', 'file_search_call'], $trace->names());
        $this->assertEquals([TraceTestTool::class, WebSearch::class, FileSearch::class], array_map(fn ($call) => $call->class, $trace->calls));
        $this->assertEquals(['type' => 'search', 'query' => 'laravel ai'], $trace->calls[1]->arguments);
        $this->assertEquals(['queries' => ['pricing']], $trace->calls[2]->arguments);
        $this->assertEquals([0, 1, 2], array_map(fn ($call) => $call->position, $trace->calls));
    }

    #[Test]
    public function falls_back_to_sdk_tool_calls_when_no_raw_step_data_exists()
    {
        $agent = new TraceTestAgent([new TraceTestTool]);
        $response = (new AgentResponse('inv', 'done', new Usage, new Meta))
            ->withToolCallsAndResults(collect([new ToolCall('c1', 'trace_test_tool', ['id' => 'abc'])]), collect());

        $trace = ToolTrace::record($agent, $response);

        $this->assertFalse($trace->providerDataAvailable);
        $this->assertEquals(['trace_test_tool'], $trace->names());
        $this->assertEquals(TraceTestTool::class, $trace->calls[0]->class);
    }

    #[Test]
    public function scorers_match_by_class_or_name_and_see_provider_calls()
    {
        $agent = new TraceTestAgent([new TraceTestTool, new WebSearch]);
        $response = $this->responseWithSteps([
            $this->step(['content' => [
                ['type' => 'server_tool_use', 'id' => 'srvtoolu_1', 'name' => 'web_search', 'input' => ['query' => 'laravel ai']],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'trace_test_tool', 'input' => ['id' => 'abc']],
            ]], [new ToolCall('toolu_1', 'trace_test_tool', ['id' => 'abc'])]),
        ]);
        $sample = Sample::fromResponse('hi', $response, $agent);

        $this->assertEquals(1.0, (new AgentTrajectory([WebSearch::class, TraceTestTool::class]))->score($sample)->score);
        $this->assertEquals(0.5, (new AgentTrajectory([TraceTestTool::class, WebSearch::class]))->score($sample)->score);
        $this->assertEquals(1.0, (new AgentTrajectory(['trace_test_tool', 'web_search'], strictOrder: false))->score($sample)->score);
        $this->assertEquals(1.0, (new ToolCallMatch([WebSearch::class => ['query' => 'laravel ai'], 'trace_test_tool' => ['id' => 'abc']]))->score($sample)->score);
        $this->assertEquals(0.5, (new ToolCallMatch([WebSearch::class => ['query' => 'other'], TraceTestTool::class => fn (array $arguments) => $arguments['id'] === 'abc']))->score($sample)->score);
    }

    #[Test]
    public function rejects_expectations_for_tools_the_agent_does_not_expose()
    {
        $agent = new TraceTestAgent([new TraceTestTool]);
        $sample = Sample::fromResponse('hi', new AgentResponse('inv', 'done', new Usage, new Meta), $agent);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not expose');

        (new AgentTrajectory([TraceTestDeferredTool::class]))->score($sample);
    }

    #[Test]
    public function rejects_a_class_exposed_under_several_names()
    {
        ToolIdentity::unwrapUsing(fn (object $tool): ?object => $tool instanceof TraceTestPrefixedTool ? $tool->inner() : null);
        $agent = new TraceTestAgent([
            new TraceTestPrefixedTool(new TraceTestMcpTool, prefix: 'contact'),
            new TraceTestPrefixedTool(new TraceTestMcpTool, prefix: 'team'),
        ]);
        $sample = Sample::fromResponse('hi', new AgentResponse('inv', 'done', new Usage, new Meta), $agent);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('several names');

        (new AgentTrajectory([TraceTestMcpTool::class]))->score($sample);
    }

    #[Test]
    public function treats_unrecognised_provider_response_shapes_as_missing_evidence()
    {
        $agent = new TraceTestAgent([new TraceTestTool, new WebSearch]);
        $response = $this->responseWithSteps([
            $this->step(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'trace_test_tool', 'args' => []]]]]]]], [new ToolCall('c1', 'trace_test_tool', [])]),
        ]);
        $sample = Sample::fromResponse('hi', $response, $agent);

        $this->assertFalse($sample->tools->providerDataAvailable);
        $this->assertEquals(['trace_test_tool'], $sample->toolNames());
        $this->expectException(IncompleteToolTraceException::class);

        (new AgentTrajectory([WebSearch::class]))->score($sample);
    }

    #[Test]
    public function applies_evidence_checks_to_provider_tool_names_as_well_as_classes()
    {
        $agent = new TraceTestAgent([new WebSearch]);
        $sample = Sample::fromResponse('hi', new AgentResponse('inv', 'done', new Usage, new Meta), $agent);

        $this->expectException(IncompleteToolTraceException::class);

        (new AgentTrajectory(['web_search_call']))->score($sample);
    }

    #[Test]
    public function identifies_raw_agents_and_mcp_primitives_like_the_sdk_does()
    {
        $agent = new TraceTestAgent([new TraceTestChildAgent, new TraceTestMcpTool]);

        $exposed = ToolIdentity::exposed($agent->tools());

        $this->assertEquals([
            [TraceTestChildAgent::class, 'TraceTestChildAgent'],
            [TraceTestMcpTool::class, 'trace_test_mcp_tool'],
        ], array_map(fn ($tool) => [$tool->class, $tool->name], $exposed));
    }

    #[Test]
    public function identifies_called_tools_from_the_run_when_tools_is_a_generator()
    {
        $agent = new TraceTestGeneratorAgent;
        TraceTestGeneratorAgent::fake([new ToolCall('c1', 'trace_test_tool', ['id' => 'abc']), 'done']);

        $sample = $this->prompt($agent, 'hi');

        $this->assertNull($sample->tools->exposed);
        $this->assertEquals(TraceTestTool::class, $sample->tools->calls[0]->class);
        $this->assertEquals(1.0, (new AgentTrajectory([TraceTestTool::class]))->score($sample)->score);
    }

    #[Test]
    public function keeps_tools_that_ran_even_when_tools_changes_after_execution()
    {
        $agent = new TraceTestShrinkingAgent;
        TraceTestShrinkingAgent::fake([new ToolCall('c1', 'trace_test_tool', ['id' => 'abc']), 'done']);

        $sample = $this->prompt($agent, 'hi');

        $this->assertSame([], [...$agent->tools()]);
        $this->assertEquals([[TraceTestMarkingTool::class, 'trace_test_tool']], array_map(fn ($tool) => [$tool->class, $tool->name], $sample->tools->exposed));
        $this->assertEquals(1.0, (new AgentTrajectory([TraceTestMarkingTool::class]))->score($sample)->score);
        $this->assertEquals(1.0, (new ToolCallMatch([TraceTestMarkingTool::class => ['id' => 'abc']]))->score($sample)->score);
    }

    #[Test]
    public function reports_missing_evidence_when_a_requested_tool_never_executed_and_tools_cannot_be_read()
    {
        $agent = new TraceTestGeneratorAgent;
        iterator_to_array($agent->tools());
        $response = (new AgentResponse('inv', 'done', new Usage, new Meta))
            ->withToolCallsAndResults(collect([new ToolCall('c1', 'trace_test_tool', ['id' => 'abc'])]), collect());
        $sample = Sample::fromResponse('hi', $response, $agent);

        $this->assertNull($sample->tools->exposed);
        $this->assertNull($sample->tools->calls[0]->class);
        $this->assertEquals(1.0, (new AgentTrajectory(['trace_test_tool']))->score($sample)->score);
        $this->expectException(IncompleteToolTraceException::class);
        $this->expectExceptionMessage('cannot be matched by class');

        (new AgentTrajectory([TraceTestTool::class]))->score($sample);
    }

    #[Test]
    public function scores_proven_class_matches_even_when_another_call_is_unidentified()
    {
        $agent = new TraceTestGeneratorAgent;
        iterator_to_array($agent->tools());
        $response = (new AgentResponse('inv', 'done', new Usage, new Meta))
            ->withToolCallsAndResults(collect([
                new ToolCall('c1', 'deferred_tool', []),
                new ToolCall('c2', 'trace_test_tool', ['id' => 'abc']),
            ]), collect());
        $sample = Sample::fromResponse('hi', $response, $agent, [new TraceTestTool]);

        $this->assertEquals([TraceTestTool::class], array_map(fn ($call) => $call->class, $sample->tools->matching(TraceTestTool::class)));
        $this->assertNull($sample->tools->calls[0]->class);
        $this->assertEquals(1.0, (new AgentTrajectory([TraceTestTool::class]))->score($sample)->score);
        $this->assertEquals(1.0, (new AgentTrajectory([TraceTestTool::class], strictOrder: false))->score($sample)->score);
        $this->assertEquals(1.0, (new ToolCallMatch([TraceTestTool::class => ['id' => 'abc']]))->score($sample)->score);
        $this->assertEquals(0.5, (new AgentTrajectory([TraceTestTool::class, 'unknown_tool']))->score($sample)->score);
        $this->assertEquals(0.5, (new AgentTrajectory([TraceTestTool::class, 'unknown_tool'], strictOrder: false))->score($sample)->score);
        $this->expectException(IncompleteToolTraceException::class);

        (new AgentTrajectory([TraceTestTool::class, TraceTestTool::class]))->score($sample);
    }

    #[Test]
    public function reports_missing_evidence_when_an_unidentified_call_could_complete_the_sequence()
    {
        $agent = new TraceTestGeneratorAgent;
        iterator_to_array($agent->tools());
        $response = (new AgentResponse('inv', 'done', new Usage, new Meta))
            ->withToolCallsAndResults(collect([
                new ToolCall('c1', 'deferred_tool', []),
                new ToolCall('c2', 'trace_test_tool', ['id' => 'abc']),
            ]), collect());
        $sample = Sample::fromResponse('hi', $response, $agent, [new TraceTestTool]);

        $this->assertEquals(0.5, (new AgentTrajectory([TraceTestTool::class, 'never_called']))->score($sample)->score);
        $this->expectException(IncompleteToolTraceException::class);

        (new AgentTrajectory([TraceTestDeferredTool::class, TraceTestTool::class]))->score($sample);
    }

    #[Test]
    public function does_not_mistake_a_regular_tool_for_a_provider_tool_by_name()
    {
        $agent = new TraceTestAgent([new TraceTestWebSearchDocumentsTool]);
        $response = (new AgentResponse('inv', 'done', new Usage, new Meta))
            ->withToolCallsAndResults(collect([new ToolCall('c1', 'web_search_documents', ['query' => 'x'])]), collect());
        $sample = Sample::fromResponse('hi', $response, $agent);

        $this->assertEquals(1.0, (new AgentTrajectory(['web_search_documents']))->score($sample)->score);
        $this->assertEquals(1.0, (new AgentTrajectory([TraceTestWebSearchDocumentsTool::class]))->score($sample)->score);
        $this->assertEquals(1.0, (new ToolCallMatch(['web_search_documents' => ['query' => 'x']]))->score($sample)->score);
    }

    #[Test]
    public function registers_one_listener_no_matter_how_many_prompts_run()
    {
        $agent = new TraceTestAgent([new TraceTestTool]);
        TraceTestAgent::fake([
            new ToolCall('c1', 'trace_test_tool', ['id' => 'abc']), 'done',
            new ToolCall('c2', 'trace_test_tool', ['id' => 'abc']), 'done',
            new ToolCall('c3', 'trace_test_tool', ['id' => 'abc']), 'done',
        ]);
        $listenersBefore = count(Event::getListeners(InvokingTool::class));

        $samples = $this->promptRepeatedly($agent, 'hi', times: 3);

        $this->assertCount($listenersBefore + 1, Event::getListeners(InvokingTool::class));
        $this->assertEquals([['trace_test_tool'], ['trace_test_tool'], ['trace_test_tool']], array_map(fn (Sample $sample) => $sample->toolNames(), $samples));
        $this->assertSame([], InvokedToolRecorder::for(app())->invokedDuring('inv'));
    }

    #[Test]
    public function identifies_a_nested_agent_call_from_the_run()
    {
        $agent = new TraceTestAgent([new TraceTestChildAgent]);
        TraceTestAgent::fake([new ToolCall('c1', 'TraceTestChildAgent', ['task' => 'summarise']), 'done']);
        TraceTestChildAgent::fake(['child done']);

        $sample = $this->prompt($agent, 'hi');

        $this->assertEquals(['TraceTestChildAgent'], $sample->toolNames());
        $this->assertEquals(TraceTestChildAgent::class, $sample->tools->calls[0]->class);
        $this->assertEquals(1.0, (new ToolCallMatch([TraceTestChildAgent::class => ['task' => 'summarise']]))->score($sample)->score);
    }

    #[Test]
    public function matches_a_later_call_with_arguments_before_reporting_missing_evidence()
    {
        $agent = new TraceTestAgent([new WebSearch]);
        $response = $this->responseWithSteps([
            $this->step(['output' => [
                ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'],
                ['type' => 'web_search_call', 'id' => 'ws_2', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'laravel ai']],
            ]], []),
        ]);
        $sample = Sample::fromResponse('hi', $response, $agent);

        $this->assertEquals(1.0, (new ToolCallMatch([WebSearch::class => ['query' => 'laravel ai']]))->score($sample)->score);
        $this->expectException(IncompleteToolTraceException::class);

        (new ToolCallMatch([WebSearch::class => ['query' => 'other']]))->score($sample);
    }

    #[Test]
    public function refuses_to_evaluate_provider_tools_without_raw_step_data()
    {
        $agent = new TraceTestAgent([new WebSearch]);
        $sample = Sample::fromResponse('hi', new AgentResponse('inv', 'done', new Usage, new Meta), $agent);

        $this->expectException(IncompleteToolTraceException::class);

        (new AgentTrajectory([WebSearch::class]))->score($sample);
    }

    #[Test]
    public function refuses_to_assert_arguments_the_provider_did_not_report()
    {
        $agent = new TraceTestAgent([new WebSearch]);
        $response = $this->responseWithSteps([
            $this->step(['output' => [['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed']]], []),
        ]);
        $sample = Sample::fromResponse('hi', $response, $agent);

        $this->assertEquals(1.0, (new AgentTrajectory([WebSearch::class]))->score($sample)->score);
        $this->expectException(IncompleteToolTraceException::class);

        (new ToolCallMatch([WebSearch::class => ['query' => 'x']]))->score($sample);
    }

    /**
     * @param  array<int, ToolCall>  $toolCalls
     */
    private function step(array $raw, array $toolCalls): Step
    {
        return (new Step('', $toolCalls, [], FinishReason::ToolCalls, new Usage, new Meta))
            ->withRawResponse(new HttpResponse(new PsrResponse(200, ['Content-Type' => 'application/json'], json_encode($raw))));
    }

    /**
     * @param  array<int, Step>  $steps
     */
    private function responseWithSteps(array $steps): AgentResponse
    {
        $toolCalls = collect($steps)->flatMap(fn (Step $step) => $step->toolCalls);

        return (new AgentResponse('inv', 'done', new Usage, new Meta))
            ->withToolCallsAndResults($toolCalls, collect())
            ->withSteps(collect($steps));
    }
}

class TraceTestAgent implements Agent, HasTools
{
    use Promptable;

    public function __construct(private array $tools)
    {
        //
    }

    public function instructions(): string
    {
        return 'test';
    }

    public function tools(): iterable
    {
        return $this->tools;
    }
}

class TraceTestChildAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return 'child';
    }
}

class TraceTestGeneratorAgent implements Agent, HasTools
{
    use Promptable;

    private ?\Generator $tools = null;

    public function instructions(): string
    {
        return 'test';
    }

    public function tools(): iterable
    {
        return $this->tools ??= (function (): \Generator {
            yield new TraceTestTool;
        })();
    }
}

class TraceTestShrinkingAgent implements Agent, HasTools
{
    use Promptable;

    public bool $ran = false;

    public function instructions(): string
    {
        return 'test';
    }

    public function tools(): iterable
    {
        return $this->ran ? [] : [new TraceTestMarkingTool($this)];
    }
}

class TraceTestTool implements Tool
{
    public function name(): string
    {
        return 'trace_test_tool';
    }

    public function description(): Stringable|string
    {
        return 'test';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ok';
    }
}

class TraceTestDeferredTool extends TraceTestTool
{
    public function name(): string
    {
        return 'deferred_tool';
    }
}

class TraceTestMarkingTool extends TraceTestTool
{
    public function __construct(private TraceTestShrinkingAgent $agent)
    {
        //
    }

    public function handle(Request $request): Stringable|string
    {
        $this->agent->ran = true;

        return 'ok';
    }
}

class TraceTestWebSearchDocumentsTool extends TraceTestTool
{
    public function name(): string
    {
        return 'web_search_documents';
    }
}

class TraceTestMcpTool extends McpTool
{
    protected string $name = 'trace_test_mcp_tool';

    protected string $description = 'test';

    public function handle(McpRequest $request): McpResponse
    {
        return McpResponse::text('ok');
    }
}

/**
 * Stands in for an application wrapper that renames a tool (e.g. an MCP adapter that prefixes names).
 */
class TraceTestPrefixedTool implements Tool
{
    public function __construct(private McpTool $tool, private string $prefix)
    {
        //
    }

    public function name(): string
    {
        return $this->prefix.'__'.$this->tool->name();
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        return 'ok';
    }

    public function inner(): McpTool
    {
        return $this->tool;
    }
}
