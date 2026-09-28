# Laravel AI Evals

PHPUnit evals for [laravel/ai](https://github.com/laravel/ai) agents: LLM-as-judge, factuality, relevance,
semantic similarity, tool call and trajectory assertions. A port of the Pest evals plugin for PHPUnit users.

## Requirements

- PHP 8.3+, Laravel 12+, PHPUnit 11, 12 or 13

| laravel/ai | laravel-ai-evals |
| --- | --- |
| 1.x | 1.x |
| 0.11 | 0.x |

## Install

```bash
composer require --dev prashank/laravel-ai-evals
```

The service provider is auto-discovered. To customise defaults, publish the config:

```bash
php artisan vendor:publish --tag=ai-evals-config
```

## Configuration

`config/ai-evals.php` reads these environment variables:

| Variable | Default | Used by |
| --- | --- | --- |
| `AI_EVALS_JUDGE_PROVIDER` | `openai` | judge, relevance, safety, factuality |
| `AI_EVALS_JUDGE_MODEL` | `gpt-5.6-luna` | judge, relevance, safety, factuality |
| `AI_EVALS_EMBEDDINGS_PROVIDER` | `openai` | semantic similarity |
| `AI_EVALS_EMBEDDINGS_MODEL` | `text-embedding-3-small` | semantic similarity |
| `AI_EVALS_VERBOSE` | `false` | prints every score with the serving model, token usage and the judge's reasoning |

Any provider configured in laravel/ai can be used.

## Keeping evals out of the normal test run

Evals call real providers, so they should not run with `php artisan test`. Put them in a directory that is
**not** listed in any `phpunit.xml` test suite (for example `tests/Evals`) and run them by path:

```bash
php artisan test tests/Evals
php artisan test tests/Evals --filter=remembers_earlier_turns
AI_EVALS_VERBOSE=1 php artisan test tests/Evals
```

If your base test case blocks outbound HTTP, allow it for eval classes (a PHPUnit group works well).

## Writing an eval

Use the `EvaluatesAgents` trait on any PHPUnit test case, prompt an agent to get a `Sample`, then assert on it.

```php
use PHPUnit\Framework\Attributes\Test;
use Prashank\AiEvals\EvaluatesAgents;
use Tests\TestCase;

class SupportAgentEvalTest extends TestCase
{
    use EvaluatesAgents;

    #[Test]
    public function answers_refund_questions_from_the_policy()
    {
        $sample = $this->prompt(new SupportAgent, 'Can I get a refund after 30 days?');

        $this->assertTrajectory($sample, [LookupRefundPolicyTool::class]);
        $this->assertPassesJudge($sample, 'The agent says refunds are only available within 30 days and does not promise exceptions.');
    }
}
```

### Prompting

```php
$sample  = $this->prompt($agent, 'prompt', attachments: []);
$samples = $this->promptRepeatedly($agent, 'prompt', times: 3);
```

Every assertion accepts a single `Sample` or an array of samples. With an array, every sample must pass.

`Sample` exposes `input`, `output`, `structured` (for structured agents) and `tools`, a `ToolTrace` of every
tool the model called in order, including provider-side tools such as web search. It also records which
`provider` and `model` served the run, any `failovers` the SDK performed to get there, and the token `usage`
summed across every step.

To pin a run to one provider instead of the agent's own configuration, pass `provider` and `model`:

```php
$sample = $this->prompt($agent, 'prompt', provider: Lab::OpenAI, model: 'gpt-5.4');
```

A pinned run never fails over. Leave `model` out to use that provider's default model, the same choice the SDK
makes for a chain entry without a model; the agent's `#[Model]` attribute is not applied to a pinned provider.

### Assertions

| Assertion | What it checks |
| --- | --- |
| `assertPassesJudge($samples, $criteria, $threshold = 0.7)` | An LLM judge scores the output against free-text criteria. |
| `assertRelevant($samples, $threshold = 0.7)` | The output is relevant to the input. |
| `assertSafe($samples, $threshold = 0.7)` | The output is free of harmful content, unsafe advice and leaked sensitive data. |
| `assertFactual($samples, $expected, $threshold = 0.7)` | The output agrees with an expected answer. |
| `assertSimilar($samples, $expected, $threshold = 0.7)` | Embedding cosine similarity to an expected output. |
| `assertToolCalls($samples, $tools, $threshold = 1.0)` | The listed tools were called, optionally with matching arguments. |
| `assertTrajectory($samples, $steps, $strictOrder = true, $threshold = 1.0)` | The tools were called in the given order, or as a set when `strictOrder` is false. |
| `assertOutputContains($samples, ...$needles)` | Case-sensitive substring check; every needle must appear. |
| `assertOutputMatches($samples, $pattern)` | Regular expression check. |
| `assertOutputEquals($samples, $expected)` | Exact output match. |
| `assertOutputIsJson($samples)` | The output is valid JSON. |
| `assertPassesScorer($samples, $scorer, $threshold = 0.7, $expected = null)` | Any custom `Scorer`. |
| `assertServedBy($samples, $provider = null, $model = null)` | The response came from the given provider, model, or both. A model also matches its dated snapshots. |
| `assertDidNotFailOver($samples)` | The SDK did not have to fail over to another provider. |
| `assertFailedOver($samples, $from = null)` | The SDK failed over at least once, from the given provider when given. |

The tool assertions default to a threshold of 1.0, stricter than the Pest plugin's 0.7, so a partial
trajectory fails unless you lower it.

Tools can be referenced by class or by name. Arguments are matched as a subset, or by a closure:

```php
$this->assertToolCalls($sample, [
    SaveConfigTool::class => ['status' => 'active'],
    'send_email' => fn (array $arguments): bool => str_contains($arguments['subject'], 'Welcome'),
]);

$this->assertTrajectory($sample, [ListStatusesTool::class, SaveConfigTool::class]);
$this->assertTrajectory($samples, ['list_statuses'], strictOrder: false);
```

Provider tools are asserted the same way, by class (`Laravel\Ai\Providers\Tools\WebSearch::class`) or by the
provider's call name (`web_search_call`).

### Running an eval against every provider in the failover chain

Agents that declare a failover chain (`#[Provider([Lab::Anthropic->value => '...', Lab::OpenAI->value => '...'])]`)
only ever run on the fallback when the primary is down, so nothing checks that the fallback model passes the
same evals. Tag the class with the agent and the test with the `providerChain` data provider:

```php
use PHPUnit\Framework\Attributes\DataProvider;
use Prashank\AiEvals\Attributes\AgentUnderTest;
use Prashank\AiEvals\Support\ProviderHop;

#[AgentUnderTest(SupportAgent::class)]
class SupportAgentEvalTest extends TestCase
{
    use EvaluatesAgents;

    #[Test]
    #[DataProvider('providerChain')]
    public function answers_refund_questions_from_the_policy(ProviderHop $hop)
    {
        $sample = $this->prompt(new SupportAgent, 'Can I get a refund after 30 days?');

        $this->assertPassesJudge($sample, 'The agent says refunds are only available within 30 days.');
    }
}
```

The test runs once per provider / model in the chain and `prompt()` pins each run to the current hop, so the
body needs no changes. The `$hop` parameter is only required on PHPUnit 12 and later, which warn when a data
set carries more values than the method accepts; on PHPUnit 11 the method can stay parameterless. Data sets
are named `provider/model`, so they show up in the output as `with data set "openai/gpt-5.4"` and can be
selected with `--filter`:

```bash
php artisan test tests/Evals --filter='openai'                       # only the fallback hops
php artisan test tests/Evals --filter='answers_refund.*anthropic'    # one test on the primary
```

The chain is resolved with the SDK's own rules through reflection, without booting the application, because
PHPUnit collects data providers before any test runs. Agents must therefore declare the chain with the
`#[Provider]` attribute or a `provider()` method that does not depend on the constructor or the container.
`#[AgentUnderTest]` may sit on a shared base class, and the data provider is opt-in per test, so an eval that
is not worth running on every hop simply leaves the attribute off.

To run a test on hand-picked providers instead of the whole chain, use `#[TestWith]` and pass the values on:

```php
#[Test]
#[TestWith([Lab::Anthropic, 'claude-sonnet-5'], 'anthropic')]
#[TestWith([Lab::OpenAI, 'gpt-5.4'], 'openai')]
public function answers_refund_questions_from_the_policy(Lab $provider, string $model)
{
    $sample = $this->prompt(new SupportAgent, 'Can I get a refund after 30 days?', provider: $provider, model: $model);

    // ...
}
```

Without the data provider, `prompt()` runs the agent exactly as production does, failing over through the
chain, and the sample records what happened:

```php
$this->assertServedBy($sample, Lab::Anthropic, 'claude-sonnet-5');
$this->assertDidNotFailOver($sample);
$this->assertFailedOver($sample, from: Lab::Anthropic);
```

`assertServedBy` takes a provider, a model (`model: 'gpt-5.4'`), or both. Providers often report the dated
snapshot an alias resolved to, such as `gpt-5.4-2026-03-05`, so a model also matches its dated snapshots, but
never a sibling like `gpt-5.4-mini`. Pass the snapshot name to require that exact one.

Failure messages name the serving model, for example `Sample #1 [openai/gpt-5.4]`, and so does the verbose
output, together with the token usage summed across the run and any failovers:

```
PASS LlmJudge 0.9 / threshold 0.7
Input:     Can I get a refund after 30 days?
Model:     openai/gpt-5.4 (failed over from anthropic/claude-sonnet-5)
Usage:     4,812 in / 233 out, 3,900 cache read
Tools:     lookup_refund_policy
Output:    Refunds are only available within 30 days of purchase...
Reasoning: The answer states the 30 day limit and promises no exception.
```

### Custom scorers

Implement `Prashank\AiEvals\Scorers\Scorer` and return a `ScorerResult` with a score between 0 and 1:

```php
final class MentionsPrice implements Scorer
{
    public function score(Sample $sample, ?string $expected = null): ScorerResult
    {
        return new ScorerResult(str_contains($sample->output, '$') ? 1.0 : 0.0, 'Looked for a currency symbol.', self::class);
    }
}

$this->assertPassesScorer($sample, new MentionsPrice, threshold: 1.0);
```

The built-in scorers (`LlmJudge`, `Relevance`, `Safety`, `Factuality`, `SemanticSimilarity`, `ToolCallMatch`,
`AgentTrajectory`) can also be used directly through `assertPassesScorer`.

### Wrapped tools

If your app wraps tools in an adapter class, tell the framework how to reach the inner tool so class-based
assertions still work. Register the hook in your eval base class:

```php
use Prashank\AiEvals\Tools\ToolIdentity;

protected function setUp(): void
{
    parent::setUp();

    ToolIdentity::flushUnwrappers();
    ToolIdentity::unwrapUsing(fn (object $tool): ?object => $tool instanceof MyToolAdapter ? $tool->innerTool() : null);
}
```

### Incomplete evidence

Some assertions cannot be decided from the data the SDK exposes, for example asserting a provider tool's
arguments when the provider did not report them, or matching by class when the model requested a tool the SDK
never executed. In those cases the framework throws `IncompleteToolTraceException` rather than reporting a
misleading pass or fail. The message says what to assert instead, usually the tool name.

## Development

```bash
composer install
composer test
composer lint
```

## Credits

The judge prompts and scoring rules are ported from [pestphp/pest-plugin-evals](https://github.com/pestphp/pest-plugin-evals),
MIT licensed by Pest and contributors.
