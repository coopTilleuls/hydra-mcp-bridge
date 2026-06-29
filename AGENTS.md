# AGENTS.md

This file provides guidance to coding agents when working with code in this repository.

## What This Is

A dynamic MCP (Model Context Protocol) gateway that bridges Hydra/HATEOAS APIs with MCP clients. It exposes one static tool (`read_hydra_resource`) and dynamically discovers additional tools at runtime by parsing Hydra hypermedia controls (JSON-LD).

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

**Phase 1 — Entrypoint (`@type: "Entrypoint"`):** Caches the `docs.jsonld` URL from the response `Link` header. No tools registered. Returns collection links as awareness.

**Phase 2 — Collection visit (`@type` contains `hydra:Collection`):** Resolves the managed class (via `hydra:memberAssertion` → `members[0][@type]` → `@context` URL fallback), fetches `docs.jsonld` (cached after first fetch), extracts `hydra:supportedClass` operations → registers typed CRUD tools (`create_`, `read_`, `update_`, `replace_`, `delete_`). Also discovers `hydra:search` → registers `search_` tools.

**Phase 3 — Inline overrides:** `hydra:operation` nodes in any resource response override or add tools discovered in Phase 2. Always runs.

After registering new tools, `Fiber::suspend()` sends a `ToolListChangedNotification` to the MCP client.

### Request Flow

1. MCP client calls `read_hydra_resource(uri)` → `ReadHydraResourceProcessor`
2. Processor fetches the upstream Hydra resource, expands JSON-LD via `Expander`
3. `HydraResponseParser` extracts affordances based on response type (entrypoint/collection/resource)
4. `DynamicToolRegistry` registers new tools in the MCP `RegistryInterface` with handler string `DynamicToolRegistry::HANDLER`
5. When client calls a dynamic tool → `DynamicToolRequestHandler` (tagged `mcp.request_handler`) intercepts before the SDK's `CallToolHandler`, looks up `StoredAffordance` from `AffordanceStore`, executes the upstream HTTP call

### Key Components

- **`ReadHydraResourceProcessor`** — The only static tool's processor. Orchestrates discovery phases.
- **`HydraResponseParser`** — Stateless parser extracting affordances from expanded JSON-LD. Handles `hydra:search`, `hydra:operation`, and `hydra:supportedClass` operations from `docs.jsonld`.
- **`DynamicToolRegistry`** — Registers `StoredAffordance` as MCP tools. Uses a plain string handler constant — never container-resolved.
- **`DynamicToolRequestHandler`** — `RequestHandlerInterface` that intercepts dynamic tool calls. Routes `search` → GET with query params, `operation` → HTTP method with JSON-LD body.
- **`AffordanceStore`** — File-based persistence per MCP session (`var/cache/dev/mcp/{sessionId}.*`). Stores affordances, docs URL, and cached expanded docs.
- **`RestoringRegistryDecorator`** — Decorates `mcp.registry` to lazily restore tools from `AffordanceStore` across PHP process boundaries.
- **`Expander` + `Vocab`** — JSON-LD expansion via `sweetrdf/json-ld`. `Vocab` holds IRI constants. `Expander` has static helpers for navigating expanded nodes (`value()`, `id()`, `all()`, `first()`, `types()`).

### DI Wiring Notes

- `RestoringRegistryDecorator` is excluded from auto-registration in `services.yaml` and manually wired as a decorator of `mcp.registry` with `decoration_priority: 10`
- `hydra.client` is a scoped Symfony HTTP client configured in `config/packages/framework.yaml` with `base_uri` from `HYDRA_API_BASE_URL`
- MCP config in `config/packages/mcp.yaml`: HTTP+stdio transports, file-based sessions in `%kernel.cache_dir%/mcp`

## Stack

- PHP 8.4, Symfony 8.0, API Platform 4.3 (dev), `symfony/mcp-bundle` 0.6
- FrankenPHP (Docker) with Caddy
- `sweetrdf/json-ld` for JSON-LD expansion
- No database — this is a stateless gateway (session state is file-based in cache dir)
