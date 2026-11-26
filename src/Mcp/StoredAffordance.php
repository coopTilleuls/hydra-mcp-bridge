<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Serializable metadata for a discovered affordance, persisted across HTTP requests via get_object_vars()
 * and named argument spread.
 *
 * It never holds a target discovered on one resource for use on another. The target is resolved at call time:
 * the $link value read from the parent IRI (argument $parentParam, else the fixed $uri), the "uri" argument
 * when the schema declares it (one of $advertisedBy when set), the "collection" argument of a search when
 * declared, else the fixed $uri.
 */
final class StoredAffordance
{
    /**
     * @param list<string> $paramNames   search variables
     * @param list<string> $advertisedBy resources whose representation advertised this operation inline
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $type, // 'search' or 'operation'
        public readonly ?string $uri = null,
        public readonly array $paramNames = [],
        public readonly ?string $method = null,
        public readonly ?string $link = null,
        public readonly ?string $parentParam = null,
        public readonly array $advertisedBy = [],
    ) {
    }

    public function declaresArgument(string $name): bool
    {
        return \array_key_exists($name, (array) ($this->inputSchema['properties'] ?? []));
    }

    public function withAdvertisedBy(string $resource): self
    {
        if (\in_array($resource, $this->advertisedBy, true)) {
            return $this;
        }

        return new self(...['advertisedBy' => [...$this->advertisedBy, $resource]] + get_object_vars($this));
    }

    public function sameBinding(self $other): bool
    {
        return [$this->type, $this->method, $this->uri, $this->link, $this->parentParam, [] === $this->advertisedBy]
            === [$other->type, $other->method, $other->uri, $other->link, $other->parentParam, [] === $other->advertisedBy];
    }
}
