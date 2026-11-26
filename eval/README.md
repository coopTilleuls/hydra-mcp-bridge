# Eval Benchmark Runner

Automates the evaluation protocol from `EVAL_PROTOCOL.md`. Runs 19 tasks against LLM CLI tools in headless mode, collects structured results and gateway NDJSON logs.

Compares three conditions:
- **Condition A (Hydra gateway)**: Lazy hypermedia-driven discovery. 1 tool at init, more appear as the agent navigates. MCP server is the gateway at `http://localhost:8000/mcp`.
- **Condition C (Hydra gateway, static tools)**: The same gateway and the same project directory as A, started with `HYDRA_LEGACY_DISCOVERY=static`. `tools/list` is always exactly two tools, `read_api_resource` and `invoke_api_operation`. Read results carry the operations and their JSON schemas, and `invoke_api_operation(uri, method, payload)` runs them. Under A (`HYDRA_LEGACY_DISCOVERY=dynamic`, the default) `invoke_api_operation` is never listed.
- **Condition B (OpenAPI static)**: All tools pre-registered from OpenAPI spec at init. MCP server is `@ivotoby/openapi-mcp-server` pointed at `https://localhost/docs.jsonopenapi`.

No external dependencies — Node built-ins only (`child_process`, `fs`, `path`).

## Prerequisites

- Demo API running: `curl -sk https://localhost/books`
- For Conditions A and C: gateway running at `http://localhost:8000/mcp` in the matching mode (see below)
- For Condition B: `npx @ivotoby/openapi-mcp-server` available (installed on first run)
- CLI tools installed: `claude`, `gemini`, `opencode`

## Project Directories

Each condition has its own project directory with `.mcp.json` and per-CLI settings:

| Condition | Directory | MCP Server |
|-----------|-----------|------------|
| A (Hydra) | `/home/soyuka/projects/test-hydra-mcp` | HTTP transport → `http://localhost:8000/mcp` |
| C (Hydra, static tools) | `/home/soyuka/projects/test-hydra-mcp` (same as A) | HTTP transport → `http://localhost:8000/mcp` |
| B (OpenAPI) | `/home/soyuka/projects/test-openapi-mcp` | stdio → `@ivotoby/openapi-mcp-server` |

