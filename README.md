# Laravel AI Evals

PHPUnit evals for [laravel/ai](https://github.com/laravel/ai) agents: LLM-as-judge, factuality, relevance,
semantic similarity, tool call and trajectory assertions. A port of the Pest evals plugin for PHPUnit users.

## Requirements

- PHP 8.3+, Laravel 12+, laravel/ai 0.11+, PHPUnit 11, 12 or 13

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
| `AI_EVALS_VERBOSE` | `false` | prints every score and the judge's reasoning |

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
tool the model called in order, including provider-side tools such as web search.

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
