<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use Mcp\Schema\Extension\ExtensionIdentifier;
use Mcp\Schema\Extension\ExtensionInterface;

/**
 * Draft extension of modelcontextprotocol/progressive-disclosure-wg#19: one group per documented Hydra class.
 * Its handlers are tagged mcp.request_handler by autoconfiguration, not returned here.
 */
final class GroupsExtension implements ExtensionInterface
{
    public const string ID = 'io.modelcontextprotocol/groups';

    public function getId(): ExtensionIdentifier
    {
        return new ExtensionIdentifier(self::ID);
    }

    public function getCapabilities(): array
    {
        return [];
    }

    public function getMessages(): array
    {
        return [ListGroupsRequest::class, GetGroupRequest::class];
    }

    public function getRequestHandlers(): iterable
    {
        return [];
    }
}
