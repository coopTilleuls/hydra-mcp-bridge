<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use Mcp\Schema\JsonRpc\ResultInterface;
use Mcp\Schema\Tool;

final readonly class GetGroupResult implements ResultInterface
{
    /**
     * @param list<Tool> $tools
     */
    public function __construct(
        public array $tools,
    ) {
    }

    public function jsonSerialize(): array
    {
        return ['tools' => $this->tools, 'ttlMs' => 0, 'cacheScope' => 'private'];
    }
}
