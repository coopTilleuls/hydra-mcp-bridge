# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## What This Is

A dynamic MCP (Model Context Protocol) gateway that bridges Hydra/HATEOAS APIs with MCP clients. It exposes one static tool (`read_api_resource`) and dynamically discovers additional tools at runtime by parsing Hydra hypermedia controls (JSON-LD).

## Commands

### Development
```bash
docker compose up -d          # Start dev environment (FrankenPHP on https://localhost:8000)
docker compose exec php bash  # Shell into the container
bin/console cache:clear       # Clear Symfony cache
```

### Tests
```bash
bin/phpunit                                     # All PHPUnit tests
bin/phpunit tests/Mcp/HydraResponseParserTest.php   # Single test file
bin/phpunit --filter testMethodName              # Single test method
bash tests/e2e.sh                               # E2E tests (requires gateway on :8000 + demo API on https://localhost)
```

The E2E tests (`tests/e2e.sh`) are the primary integration tests — they exercise the full 3-phase discovery model over HTTP using curl against a running gateway + upstream Hydra API.

### Environment
- `HYDRA_API_BASE_URL` — upstream Hydra API base URL (default: `https://localhost`)
- MCP endpoint: `POST /mcp` (HTTP transport) or stdio

## Architecture

### Three-Phase Lazy Discovery

The gateway discovers tools lazily as the MCP client navigates the Hydra API:

**Phase 1 — Entrypoint (`@type: "Entrypoint"`):** Caches the `docs.jsonld` URL from the response `Link` header and the entrypoint links (`book` → `/books`). No tools registered. Returns collection links as awareness.

**Phase 2 — Collection visit (`@type` contains `hydra:Collection`):** Resolves the managed class (via `hydra:memberAssertion` → `members[0][@type]` → `@context` URL fallback), fetches `docs.jsonld` (cached after first fetch), and the entrypoint named by `hydra:entrypoint` when it was not visited. Registers typed tools named from the operation `hydra:title`: the managed class's `supportedOperation`, and the `supportedOperation` of every `hydra:Link` property whose range is the managed class or whose domain is it (Hydra §7: "the target of the Hydra link"). Also discovers `hydra:search` → registers `search_` tools.

**Phase 3 — Inline overrides:** `hydra:operation` nodes in any resource response override or add tools discovered in Phase 2. Always runs.

### Tool Binding

A tool is identified by its name and never carries a target found on one resource for use on another (an eval run once posted a review for a new book on the book whose reviews were read first). `StoredAffordance` holds how the target is found; `DynamicToolRequestHandler::target()` resolves it at call time:

