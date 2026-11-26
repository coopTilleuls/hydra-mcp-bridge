<?php

declare(strict_types=1);

namespace App\Mcp;

final readonly class DescribedResource
{
    /**
     * @param list<OperationDescription> $operations       operations on $uri itself, one per method
     * @param list<OperationDescription> $memberOperations operations on each member URI of a collection
     */
    public function __construct(
        public string $uri,
        public \stdClass $resource,
        public array $operations,
        public array $memberOperations = [],
    ) {
    }

    public function operation(string $method): ?OperationDescription
    {
        foreach ($this->operations as $operation) {
            if ($operation->method === $method) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $described = [
            'resource' => $this->resource,
            'operations' => array_map(fn (OperationDescription $operation): array => $operation->toArray($this->uri), $this->operations),
        ];

        if ([] !== $this->memberOperations) {
            $described['memberOperations'] = array_map(static fn (OperationDescription $operation): array => $operation->toArray(), $this->memberOperations);
        }

        return $described;
    }
}
