<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use Mcp\Schema\JsonRpc\ResultInterface;

final readonly class ListGroupsResult implements ResultInterface
{
    /**
     * @param list<Group> $groups
     */
    public function __construct(
        public array $groups,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['groups' => $this->groups, 'ttlMs' => 0, 'cacheScope' => 'private'];
    }
}
