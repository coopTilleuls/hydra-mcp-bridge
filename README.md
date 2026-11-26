# Hydra MCP Bridge

A Hydra MCP bridge for agentic tool discovery. A dynamic
[Model Context Protocol (MCP)](https://modelcontextprotocol.io) gateway that bridges
Hydra/HATEOAS APIs with MCP clients. It exposes a single static tool
(`read_api_resource`) and **discovers additional tools at runtime** by parsing Hydra
hypermedia controls (JSON-LD), as the agent navigates the API.

This repository accompanies the paper and contains three parts:

- **Paper** — *APIs We Built Are Meant for Computers: How Do We Expose Hypermedia APIs to LLMs?*
- **Experiment** — a reproducible comparative evaluation (Hydra gateway vs. static OpenAPI baseline)
- **Prototype** — a working gateway built on Symfony + API Platform + FrankenPHP

## Paper

> Antoine Bluchet, Kévin Dunglas. *APIs We Built Are Meant for Computers: How Do We
> Expose Hypermedia APIs to LLMs?*

**HAL:** https://hal.science/hal-05630480

MCP separates application-controlled `Resources` (read-only context) from model-controlled
`Tools` (callable actions). REST/HATEOAS APIs (Hydra, JSON:API, HAL, Solid) break this
assumption: available actions are not static, they are discovered at runtime inside resource
content. Neither static endpoint-to-tool mapping nor a generic API-call tool preserves
hypermedia discovery. We propose a **Dynamic Gateway Architecture** that resolves this
mismatch through three discovery phases. In a comparative evaluation against a static OpenAPI
baseline (19 tasks, two models, *k*=3 runs), agents reach 100% completion on all 15
positive-capability tasks while the gateway reduces tool-schema overhead from **77% to 2%**
of input context.

LaTeX sources and figures live in [`paper/`](paper/).

## How it works — three-phase lazy discovery

The gateway discovers tools lazily as the MCP client navigates the Hydra API:

1. **Entrypoint** (`@type: Entrypoint`) — returns collection links as awareness, caches the
   `docs.jsonld` URL. No tools registered.
2. **Collection visit** (`@type` contains `hydra:Collection`) — resolves the managed class,
   fetches `docs.jsonld` (cached), extracts `hydra:supportedClass` operations → registers
   typed CRUD tools (`create_`, `read_`, `update_`, `replace_`, `delete_`) and `search_` tools.
3. **Inline overrides** — `hydra:operation` nodes in any resource response override or add
   tools, reflecting per-resource state.

A single static tool whose description embeds navigational guidance bootstraps the whole
process — no system prompt required.

See [`AGENTS.md`](AGENTS.md) for the full architecture and component reference.

## Quick start

```bash
docker compose up -d          # FrankenPHP on https://localhost:8000
docker compose exec php bash  # shell into the container
```

The MCP endpoint is `POST /mcp` (HTTP transport) or stdio. Point it at an upstream Hydra API
with `HYDRA_API_BASE_URL` (default: `https://localhost`).

## Tests

```bash
bin/phpunit          # PHPUnit unit tests
bash tests/e2e.sh    # E2E tests (full 3-phase discovery over HTTP)
```

## Experiment

The reproducible benchmark (19 tasks, 4 tiers, two models, *k*=3 runs, two conditions) lives
in [`eval/`](eval/). See [`eval/README.md`](eval/README.md) for the runner and
[`EVAL_PROTOCOL.md`](EVAL_PROTOCOL.md) for the protocol.

```bash
node eval/run.mjs --condition=A --tier=1 --runs=3   # Hydra gateway
node eval/run.mjs --condition=B --tier=1 --runs=3   # OpenAPI baseline
node eval/analyze.mjs                               # aggregate results
```

## Stack

PHP 8.4 · Symfony 8.0 · API Platform 4.3 · `symfony/mcp-bundle` · FrankenPHP (Caddy) ·
`sweetrdf/json-ld`. No database — a stateless gateway (session state is file-based).
