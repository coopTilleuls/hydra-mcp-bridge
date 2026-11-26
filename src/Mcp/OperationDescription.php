<?php

declare(strict_types=1);

namespace App\Mcp;

final readonly class OperationDescription
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema of the payload (request body, or query parameters for GET)
     */
    public function __construct(
        public string $method,
        public ?string $title,
        public string $description,
        public array $inputSchema,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(?string $uri = null): array
    {
        return array_filter([
            'method' => $this->method,
            'uri' => $uri,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ], static fn (mixed $value): bool => null !== $value);
    }
}
