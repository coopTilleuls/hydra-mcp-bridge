<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Serializable metadata for a discovered affordance.
 * Used to persist dynamic tools across HTTP requests.
 *
 * Serialized via get_object_vars() / named argument spread — no manual toArray/fromArray needed.
 */
final class StoredAffordance
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly string $type, // 'search' or 'operation'
        public readonly string $uri,
        public readonly array $paramNames = [], // for search tools
        public readonly ?string $method = null, // for operation tools
    ) {
    }
}
