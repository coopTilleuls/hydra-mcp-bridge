<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;

final class GetGroupRequest extends Request
{
    public function __construct(
        public readonly string $name,
    ) {
    }

    public static function getMethod(): string
    {
        return 'groups/get';
    }

    protected static function fromParams(?array $params): static
    {
        if (!isset($params['name']) || !\is_string($params['name']) || '' === $params['name']) {
            throw new InvalidArgumentException('Missing or invalid "name" parameter for groups/get.');
        }

        return new self($params['name']);
    }

    protected function getParams(): array
    {
        return ['name' => $this->name];
    }
}
