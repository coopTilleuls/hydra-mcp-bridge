# Eval Benchmark Runner

Automates the evaluation protocol from `EVAL_PROTOCOL.md`. Runs 19 tasks against LLM CLI tools in headless mode, collects structured results and gateway NDJSON logs.

Compares two conditions:
- **Condition A (Hydra gateway)**: Lazy hypermedia-driven discovery. 1 tool at init, more appear as the agent navigates. MCP server is the gateway at `http://localhost:8000/mcp`.
- **Condition B (OpenAPI static)**: All tools pre-registered from OpenAPI spec at init. MCP server is `@ivotoby/openapi-mcp-server` pointed at `https://localhost/docs.jsonopenapi`.

No external dependencies — Node built-ins only (`child_process`, `fs`, `path`).

## Prerequisites

- Demo API running: `curl -sk https://localhost/books`
- For Condition A: gateway running at `http://localhost:8000/mcp`
- For Condition B: `npx @ivotoby/openapi-mcp-server` available (installed on first run)
- CLI tools installed: `claude`, `gemini`, `opencode`

## Project Directories

Each condition has its own project directory with `.mcp.json` and per-CLI settings:

| Condition | Directory | MCP Server |
|-----------|-----------|------------|
| A (Hydra) | `/home/soyuka/projects/test-hydra-mcp` | HTTP transport → `http://localhost:8000/mcp` |
| B (OpenAPI) | `/home/soyuka/projects/test-openapi-mcp` | stdio → `@ivotoby/openapi-mcp-server` |

All CLIs spawn with `cwd` set to the condition's directory so they pick up:
- `.mcp.json` — MCP server config
- `.claude/settings.local.json` — Claude tool permissions
- `opencode.json` — OpenCode MCP config

## Usage

```bash
# Condition A (Hydra gateway — default)
node eval/run.mjs                                # All tasks, all CLIs, k=1
node eval/run.mjs --cli claude --task T1.1       # Single task, single CLI
node eval/run.mjs --tier 1 --runs 5              # Tier 1, k=5

# Condition B (OpenAPI static)
node eval/run.mjs --condition B                  # All tasks, all CLIs
node eval/run.mjs --condition B --cli claude     # Single CLI

# Other options
node eval/run.mjs --timeout 180000               # 3min timeout per task
node eval/run.mjs --skip-reset                   # Skip DB fixture reset
node eval/run.mjs --dry-run                      # Preview what would run
```

## Analysis

```bash
node eval/analyze.mjs                            # Full summary (table)
node eval/analyze.mjs --format csv               # CSV output + files
node eval/analyze.mjs --cli claude               # Filter by CLI
node eval/analyze.mjs --tier 1                   # Filter by tier
node eval/analyze.mjs --condition A              # Filter by condition
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
    opencode.mjs      # OpenCode CLI adapter (github-copilot/gpt-5.4, run --format json)
```

Output: `var/eval/results/{taskId}_{condition}_{cli}_{run}.json`

## CLIs

| CLI | Model | Headless flag | JSON output |
|-----|-------|--------------|-------------|
| `claude` | sonnet | `-p` | `--output-format json` |
| `gemini` | gemini-2.5-flash | `-p` | `--output-format json` |
| `opencode` | github-copilot/gpt-5.4 | `run` | `--format json` |

## System Prompts

- **Condition A**: `SYSTEM_PROMPT.md` — "navigate first, never guess endpoints"
- **Condition B**: Built-in prompt listing all available resources, required fields, and constraints. No navigation instructions since all tools are already available.

## Execution Flow

Per task x condition x cli x run:

1. Reset DB fixtures via `docker compose exec` on demo API (`FIXTURES_SEED=42`)
2. Clear gateway MCP session cache (`var/cache/dev/mcp/*`) — Condition A only
3. Record pre-run timestamp
4. Spawn CLI subprocess with `cwd` set to the condition's project directory
5. Parse CLI output via adapter -> normalized result
6. Find matching gateway NDJSON log (Condition A only — Condition B uses stdio MCP)
7. Extract gateway metrics (tool calls, errors, tool list snapshots)
8. Evaluate goal state (keyword matching, API state check, or manual flag)
9. Write result to `var/eval/results/{taskId}_{condition}_{cli}_{run}.json`

## Gateway Log Matching (Condition A only)

The `McpEvalLogger` writes NDJSON to `var/eval/{clientSlug}_{sessionId}.ndjson`. The runner matches logs by:

1. Scanning `var/eval/` for files with mtime > pre-run timestamp
2. Filtering by client name prefix (`claude-code`, `gemini-cli-mcp-client`, `github-copilot-developer`)
3. Picking the most recently modified match

Condition B uses a stdio MCP server (`@ivotoby/openapi-mcp-server`) — no gateway logs are produced. Metrics come from CLI-reported usage only.

## Metrics

| Metric | Source | Auto-evaluated |
|--------|--------|----------------|
| M1: Task Completion | Goal checks (keyword match, API state) | Yes (except `manual` tasks) |
| M2: Tool Call Count | `gatewayMetrics.toolCallCount` (Cond A) / CLI output (Cond B) | Yes |
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
