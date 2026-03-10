<?php

declare(strict_types=1);

namespace App\JsonLd;

/**
 * Wraps a single expanded JSON-LD node for fluent navigation.
 *
 * After expansion, all property names are full IRIs and values are arrays.
 * This class provides typed accessors that eliminate the repetitive
 * `$node->{$iri}[0]->{'@value'}` patterns.
 */
final class JsonLdNode
{
    public function __construct(private readonly \stdClass $data)
    {
    }

    /**
     * Get the first scalar @value from an expanded property.
     *
     * Given: [{"@value": "Book"}], returns "Book"
     */
    public function value(string $iri): mixed
    {
        $values = $this->resolveProperty($iri);
        if ([] === $values) {
            return null;
        }

        $first = $values[0] ?? null;

        return $first instanceof \stdClass ? ($first->{'@value'} ?? null) : $first;
    }

    /**
     * Get the first @id from an expanded property.
     *
     * Given: [{"@id": "https://localhost/#Book"}], returns "https://localhost/#Book"
     */
    public function id(string $iri): ?string
    {
        $values = $this->resolveProperty($iri);
        if ([] === $values) {
            return null;
        }

        $first = $values[0] ?? null;

        return $first instanceof \stdClass ? ($first->{'@id'} ?? null) : null;
    }

    /**
     * Get the @id of this node.
     */
    public function nodeId(): ?string
    {
        return $this->data->{'@id'} ?? null;
    }

    /**
     * Get @type array of this node.
     *
     * @return string[]
     */
    public function types(): array
    {
        return $this->data->{'@type'} ?? [];
    }

    /**
     * Get all items for a property as JsonLdNode[].
     *
     * @return JsonLdNode[]
     */
    public function all(string $iri): array
    {
        return array_map(
            static fn (\stdClass $item) => new self($item),
            $this->resolveProperty($iri),
        );
    }

    /**
     * Get the first node for a property.
     */
    public function first(string $iri): ?self
    {
        $values = $this->resolveProperty($iri);

        return isset($values[0]) ? new self($values[0]) : null;
    }

    /**
     * Escape hatch for raw stdClass access.
     */
    public function raw(): \stdClass
    {
        return $this->data;
    }

    /**
     * Resolve a property from an expanded node, with fallback to local name matching.
     *
     * When a document's @context uses @vocab, compact terms expand to the @vocab
     * namespace instead of the expected Hydra IRI. This method first tries an exact
     * match, then falls back to matching by local name.
     */
    private function resolveProperty(string $iri): array
    {
        if (isset($this->data->{$iri})) {
            return $this->data->{$iri};
        }

        $localName = self::localName($iri);
        if (null === $localName) {
            return [];
        }

        foreach (get_object_vars($this->data) as $key => $values) {
            if ('@' === ($key[0] ?? '')) {
                continue;
            }
            if (self::localName($key) === $localName) {
                return $values;
            }
        }

        return [];
    }

    /**
     * Extract the local name (fragment or last path segment) from an IRI.
     */
    private static function localName(string $iri): ?string
    {
        if (str_contains($iri, '#')) {
            return substr($iri, strrpos($iri, '#') + 1);
        }

        $path = parse_url($iri, \PHP_URL_PATH);
        if (null !== $path && '' !== $path) {
            return basename($path);
        }

        return null;
    }
}