| Affordance | Arguments | Target |
|---|---|---|
| Class `supportedOperation` | `uri` | the given resource |
| `#Entrypoint/x` link operation | body | the entrypoint's `x` value (`uri`); not registered when the entrypoint has no `x` |
| Other `hydra:Link` operation (`#Book/reviews`) | `book` (the link's domain in snake_case, `_iri` suffix if the body has that name) + body | GET the parent, read `reviews` from its compact JSON-LD (`link`, `parentParam`) |
| `hydra:search` on an entrypoint collection | template variables | that collection (`uri`) |
| `hydra:search` elsewhere | `collection` + template variables | the given collection |
| Inline `hydra:operation` | `uri` | the given resource, only if it is one of `advertisedBy` (resources whose representation listed it); seeing it on another resource extends `advertisedBy` |

The `uri` argument is only honored when the tool's schema declares it, and only declared body properties are sent. A second affordance with an existing name but another binding is ignored with a warning.

After registering new tools, `Fiber::suspend()` sends a `ToolListChangedNotification` to the MCP client.

### Static Mode (MCP 2026-07-28)

2026-07-28 forbids a per-connection tool list and removes sessions, so those requests get a fixed `tools/list`: `read_api_resource` and `invoke_api_operation`. `ResourceDescriber` returns the resource with its `operations` (class-level from `docs.jsonld`, replaced by inline `hydra:operation` when present, `hydra:search` as `GET`) and, for collections, `memberOperations`. `InvokeApiOperationProcessor` re-describes the target URI, validates the payload against the operation schema, then calls upstream. `docs.jsonld` is cached app-wide by URL (`ApiDocumentation`). `DiscoveryModeResolver` picks the mode: static for 2026-07-28, `HYDRA_LEGACY_DISCOVERY` (`dynamic` default) for older clients.

### Request Flow

1. MCP client calls `read_api_resource(uri)` → `ReadHydraResourceProcessor`
2. Processor fetches the upstream Hydra resource, expands JSON-LD via `Expander`
3. `HydraResponseParser` extracts affordances based on response type (entrypoint/collection/resource)
4. `DynamicToolRegistry` registers new tools in the MCP `RegistryInterface` with handler string `DynamicToolRegistry::HANDLER`
5. When client calls a dynamic tool → `DynamicToolRequestHandler` (tagged `mcp.request_handler`) intercepts before the SDK's `CallToolHandler`, looks up `StoredAffordance` from `AffordanceStore`, executes the upstream HTTP call

### Key Components

- **`ReadHydraResourceProcessor`** — The only static tool's processor. Orchestrates discovery phases.
- **`HydraResponseParser`** — Stateless parser extracting affordances from expanded JSON-LD. Handles `hydra:search`, `hydra:operation`, and `hydra:supportedClass` operations from `docs.jsonld`.
- **`DynamicToolRegistry`** — Registers `StoredAffordance` as MCP tools. Uses a plain string handler constant — never container-resolved.
- **`DynamicToolRequestHandler`** — `RequestHandlerInterface` that intercepts dynamic tool calls. Resolves the target (see Tool Binding), then routes `search` → GET with query params, `operation` → HTTP method with JSON-LD body.
- **`AffordanceStore`** — File-based persistence per MCP session (`var/cache/dev/mcp/{sessionId}.*`). Stores affordances, docs URL, entrypoint links, and cached expanded docs.
- **`RestoringRegistryDecorator`** — Decorates `mcp.registry` to lazily restore tools from `AffordanceStore` across PHP process boundaries.
- **`Expander` + `Vocab`** — JSON-LD expansion via `sweetrdf/json-ld`. `Vocab` holds IRI constants. `Expander` has static helpers for navigating expanded nodes (`value()`, `id()`, `all()`, `first()`, `types()`).

### DI Wiring Notes

- `RestoringRegistryDecorator` is excluded from auto-registration in `services.yaml` and manually wired as a decorator of `mcp.registry` with `decoration_priority: 10`
- The MCP registry is shared by every request a FrankenPHP worker serves. `DynamicToolRegistry`, `RestoringRegistryDecorator` and `AffordanceStore` implement `ResetInterface`: dynamic tools are unregistered and caches cleared between requests, then restored from the current session only. Without it a new session inherits tools (with stale URIs) discovered by others. Covered by `testDynamicToolsDoNotLeakAcrossSessionsInWorkerMode` (`disableReboot()` mimics the worker).
- `hydra.client` is a scoped Symfony HTTP client configured in `config/packages/framework.yaml` with `base_uri` from `HYDRA_API_BASE_URL`
- `hydra.client` is decorated by `App\Http\SameOriginHttpClient`, which throws a `TransportException` before any request when the URL resolves to another scheme, host or port than `HYDRA_API_BASE_URL` (ScopingHttpClient lets absolute URLs through). Every consumer gets it; tests replacing `hydra.client` in the container bypass it, so mock `hydra.client.uri_template` (the decorated service) to keep the guard.
- MCP config in `config/packages/mcp.yaml`: HTTP+stdio transports, file-based sessions in `%kernel.cache_dir%/mcp`

## Stack

- PHP 8.4, Symfony 8.0, API Platform 4.3 (dev), `symfony/mcp-bundle` 0.6
- FrankenPHP (Docker) with Caddy
- `sweetrdf/json-ld` for JSON-LD expansion
- No database — this is a stateless gateway (session state is file-based in cache dir)
