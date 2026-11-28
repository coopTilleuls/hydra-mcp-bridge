# Notes for the groups SEP (progressive-disclosure-wg#19)

Raw material for a comment written by hand. The modelcontextprotocol org requires disclosing AI assistance in PRs, issues and comments (`AI_POLICY.md` in modelcontextprotocol/modelcontextprotocol): the gateway and the eval harness were built with AI assistance, say so.

## Thread context (2026-11-28)

- The spec does not say how a client presents groups to the model ("does **not** mandate how a client exposes the resulting instructions or scoped primitives").
- helloeve (11-07), local 8B/14B, 19 tools: groups save context but "do not beat a flat list the model can already read".
- abcreativ (11-16), 521 tools: upfront capability text helped more than grouping.
- No sponsor yet. Beta needs an Extensions Track SEP and a reference implementation in an official SDK (php-sdk is one). Stable needs one client and one server interoperating: the gateway and the OpenCode fork are that pair.

- #20 (helloeve, open, no measurements committed): TypeScript helper `@modelcontextprotocol/ext-grouping` over `@modelcontextprotocol/sdk` 1.27.x, flat `groups/get` shape, drops `ttlMs`/`cacheScope` (not in the base SDK). Clients: `groups-agent` (Claude, `list_groups` + `get_group` tools) and `groups-mod` (Claude Code plugin, group list injected in the prompt, `open_group`). Both are our inline presentation; no search variant anywhere. chughtapan's 4 review threads (membership, Identity, caching) unanswered.
- Other implementations: panyam/mcpkit (Go). #11 uses the older id `io.modelcontextprotocol/grouping`. #18 (Apify, 70k+ tools) asks for `tools/resolve`, no data.
- Ours matches #19: id `io.modelcontextprotocol/groups`, plural methods, flat result, `ttlMs`/`cacheScope` kept. A PHP server and a third client (OpenCode, inline and search) next to #20's TypeScript ones.
- Where to share: findings PR to `docs/experimental-findings.md` (CONTRIBUTING asks for reproducible detail, clients/servers tested, what worked/didn't/untested), a short #19 comment linking it, a line on Discord `#primitive-grouping-ig`.

## Setup

- Gateway: one group per Hydra class, generated from docs.jsonld, no hand-written groups. OpenCode fork negotiating `io.modelcontextprotocol/groups`.
- 19 tasks on a books API, 3 models through OpenRouter (gpt-6-luna, gemini-3.5-flash-lite, deepseek-v4.1-flash), reasoning effort low, k=3.
- Tokens: prompt tokens billed by the provider (input + cache), summed per task. Not a serialization size.
- Scaling: 19 → 519 operations with extra classes no task touches.
- Conditions: flat (every tool in `tools/list`), inline (every group in the `load_group` description and enum), search (`find_groups(query)`, catalog out of the prompt).

## Numbers

Mean prompt tokens per task, 19 → 519 operations (scaling tasks):

| model | flat | inline | search |
|---|---|---|---|
| luna | 7.9k → 81.4k | 17.7k → 26.2k | 23.2k → 26.6k |
| gemini | 11.7k → 154.9k | 23.2k → 33.4k | 37.0k → 35.0k |
| deepseek | 10.3k → 154.0k | 28.1k → 33.7k | 29.8k → 37.6k |

- Flat grows 10–15×, groups stay under 1.7×. Flat loses between 69 and 219 operations.
- At 19 operations flat is cheapest (agrees with helloeve).
- Pass rates on the full grid (/57, judge applied): luna flat 54, inline 57, search 56; gemini 51 / 54 / 52; deepseek 54 / 54 / 49. Groups do not cost success.
- First-step prompt (tool definitions): inline grows with the API (luna 1.6k → 3.3k, gemini 4.4k → 6.2k, deepseek 2.9k → 4.7k), search stays flat.
- Inline stays at or under search on every model up to 519 operations (luna converges, 26.2k vs 26.6k). Differences under ~15% are noise at k=3.

## Points for the spec

1. Client presentation decides most of the cost. Inline grows with the catalog, search does not, but inline stayed cheaper overall up to 519 operations on all three models, converging on luna. Search pays one extra step, inline pays a growing prompt: where they cross depends on the model and lies beyond 519 operations here.
2. Search alone needs a way to enumerate. deepseek called `find_groups("a")`, `find_groups("*")` on T3.4 when it could not name the group, 584k–905k tokens per run. A client offering search should also be able to list (a SHOULD on the client side?).

Method and raw results: `docs/research-log.md`.
