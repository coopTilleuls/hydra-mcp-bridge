# Research log

Each entry is a hypothesis or approach: what we tried, what happened, and why we kept or dropped it.
Abandoned approaches are kept on purpose: they record the scientific and technical uncertainty behind the final design.
Each entry links to its evidence (commits, PRs, result archives).

## 2026-11 — Eval reproduction on OpenRouter

### Running March condition A again on a fresh server (abandoned run, "leaky gateway")
- Hypothesis: rerunning A on our own server with OpenRouter models reproduces the March numbers.
- Result: invalid. In FrankenPHP worker mode, dynamic tools leaked across MCP sessions: a new session inherited tools, with stale URIs, discovered by earlier ones. This hid other bugs and inflated some runs.
- Kept: `ResetInterface` on the registry, its decorator and the store, plus a regression test (`testDynamicToolsDoNotLeakAcrossSessionsInWorkerMode`). Commit 98eb03d. Results archived as `archive/leaky-gateway`.

### Rerun after the leak fix ("A2", abandoned)
- Result: every write task failed. After the mcp/sdk upgrade, `notifications/tools/list_changed` was silently dropped: the SDK only accepts typed suspensions, and a raw array passed to `Fiber::suspend` is ignored with a warning. Clients never refetched tools, so the typed tools were never visible. The leak had been masking it.
- Kept: send it through `ClientGateway::notify`, and assert the notification in the functional test. Commit 98eb03d. Results archived as `archive/a2-no-notification`.

### Third rerun ("A3"), compared with March (confounded)
- Result: the comparison is not valid. The March A sessions all had `SYSTEM_PROMPT.md` prepended (114/114, against 0/190 B sessions, checked in the OpenCode DB); it was removed in 98eda22 after the runs. The MCP `initialize` instructions also described the static tools to dynamic clients.
- Kept: per-mode instructions (`DynamicModeInstructions`) and the frozen March prompt in `eval/prompts/condition-a.md`. Commit 98eb03d / e956005. Results archived as `archive/a3`.

### Keyword grading (dropped)
- Finding: several goals passed wrong answers. "rating" matched any answer (T1.2). "Hyperion" plus "bookmark" passed false claims of success (T3.4: 2 of the 3 March Gemini "passes" made no bookmark call). A count-only check passed models that substituted another Open Library ID (T2.1, T4.2). T4.2 used `OL35227850M`, a deleted Open Library record that the demo could never create.
- Kept: a shared grader (`eval/grade.mjs`) that checks API state and regex goals, and a live ID for T4.2. Commit e956005. Moving from the paper's figures to the regraded ones flips the A vs B ordering (A 91.2/86.0 → 86.0/82.5, B 87.4/83.2 → 89.5/86.3), with A still provisional.

### Keyword grading, second pass (2026-11-26)
- Finding (full A, gpt-6-luna, paused at 53/171): five correct refusals were graded as failures. Models write typographic apostrophes ("can’t") while the keywords use ASCII ones, and past-tense refusals ("I couldn’t change the author") were not in the T2.6 list.
- Kept: quote normalization in `gradeResponse` and past-tense variants, with `eval/grade.test.mjs`. Open question: keyword lists for the negative-capability tasks keep chasing phrasings, so an LLM judge or manual review may be needed for those tasks.
- Also found: the demo fetches Open Library live when a book is created, and two correct `post_book` calls failed on timeouts and connection resets. The run was paused so these lookups can be replayed from recorded responses.

### OpenCode built-in tools bypassed the interface (2026-11-26)
- Finding: OpenCode offers its built-in tools (bash, read, webfetch, grep, skill, …) alongside the MCP ones. In the A run, 24 of 163 runs used them: they curled the gateway and the demo directly, read local config files, and fetched Open Library to find IDs. A `cookies.txt` left by one run was visible to later ones. Clean runs passed 136/139, contaminated runs 9/24, and the contamination is concentrated on the negative-capability tasks (T2.5, T2.6, T4.4, T1.5) and T3.4. The built-in tool schemas also add tokens to every request, which skews the context-cost metrics. The March runs likely had them enabled too (to check).
- Kept: `permission: {"*": "deny", "invalid": "allow", "book-api_*": "allow"}` in both conditions. Verified through a logging proxy: the request's `tools` array holds only MCP tools (A: `read_api_resource` then discovered tools; B: the 19 OpenAPI tools). The test dirs were cleaned, leaving only opencode.json and the init git repo.

