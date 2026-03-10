<?php

declare(strict_types=1);

namespace App\JsonLd;

/**
 * Wraps the top-level expanded JSON-LD result (array of nodes).
 * Eliminates the `$expanded[0] ?? []` pattern repeated everywhere.
 */
final class JsonLdDocument
{
    /**
     * @param JsonLdNode[] $nodes
     */
    public function __construct(private readonly array $nodes)
    {
    }

    public function first(): ?JsonLdNode
    {
        return $this->nodes[0] ?? null;
    }

    /**
     * @return JsonLdNode[]
     */
    public function all(): array
    {
        return $this->nodes;
    }

    public function isEmpty(): bool
    {
        return [] === $this->nodes;
    }

    /**
     * Convert raw expanded array (from sweetrdf/json-ld) into a JsonLdDocument.
     *
     * @param array<\stdClass> $expanded
     */
    public static function fromExpanded(array $expanded): self
    {
        return new self(array_map(
            static fn (\stdClass $node) => new JsonLdNode($node),
            $expanded,
        ));
    }

    /**
     * Convert back to raw stdClass array for serialization (e.g., cache storage).
     *
     * @return array<\stdClass>
     */
    public function toExpandedArray(): array
    {
        return array_map(
            static fn (JsonLdNode $node) => $node->raw(),
            $this->nodes,
        );
    }
}
