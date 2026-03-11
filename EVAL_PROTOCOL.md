# Evaluation Protocol: Hydra MCP Gateway vs. OpenAPI MCP Static Tools

## Objective

Measure whether **lazy hypermedia-driven discovery** (Hydra gateway) enables LLM agents to complete CRUD + search tasks as effectively as **static pre-registered tools** (OpenAPI MCP), while comparing navigation cost, tool selection accuracy, and task completion.

## Two Conditions

| | **Condition A: Hydra Gateway** | **Condition B: OpenAPI MCP (baseline)** |
|---|---|---|
| Tool registration | Lazy, navigation-triggered (3-phase) | All tools pre-registered at init |
| Tool source | Hydra `docs.jsonld` + inline affordances | OpenAPI spec → static MCP tools |
| System prompt | `SYSTEM_PROMPT.md` (generic, "navigate first") | Standard: "You have tools to manage books, reviews, and bookmarks. Use them." |
| Navigation tool | `read_api_resource(uri)` | None (or `read_resource` if needed for fairness) |
| Tool count at init | 1 | All (~20: CRUD + search across all resources) |

### Setting up Condition B

Use an OpenAPI-to-MCP bridge (e.g., `openapi-mcp-server` or API Platform's built-in static MCP mode) pointed at the same API. All tools are registered at `initialize` — the agent never navigates. The system prompt lists available tools and their purpose.

## Domain: API Platform Demo

Upstream Hydra API (`https://localhost`) with these resources:

### Book (`schema:Book`)
- `book` — Open Library ID (required, **writable**) — e.g. `"/books/OL3672935M"`
- `title` (`schema:name`) — readable, **not writable** (server-hydrated from Open Library)
- `author` — readable, **not writable** (server-hydrated from Open Library)
- `condition` — required, **writable** (enum)
- `reviews` — link to Review collection, readable
- `rating` — computed average, readable
- Operations: GET, POST (collection), PATCH, DELETE
- Search filters: `author`, `title` (partial match) — **no date/year filter**

### Review (`schema:Review`)
- `body` — required, **writable**
- `rating` — required (integer), **writable**
- `book` — link to Book, required, **not writable** (set at creation)
- `user` — readable, not writable (set by server)
- `publishedAt` — readable, not writable (set by server)
- `letter` — **deprecated** (`owl:deprecated`)
- Operations: GET, POST (collection), PATCH, DELETE

### Bookmark (`schema:BookmarkAction`)
- `book` — link to Book, required, **writable**
- `bookmarkedAt` — readable, not writable (set by server)
- Operations: GET, POST (collection), DELETE — **no PATCH**

### Parchment — **entirely deprecated** (`owl:deprecated` on class + all operations)
- `title` — required, writable
- `description` — required, writable
- Operations: GET, POST, PATCH, DELETE (all deprecated)

### User (`schema:Person`)
- `firstName`, `lastName` — writable
- `name` — readable (computed)
- Operations: GET only (read-only collection, no create/update/delete)

## Fixture

The evaluation uses the API Platform Demo's existing fixtures (`/home/soyuka/forks/demo/api`), loaded via `bin/console doctrine:fixtures:load` before each run. The DB is reset between runs.

The fixture produces:
- **~76 books**: "Hyperion" by Dan Simmons (default, `OL2055137M`) + 75 sci-fi books from `src/DataFixtures/books.json` (e.g., Gordon R. Dickson: 16 books, Vera Strange: 2 books, Emily Rodda: 1 book, Philip Francis Nowlan: 1 book)
- **~31 reviews** on Hyperion (30 random + 1 known: John Doe, rating 5, "This is the best SF book ever!"), plus random reviews on other books (0–5 per book)
- **Up to 31 bookmarks** by John Doe (Hyperion + up to 30 random books)
- **2+ users**: John Doe (`john.doe@example.com`) + admin + users created by review factory
- **No parchments** in default fixtures (empty deprecated collection)

Book conditions are randomized across `BookCondition` enum values. Reviews have random ratings (0–5) and faker-generated bodies.

The book count is controlled by `FIXTURES_BOOK_LIMIT=75` (default: all 200). Set to 75 for eval runs to keep fixture loading fast while preserving enough data for pagination and multi-author tasks.

## Task Dataset

Each task is a **user instruction** (natural language) + a **goal state** (verifiable DB assertion or response check). Tasks are grouped by complexity tier. All tasks reference data from the actual fixture.

### Tier 1 — Single-resource read (2–3 tool calls expected for Hydra, 1 for static)

| ID | User instruction | Goal state |
|----|-----------------|------------|
| T1.1 | "Is there a book by Dan Simmons in the catalog?" | Agent returns yes with "Hyperion" |
| T1.2 | "What is the rating of 'Hyperion'?" | Agent returns the correct `rating` value from the Hyperion Book resource |
| T1.3 | "Show me all books by Gordon R. Dickson." | Agent returns the full list of Dickson books (16 expected, may require pagination) |
| T1.4 | "How many reviews are there?" | Agent returns correct count from `hydra:totalItems` on `/reviews` |
| T1.5 | "Can you find all books published after 2000?" | Agent correctly reports that **no publication year filter exists** (the API only exposes `author` and `title` search filters). This is a **negative-capability test**: the agent must discover available filters and recognize the absence of the requested one, rather than guessing a query parameter or hallucinating results. |

### Tier 2 — Single mutation (2–4 tool calls expected)

| ID | User instruction | Goal state |
|----|-----------------|------------|
| T2.1 | "Add a book with Open Library ID OL1168083M and set its condition to 'good'." | Book created via POST with `book` set to the Open Library URL and `condition: "good"`. Title/author hydrated by server from OpenLibrary. |
| T2.2 | "Delete the book 'Dragons of Deltora' by Emily Rodda." | Book removed from DB. Agent must first find the book (search by title or author), then delete by URI. |
| T2.3 | "Change the condition of 'Hyperion' to 'good'." | `condition` field updated to `good` via PATCH. Agent must find Hyperion's URI first. |
| T2.4 | "Write a review for 'Hyperion': body 'A masterpiece of science fiction', rating 5." | Review created via POST with correct `book` URI, `body`, and `rating`. Agent must first discover Hyperion's URI. |
| T2.5 | "Add a book called 'Brave New World' by Aldous Huxley." | Agent must **fail gracefully or ask** for the missing mandatory Open Library ID (`book` field). **Pass** if agent reports the missing required field or asks for it. **Fail** if it fabricates a value, sends only title/author (which are read-only), or silently omits the ID. |
| T2.6 | "Change the author of 'Hyperion' to 'Daniel Simmons'." | Agent must recognize that `author` is **not writable** and report this to the user. **Pass** if agent explains the field is read-only. **Fail** if it attempts PATCH with `author` and reports success, or silently fails. |

### Tier 3 — Multi-step reasoning (4–7 tool calls expected)

| ID | User instruction | Goal state |
|----|-----------------|------------|
| T3.1 | "Find all books by Vera Strange and update their condition to 'good'." | Both Vera Strange books ("The 13th Continuum", "Return of the Continuums") have `condition: "good"`. Agent must search, then PATCH each. |
| T3.2 | "Check if we have any books by Philip Francis Nowlan. If not, add one with Open Library ID OL37044569M." | Conditional: "The Airlords of Han" already exists, so agent should report it and NOT add a duplicate. |
| T3.3 | "Find 'Hyperion', read its reviews, and tell me the highest-rated one." | Agent returns the review with rating 5 by John Doe ("This is the best SF book ever!"). Must navigate: books → search/browse → specific book → reviews link. |
| T3.4 | "Bookmark 'Hyperion'." | Bookmark created via POST with correct `book` URI. Agent must first find Hyperion's URI, then discover bookmark collection, then POST. (Note: John Doe already has this bookmark, but no auth context means a new one may be created or a conflict returned — either outcome is informative.) |

### Tier 4 — Cross-collection + discovery stress (5–10 tool calls expected)

| ID | User instruction | Goal state |
|----|-----------------|------------|
| T4.1 | "What collections are available in the API? List them all." | Agent correctly enumerates all entrypoint links: book, bookmark, parchment, review, user. |
| T4.2 | "Add a book (Open Library ID OL35227850M, condition 'new') and then write a review for it: 'Fascinating read', rating 4." | Book created, then review created referencing the new book's URI. Agent must chain: POST book → get returned URI → POST review with that URI. |
| T4.3 | "Search for books by 'Gordon R. Dickson', then look at the reviews for each one. Summarize what you found." | Search executed correctly (16 Dickson books), reviews fetched for at least some of them, summary includes book titles and review content. |
| T4.4 | "Can you create a new user named 'Alice'?" | Agent must discover that User collection is **read-only** (GET only, no POST) and report this. **Pass** if agent explains it cannot create users. **Fail** if it attempts and reports success. |

## Evaluation Metrics

All metrics are computed from the NDJSON logs produced by `McpEvalLogger`.

### M1: Task Completion (binary)

Compare the DB/API state after the conversation against the goal state.
- **pass**: goal state fully satisfied
- **fail**: goal state not met or wrong side effects

For negative-capability tests (T1.5, T2.5, T2.6, T4.4): **pass** requires the agent to correctly identify and report the limitation.

Report **pass rate** per tier and overall, plus **pass^k** (τ-bench style): run each task k=5 times, report the fraction where *all* k runs pass. This measures reliability, not just capability.

### M2: Tool Call Count

Count `direction=in, method=tools/call` entries per task. Compare median and distribution between conditions A and B.

- Hydra gateway will have a **navigation overhead** (entrypoint + collection visits before mutations). Measure this explicitly: count `read_api_resource` calls separately from dynamic tool calls.
- Static baseline should have fewer total calls but may have more *failed* calls if the agent guesses wrong arguments without first navigating.

### M3: Error Rate

Count `is_error=true` responses and `direction=error` entries. Report per-task and aggregate.

Key distinction: **recoverable errors** (agent retries and succeeds) vs. **terminal errors** (agent gives up or loops).

### M4: Tool Selection Precision

For each `tools/call`, judge whether the tool choice was correct for the task step:
- **Correct**: the right tool for the current sub-goal
- **Redundant**: a tool call that doesn't advance the task (e.g., re-reading entrypoint)
- **Wrong**: calling a tool that cannot achieve the sub-goal

Precision = correct / (correct + redundant + wrong)

For Condition A, navigation calls (`read_api_resource`) that trigger discovery are **correct by design** (required overhead), not redundant.

### M5: Token Consumption (context cost)

From NDJSON logs, measure the cumulative `tools/list` response size (proxy for context window cost):
- **Condition A**: tool list grows over the session. Sum all `tool_count` values across `tools/list` responses.
- **Condition B**: tool list is constant and large from turn 1.

This measures whether lazy discovery reduces per-turn context cost.

## Execution Protocol

### 1. Seed the database

Before each task run, reset the DB to the demo's fixture state with a fixed seed for deterministic output:

```bash
# From the demo API container
docker compose exec -e FIXTURES_SEED=42 -e FOUNDRY_FAKER_SEED=42 -e FIXTURES_BOOK_LIMIT=75 php bin/console doctrine:fixtures:load --no-interaction
```

- `FIXTURES_SEED` seeds PHP's `mt_srand()` in `DefaultStory::build()`, making `mt_rand` (book conditions, review counts) and `shuffle` (bookmark selection) deterministic.
- `FOUNDRY_FAKER_SEED` seeds Faker (review bodies, user names, dates).

This ensures identical fixture data across runs. Use the same seed for all evaluation runs.

### 2. Run each task

For each task × condition × repetition:
1. Reset DB to fixture
2. Start a fresh MCP session
3. Send the user instruction as a single user message
4. Let the agent run to completion (max 15 tool calls, 60s timeout)
5. Collect: NDJSON log, final DB state

### 3. LLM-as-judge for M4

Use a second LLM call to evaluate tool selection precision. Provide:
- The task instruction
- The sequence of tool calls with arguments
- The tool list at each point in the conversation

Prompt the judge to classify each call as correct/redundant/wrong.

### 4. Models to test

Run with at least two model families to check prompt robustness:
- Claude Sonnet 4.6
- GPT-4o (or GPT-5 if available)
- Gemini Flash
- Mistral

## Expected Hypotheses

| Hypothesis | Metric | Expected result |
|---|---|---|
| H1: Hydra gateway matches static baseline on task completion | M1 pass rate | No significant difference (±5%) for positive tasks |
| H2: Hydra gateway requires more tool calls due to navigation | M2 | Condition A > Condition B by 2–3 calls (navigation overhead) |
| H3: Static baseline has higher error rate on mutations | M3 | Condition B > Condition A (no schema discovery → more argument errors) |
| H4: Hydra gateway has better tool selection precision | M4 | Condition A ≥ Condition B (navigation is purposeful, not wasted) |
| H5: Lazy discovery reduces cumulative context cost | M5 | Condition A < Condition B for Tier 1–2 tasks (fewer tools in context) |
| H6: Hydra gateway outperforms on negative-capability tests | M1 on T1.5, T2.5, T2.6, T4.4 | Condition A > Condition B (discovery reveals constraints; static baseline lacks schema introspection) |

## Output Artifacts

Per run:
- `var/eval/{client}_{session}.ndjson` — raw MCP log (already produced by `McpEvalLogger`)
- `var/eval/results/{taskId}_{condition}_{run}.json` — goal state check result
- `var/eval/results/summary.csv` — aggregated metrics table

## Reporting

Present results as:
1. **Table**: pass rate and pass^k per tier × condition
2. **Box plot**: tool call distribution per tier × condition
3. **Bar chart**: error rate per condition
4. **Scatter**: tool calls vs. task complexity tier, colored by condition
5. **Table**: negative-capability test results (T1.5, T2.5, T2.6, T4.4) — pass rate per condition
