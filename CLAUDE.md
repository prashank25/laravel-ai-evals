# Laravel AI Evals

PHPUnit port of the Pest evals plugin for `laravel/ai` agents. Developed against a consuming app, which
installs it through a symlinked Composer path repository, so edits here are live there immediately.

## Scope rules

- Match the Pest evals plugin feature set exactly. Do not invent concepts it does not have. If the plugin has
  a gap, name it in one line and let the user decide.
- Runtime dependencies are `laravel/ai` and `phpunit/phpunit` only. No Pest support, no driver contracts.
  `orchestra/testbench` and `laravel/mcp` are dev-only, for the package's own tests.
- Configuration is `config/ai-evals.php` backed by the `AI_EVALS_*` env vars. Nothing else.
- Public surface is the `EvaluatesAgents` trait. `Sample`, `Scorers/`, `Support/` and `Tools/` are plain classes.
- Tool assertions accept tool class names as well as tool names.
- App-specific glue (e.g. unwrapping a wrapper tool to its inner tool) belongs in the consuming app via
  `ToolIdentity::unwrapUsing()`, not here.

## Working here

- Tests: `composer test`. Formatting: `composer lint` (Pint, laravel preset).
- After changing `composer.json` here, run `composer update prashank/laravel-ai-evals` in the consuming app so
  package discovery picks it up. Source changes need nothing.
- Do not commit unless asked. Commit messages are one or two plain sentences, no bullet lists.
- Use `#[Test]` attributes, verbose test names, and local variables instead of shared fixtures.

## SDK facts that shaped the design

- `GeneratesText` reads `$agent->tools()` after dispatching `PromptingAgent`, so no event exposes the resolved
  tool list. Identity comes from `InvokingTool` events (via `InvokedToolRecorder`) plus a post-run `tools()` read
  that may fail for consumed generators. When unresolved calls could change a class-based verdict, scorers throw
  `IncompleteToolTraceException` instead of guessing.
- Provider tools (Anthropic `server_tool_use`, OpenAI `web_search_call` / `file_search_call`) only appear in
  `Step->raw`, parsed by `ProviderResponseParser`.
- `Agent::fake([...])` consumes its queue across prompts and runs real tool handlers, so it dispatches
  `InvokingTool`.
- Known laravel/ai 0.11 issues (not ours to fix): Anthropic occasionally drops connections (cURL 52/56), and
  failover from Anthropic to OpenAI breaks on histories with tool calls because OpenAI needs a `call_id` that
  Anthropic-originated history lacks.
