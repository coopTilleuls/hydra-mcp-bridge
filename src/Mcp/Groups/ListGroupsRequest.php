<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use Mcp\Exception\InvalidArgumentException;
use Mcp\Schema\JsonRpc\Request;

final class ListGroupsRequest extends Request
{
    public function __construct(
        public readonly ?string $cursor = null,
    ) {
    }

    public static function getMethod(): string
    {
        return 'groups/list';
    }

    protected static function fromParams(?array $params): static
    {
        if (isset($params['cursor']) && !\is_string($params['cursor'])) {
            throw new InvalidArgumentException('Invalid "cursor" parameter for groups/list.');
        }

        return new self($params['cursor'] ?? null);
    }

    protected function getParams(): ?array
    {
        return null === $this->cursor ? null : ['cursor' => $this->cursor];
    }
}
