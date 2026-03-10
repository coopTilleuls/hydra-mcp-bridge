# Architecture Diagram (Mermaid)

Convert to TikZ/PDF for inclusion in the paper. Use `mmdc` (mermaid-cli) to render:
```bash
mmdc -i diagram.md -o diagram.pdf -t neutral
```

## Sequence Diagram: Three-Phase Discovery

```mermaid
sequenceDiagram
    participant Agent as AI Agent
    participant Gateway as MCP Gateway
    participant API as Hydra API

    rect rgb(235, 245, 255)
    Note over Agent,API: Phase 1 — Entrypoint Navigation (Awareness)
    Agent->>Gateway: tools/call("read_hydra_resource", {uri: "/"})
    Gateway->>API: GET /
    API-->>Gateway: Entrypoint JSON-LD + Link: <docs.jsonld>
    Note over Gateway: Cache docs.jsonld URL
    Gateway-->>Agent: Entrypoint content (collection links)
    Note over Agent: Available tools: read_hydra_resource
    end

    rect rgb(235, 255, 235)
    Note over Agent,API: Phase 2 — Collection Navigation (CRUD + Search Discovery)
    Agent->>Gateway: tools/call("read_hydra_resource", {uri: "/books"})
    Gateway->>API: GET /books
    API-->>Gateway: hydra:Collection (members, hydra:search)
    Note over Gateway: Resolve managed class<br/>(memberAssertion or @context)
    Gateway->>API: GET /docs.jsonld (first time only)
    API-->>Gateway: hydra:ApiDocumentation
    Note over Gateway: Extract supportedOperation<br/>+ Entrypoint property operations<br/>for Book class
    Gateway-->>Agent: notifications/tools/list_changed
    Gateway-->>Agent: Collection JSON-LD
    Note over Agent: New tools: create_book, read_book,<br/>update_book, replace_book,<br/>delete_book, search_books
    end

    rect rgb(255, 245, 235)
    Note over Agent,API: Phase 3 — Inline Operation Overrides
    Agent->>Gateway: tools/call("read_hydra_resource", {uri: "/orders/42"})
    Gateway->>API: GET /orders/42
    API-->>Gateway: Resource with hydra:operation (GET, PATCH only)
    Note over Gateway: Register inline operations<br/>(may differ from class-level defaults)
    Gateway-->>Agent: Resource JSON-LD
    Note over Agent: Server signals: only GET + PATCH<br/>valid for this resource state
    end

    rect rgb(245, 235, 255)
    Note over Agent,API: Dynamic Tool Execution
    Agent->>Gateway: tools/call("create_book",<br/>{title: "1984", author: "Orwell"})
    Gateway->>API: POST /books (application/ld+json)
    API-->>Gateway: 201 Created
    Gateway-->>Agent: Created resource JSON-LD
    end
```

## Component Diagram

```mermaid
graph TD
    subgraph "MCP Client (AI Agent)"
        A[LLM + System Prompt]
    end

    subgraph "MCP Gateway"
        B[read_hydra_resource<br/><i>static tool</i>]
        C[Dynamic Tool Registry]
        D[Hydra Response Parser]
        E[Affordance Store<br/><i>per-session</i>]
        F[Dynamic Tool Handler]

        B -->|"Phase 1-3:<br/>fetch + parse"| D
        D -->|"discovered<br/>affordances"| C
        C -->|"persist"| E
        F -->|"lookup config"| E
    end

    subgraph "Hydra API"
        G[Entrypoint /]
        H[Collections /books]
        I[Resources /books/1]
        J[docs.jsonld]
    end

    A -->|"tools/call"| B
    A -->|"tools/call"| F
    B -->|"GET"| G
    B -->|"GET"| H
    B -->|"GET"| I
    B -->|"GET"| J
    F -->|"POST/PUT/PATCH/DELETE"| H
    F -->|"POST/PUT/PATCH/DELETE"| I
    C -->|"notifications/<br/>tools/list_changed"| A
```