### Context cost against API size (2026-11-27)
- Finding on the 19-operation demo: the interfaces tie on success (A/B: luna 57/54, gemini 51/50, deepseek 53/54 of 57) but A used 1.4× to 3× more input tokens, so the context claim cannot be made on a small API.
- Hypothesis: B loads every operation up front, A only the visited collections, so A should stay flat as the API grows.
- Protocol: `EVAL_EXTRA_RESOURCES=N` adds N in-memory resources (5 operations each, never touched by the tasks; N=0 is byte-identical). gpt-6-luna, tasks T1.1 T1.3 T2.1 T2.4 T4.1, k=3, A and B.
- Result, input tokens per run (A / B): N=0 (19 ops) 4320 / 2848, N=10 (69 ops) 4103 / 7024, N=40 (219 ops) 4596 / 22339, N=100 (519 ops) 5117 / 43676. Success stayed 15/15 in both conditions at every N. The crossover lies between 19 and 69 operations; at 519 operations B uses 8.5× the context of A.
- Behaviour per condition (full A/B): Gemini deleted and recreated Hyperion to work around a read-only field only in A; luna refused to invent an Open Library ID only in A (3/3) and invented one in B (3/3); Gemini reported the OpenAPI server's abbreviated tool name "usrs" as a collection in B (3/3).
- Superseded by the next entry: these token figures dropped cache writes.

### Token usage dropped cache writes (2026-11-27)
- Finding: the OpenCode adapter summed `input + cache.read` per step and ignored `cache.write`. A step whose prompt was written to the provider cache counted ~0 tokens, so the figures depended on cache luck (A T2.4 luna: 3022 counted, 29.6k sent). Only gpt-6-luna is affected (OpenAI reports cache writes); the deepseek and gemini figures are unchanged.
- Kept: `promptTokens = input + cache.read + cache.write`, the context the model was sent (`sumUsage`, `eval/adapters/opencode.test.mjs`), and `eval/backfill-from-raw.mjs`, which recomputed every result from the raw OpenCode logs (507/507 matched, backup `~/hydra-eval-backup/pre-usage-backfill.tgz`). Commit 927e2af.
- Corrected full A/B, luna, prompt tokens per run: A 21.2k, B 10.6k (2.0×, was 1.4×).
- Corrected scaling (luna, 5 tasks, k=3), prompt tokens per run A / B: N=0 16.6k / 7.9k, N=10 17.2k / 15.6k, N=40 17.5k / 42.8k, N=100 18.9k / 81.4k. The crossover moves to between 69 and 219 operations; at 519 operations B uses 4.3× the context of A. A grows 14% from 19 to 519 operations, B ×10.

## 2026-11 — Stateless discovery: MCP groups (condition G)

