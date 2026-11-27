<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

final readonly class Group implements \JsonSerializable
{
    public function __construct(
        public string $name,
        public string $title,
        public string $description,
        public string $classId,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'title' => $this->title, 'description' => $this->description];
    }
}