All CLIs spawn with `cwd` set to the condition's directory so they pick up:
- `.mcp.json` — MCP server config
- `.claude/settings.local.json` — Claude tool permissions
- `opencode.json` — OpenCode MCP config, plus sampling and OpenRouter provider pinning. Copy it from `eval/opencode/<dir>/opencode.json` (see [Models and sampling](#models-and-sampling)). The tracked copies point at the shared eval host's ports (`18200` gateway, `18143` demo API); change the `mcp` URLs for a local stack.

## Models and sampling

Default models (`eval/config.mjs`, overridden by `EVAL_MODELS`): `openrouter/openai/gpt-6-luna`, `openrouter/google/gemini-3.5-flash-lite`, `openrouter/deepseek/deepseek-v4.1-flash`.

`eval/opencode/test-hydra-mcp/opencode.json` and `eval/opencode/test-openapi-mcp/opencode.json` carry the same sampling block; only `mcp` differs:

| Model | OpenRouter endpoint (`provider.order`, `allow_fallbacks: false`) | temperature | seed |
|-------|------------------------------------------------------------------|-------------|------|
| `openai/gpt-6-luna` | `openai` | not sent (`"temperature": false` on the model) | 42 |
| `google/gemini-3.5-flash-lite` | `google-ai-studio` (the `google-vertex` endpoint ignores temperature) | 0 | 42 |
| `deepseek/deepseek-v4.1-flash` | `deepinfra/fp8`, `quantizations: ["fp8"]` | 0 | 42 |

Every model also sends `reasoning: {effort: "low"}` and `service_tier: "default"`. Providers sell flex and priority tiers as separate endpoints; `service_tier: "default"` pins the standard one, and a tier-less slug such as `deepinfra/fp8` never matches a tier endpoint ([provider routing](https://openrouter.ai/docs/features/provider-routing), [service tiers](https://openrouter.ai/docs/guides/features/service-tiers)).

How OpenCode 1.19.6 forwards them:

- `agent.build.temperature: 0`: `opencode run` uses the `build` agent. OpenCode sends `temperature` only when the model's `temperature` capability is true, so `"temperature": false` on gpt-6-luna drops it for that model.
- `provider.openrouter.models.<id>.options`: becomes the AI SDK `providerOptions.openrouter`, which `@openrouter/ai-sdk-provider` spreads into the request body verbatim. That is how `seed` and the OpenRouter `provider` routing object reach the API (OpenCode does not pass the AI SDK `seed` call setting).
- OpenCode adds `usage: {include: true}` for OpenRouter and, for Gemini models, `reasoning: {effort: "high"}`. Model `options` are merged after these defaults, so the configured `effort: "low"` wins.
- `agent.title.disable: true` removes the title agent, and `opencode run` then skips title generation (otherwise an extra, unpinned call to an auto-picked small model). `small_model` is set to `openrouter/openai/gpt-6-luna` so any other small-model call uses the pinned route.

## Switching the gateway mode (A and C)

A and C use the same project directory and the same gateway; only the gateway's `HYDRA_LEGACY_DISCOVERY` env var differs. It is passed through by `compose.yaml`. From the repo root:

```bash
HYDRA_LEGACY_DISCOVERY=dynamic docker compose up -d   # Condition A (default)
HYDRA_LEGACY_DISCOVERY=static docker compose up -d    # Condition C
```

Before running tasks (skipped with `--dry-run`), `run.mjs` opens an MCP session on `https://localhost:8000/mcp` and checks `tools/list`: `invoke_api_operation` must be listed for C and absent for A. On a mismatch, or if the gateway is unreachable, it prints the fix and exits with code 1, so results are never mislabeled.

## Usage

```bash
# Condition A (Hydra gateway — default)
node eval/run.mjs                                # All tasks, all CLIs, k=1
node eval/run.mjs --cli claude --task T1.1       # Single task, single CLI
node eval/run.mjs --tier 1 --runs 5              # Tier 1, k=5

# Condition C (Hydra gateway, static tools — restart the gateway with HYDRA_LEGACY_DISCOVERY=static first)
node eval/run.mjs --condition C                  # All tasks, all models
node eval/run.mjs --condition C --task T1.1      # Single task

# Condition B (OpenAPI static)
node eval/run.mjs --condition B                  # All tasks, all CLIs
node eval/run.mjs --condition B --cli claude     # Single CLI

# Other options
node eval/run.mjs --timeout 180000               # 3min timeout per task
node eval/run.mjs --skip-reset                   # Skip DB fixture reset
node eval/run.mjs --dry-run                      # Preview what would run
```

## Judge

Keyword grading of refusal answers produces false failures ("a new user cannot be created" misses the keyword "cannot create"). The negative-capability tasks T1.5, T2.5, T2.6 and T4.4 therefore carry a `judge: { rubric }` in `eval/tasks.mjs`, next to their keyword goal.

```bash
node eval/judge.mjs <results-dir>      # writes <results-dir>/judgments.json
node eval/analyze.mjs                  # EVAL_RESULTS_DIR=<results-dir>, reports keywordPassRate and judgePassRate
```

- The judge is Sonnet (`claude -p --model sonnet --output-format json`, no tools, prompt on stdin), run through the local Claude Code subscription, no API key. It sees the task prompt, the rubric, the agent's final response and the tool calls with their arguments (clipped to 400 characters each). Condition B results carry no tool calls, the prompt says so and the judge works from the response alone.
- It must answer `{"verdict":"PASS"|"FAIL","reason":"..."}`; an unparsable answer is retried once. Two calls run at a time.
- `judgments.json` is keyed by result filename and stores verdict, reason, model, date and the sha256 of the prompt. An entry whose prompt hash is unchanged is skipped, so reruns cost nothing. Changing a rubric or a response re-judges that file only.
- `analyze.mjs` uses the judge verdict for the M1 pass rate of judged runs and adds `keywordPassRate` and `judgePassRate` columns.
- The judge is itself an LLM: report a manual agreement sample (a hand-read subset of the judged runs against the judge verdicts) alongside the results.

## Analysis

```bash
node eval/analyze.mjs                            # Full summary (table)
node eval/analyze.mjs --format csv               # CSV output + files
node eval/analyze.mjs --cli claude               # Filter by CLI
node eval/analyze.mjs --tier 1                   # Filter by tier
node eval/analyze.mjs --condition A              # Filter by condition (A, B or C)
```

## File Structure

```
eval/
  config.mjs          # Shared constants (paths, client slugs, Condition B system prompt)
  run.mjs             # Main runner — CLI args, task loop, subprocess orchestration
  tasks.mjs           # 19 tasks from EVAL_PROTOCOL.md
  analyze.mjs         # Post-run metrics aggregation (M1-M5)
  adapters/
    claude.mjs        # Claude CLI adapter (sonnet, --output-format json)
    gemini.mjs        # Gemini CLI adapter (gemini-2.5-flash, --output-format json)
    opencode.mjs      # OpenCode CLI adapter (run --format json)
  opencode/
    test-hydra-mcp/opencode.json    # OpenCode config for Conditions A and C
    test-openapi-mcp/opencode.json  # OpenCode config for Condition B
```

Output: `var/eval/results/{taskId}_{condition}_{cli}_{run}.json`

## CLIs

| CLI | Model | Headless flag | JSON output |
|-----|-------|--------------|-------------|
| `claude` | sonnet | `-p` | `--output-format json` |
| `gemini` | gemini-2.5-flash | `-p` | `--output-format json` |
| `opencode` | `EVAL_MODELS` / `config.mjs` (OpenRouter) | `run` | `--format json` |

## System Prompts

- **Condition A**: `eval/prompts/condition-a.md` is prepended to the task prompt. It is `SYSTEM_PROMPT.md` as the March 2026 runs used it, kept verbatim (it still names the old `read_hydra_resource` tool). The gateway's MCP `instructions` add to it: a dynamic-mode `initialize` gets `app.mcp.dynamic_instructions` (`config/packages/mcp.yaml`, the March text).
- **Condition B**: no system prompt, the OpenAPI tools are all listed up front.
- **Condition C**: no system prompt yet. The gateway serves the static-mode `instructions` (`invoke_api_operation`).

## Execution Flow

Per task x condition x cli x run:

1. Reset DB fixtures via `docker compose exec` on demo API (`FIXTURES_SEED=42`)
2. Clear gateway MCP session cache (`var/cache/dev/mcp/*`)
3. Record pre-run timestamp
4. Spawn CLI subprocess with `cwd` set to the condition's project directory
5. Parse CLI output via adapter -> normalized result
6. Find matching gateway NDJSON log (Conditions A and C — Condition B uses stdio MCP)
7. Extract gateway metrics (tool calls, errors, tool list snapshots)
8. Evaluate goal state (keyword matching, API state check, or manual flag)
9. Write result to `var/eval/results/{taskId}_{condition}_{cli}_{run}.json`

## Gateway Log Matching (Conditions A and C)

The `McpEvalLogger` writes NDJSON to `var/eval/{clientSlug}_{sessionId}.ndjson`. The runner matches logs by:

1. Scanning `var/eval/` for files with mtime > pre-run timestamp
2. Filtering by client name prefix (`claude-code`, `gemini-cli-mcp-client`, `github-copilot-developer`)
3. Picking the most recently modified match

Condition B uses a stdio MCP server (`@ivotoby/openapi-mcp-server`) — no gateway logs are produced. Metrics come from CLI-reported usage only.

## Metrics

| Metric | Source | Auto-evaluated |
|--------|--------|----------------|
| M1: Task Completion | Goal checks (keyword match, API state) | Yes (except `manual` tasks) |
| M2: Tool Call Count | `gatewayMetrics.toolCallCount` (Cond A, C) / CLI output (Cond B) | Yes |
| M3: Error Rate | `gatewayMetrics.errorCount` | Yes |
| M4: Tool Selection Precision | Requires LLM judge | Pending |
| M5: Token / Context Cost | `usage` + `gatewayMetrics.toolListSnapshots` | Yes |

## Result Format

```json
{
  "taskId": "T1.1",
  "tier": 1,
  "condition": "A",
  "cli": "claude",
  "model": "sonnet",
  "run": 1,
  "timestamp": "2026-03-10T22:30:00Z",
  "prompt": "Is there a book by Dan Simmons in the catalog?",
  "response": "Yes, there is a book by Dan Simmons...",
  "sessionId": "abc-123",
  "usage": {
    "inputTokens": 1234,
    "outputTokens": 567,
    "totalTokens": 1801,
    "cacheReadTokens": 800,
    "cacheCreationTokens": 200
  },
  "durationMs": 8500,
  "exitCode": 0,
  "timedOut": false,
  "isNegativeCapability": false,
  "goalResult": { "pass": true, "detail": "all keywords found" },
  "gatewayLog": "var/eval/claude-code_abc123.ndjson",
  "gatewayMetrics": {
    "toolCallCount": 3,
    "toolCalls": [
      { "tool": "read_api_resource", "args": { "uri": "/" } },
      { "tool": "read_api_resource", "args": { "uri": "/books" } },
      { "tool": "search_books", "args": { "author": "Dan Simmons" } }
    ],
    "errorCount": 0,
    "toolListSnapshots": [1, 7],
    "discoveredTools": ["read_api_resource", "create_book", "search_books"]
  }
}
```