### Why groups rather than condition C
- MCP 2026-07-28 forbids a per-connection tool list, which is how A works. Condition C (`read_api_resource` + `invoke_api_operation`) is stateless but loses typed tools. The draft groups extension (`io.modelcontextprotocol/groups`, modelcontextprotocol/progressive-disclosure-wg#19) keeps typed tools and lazy loading: `tools/list` holds top-level tools, `groups/list` names groups, `groups/get` returns a group's tools, and each request declares the extension in `_meta`. C is kept as a fallback for clients that negotiate neither.

### Gateway (commit 9916af2)
- One group per Hydra class, `groups/get` returns the typed tools A would discover for that class (CRUD, link-bound, search), built from `docs.jsonld` with no per-client state. Scoped tools run by name without a prior `groups/get` (the SEP: discovery gating is not access control).
- First cut grouped every documented class: API Platform also documents `Error` and `ConstraintViolation`, which A can never reach. Kept: only classes reachable from the entrypoint through `hydra:Link` properties.
- Not covered: inline-only `hydra:operation` (they exist per resource, not per class) and search on sub-collections (the template is only in the sub-collection's response). `read_api_resource` still exposes both; no eval task uses them.

### Client: OpenCode fork (soyuka/opencode, branch feat/mcp-groups on v1.19.6)
- No released client speaks the extension. The fork adds `experimental.mcp_groups`: it declares the extension (initialize and per-request `_meta`), calls `groups/list`, exposes a client-side `load_group` tool listing the groups, and merges the tools `groups/get` returns. The per-step tool refresh of 1.19.6 picks them up within the same turn.
- Parity, flag off: identical `initialize` on the wire (clientInfo, capabilities, protocol) and, on condition A (luna, the 5 scaling tasks, k=3), 15/15 with prompt tokens identical to stock to the token on T1.3, T2.4, T4.1. Run-to-run differences on T1.1/T2.1 are within stock's own variance.

### Scaling result (luna, 5 tasks, k=3, prompt tokens per run)
| N (operations) | A | B | G | GS |
|---|---|---|---|---|
| 0 (19) | 16.6k | 7.9k | 17.0k | 20.8k |
| 10 (69) | 17.2k | 15.6k | 18.4k | 21.2k |
| 40 (219) | 17.5k | 42.8k | 21.9k | 22.9k |
| 100 (519) | 18.9k | 81.4k | 28.9k | 23.0k |
- Success 15/15 for G at every N (60/60).
- G costs one more step than A (`load_group`) and grows +70% from 19 to 519 operations, against +14% for A and ×10 for B. The growth is the group list: the client lists every group in the `load_group` description, and the context is resent each step, on top of the entrypoint links A also reads. At 519 operations G uses 2.8× less than B, 1.5× more than A.

### Bounding the group list: client-side search (condition GS, 2026-11-27)
- The SEP does not say how a client shows groups to the model, so G's growth is a client choice. Listing groups on demand (a `list_groups` tool) was rejected without a run: its result stays in the history and is resent each step, the same cost as the description.
- Retained: `experimental.mcp_groups_catalog: "search"` in the fork. `load_group` takes a free name (no list, no enum) and a client-side `find_groups(query)` returns at most 10 groups from the `groups/list` result. The server and the condition G prompt are unchanged.
- First matcher (every query word must match): rejected. luna phrases intents ("review create", "books create add book"), the verbs match no group description, so it searched 2–3 times per write task and N=0 reached 31.3k (T2.4 up to 94k). 36 runs archived in `var/eval/scaling-gs-and-matcher` on the eval server.
- Kept: any word counts, ranked by hits then by group name, plural folded to singular. One search per run at most after that, often none (luna guesses `load_group(book)` from `/books`).
- Result: 60/60. GS grows +11% from 19 to 519 operations, close to A (+14%), but starts 3.8k above G: the search is one more step and each step resends the context. G and GS cross near 220 operations; at 519, GS uses 3.5× less than B and 1.2× more than A.
- Takeaway: groups scale like lazy discovery only when the client keeps the group catalog out of the prompt. Inline listing is cheaper for small APIs.

## 2026-11 — Binding dynamic tools to sub-resources

### Problem
`create_review` kept the URI of the first reviews collection the session had read, because tools were keyed by name and carried a concrete URI. Reviews for a new book landed on Hyperion (eval run T4.2, gpt-5-mini).

### Approaches considered and rejected
1. Required `uri` on create tools: rejected, it adds information the model must supply instead of using what the hypermedia already provides.
2. Optional `uri` that defaults to the discovered collection: rejected, the trap remains whenever the model omits it.
3. The last collection read wins: rejected, the target would depend on hidden navigation order.
4. A URI template (`/books/{id}/reviews`) for the model to fill: valid under Hydra §5.4, but §5.3 restricts templates to URLs the server cannot build. Here the server already serializes the link (`"reviews": "/books/<id>/reviews"`). Kept for search only.

### Retained: Hydra link operations (Hydra Core §4.2 example 12, §7)
- Operations are declared on the parent's `hydra:Link` property. The gateway tool takes the parent IRI (`create_review(book, …)`) and follows the link at call time. Gateway commit 064c718.
- Upstream API Platform did not emit this: sub-resources showed up as a phantom `#Entrypoint/<name>`. That fix is api-platform/core#8876.

### API Platform fix iterations (#8876)
1. Keyed on `Link::fromProperty`: rejected. Real apps declare `toProperty` links, and adding `fromProperty` changes runtime link selection (`Doctrine/Common/State/LinksHandlerTrait.php:58,92`, `LinkResourceMetadataCollectionFactory:83-92`). A `fromProperty`-only property also serializes as member IRIs, not as the collection.
2. Inferring the inverse property from `toProperty`: rejected as a risky implicit feature (ambiguous collections, non-Doctrine providers).
3. Retained: key on `ApiProperty(uriTemplate)`, the only case where the property's value is the sub-resource collection IRI (`AbstractItemNormalizer.php:914,950-956`).
4. Review fix: remove an operation from the entrypoint only when a property links to it AND its uriTemplate has URI variables. Removing it as soon as a property had a uriTemplate dropped top-level collections; removing every sub-resource made unlinked ones undiscoverable.

### Backporting to API Platform 4.2 for the demo (abandoned)
- Goal: keep March condition B comparable by changing only `docs.jsonld`.
- Dropped in favour of upgrading everything to the latest stack (API Platform 5.1-dev, Symfony 8.1, PHP 8.5, FrankenPHP 1.14) and rerunning every condition. The old B results stop being a baseline.

### Entrypoint links after the API Platform 5 upgrade
- Finding (A smoke, 2026-11-26, 7/12): no model could create a book. API Platform 5 describes an entrypoint link's member class with `hydra:memberAssertion` (`rdf:type` → `#Book`) on a `hydra:Collection` range. Before, it used `owl:equivalentClass`/`owl:allValuesFrom`, the only shape the gateway knew, so `post_book` was never registered.
- Kept: resolve the member class from `hydra:memberAssertion` (Hydra §5.2), keeping the owl path for older versions. Link-bound `post_review` passed on all three models in the same smoke.

## 2026-11 — Infrastructure

### Pinning FrankenPHP 1.11 (abandoned)
- FrankenPHP ≥1.13 ships Mercure 1.0, which rejected the demo's hub config: JWT keys outside an `issuer` block, and two unnamed hubs. Pinning the old image was rejected; the config was migrated as in dunglas/symfony-docker#969.
- The `playground` directive from that same change redirects `/` to the Mercure UI and breaks the Hydra entrypoint, so it was dropped (gateway 3332add).

### SSRF through model-controlled URLs
- Link following and search targets come from the model. `SameOriginHttpClient` decorates `hydra.client` and rejects any other origin. Commit 5f2c6cf.

## 2026-11 — Model and sampling protocol

- Models: gpt-6-luna (OpenAI), gemini-3.5-flash-lite (Google AI Studio), deepseek-v4.1-flash (DeepInfra fp8). Three vendors, all cheaper than the March pair.
- Determinism: temperature 0 where supported (gpt-6-luna rejects it), seed 42, provider pinned with `allow_fallbacks: false` and `service_tier: "default"`. Temperature 0 alone is not deterministic: provider routing, quantization and batching all add variance, so k≥3 stays.
- Reasoning: an explicit `effort: "low"` for every model. The March runs were confounded: OpenCode forces `high` on Gemini, gpt-5-mini ran at its default, and the paper states neither. A sensitivity arm at `high` is planned to separate the interface effect from the reasoning budget.
