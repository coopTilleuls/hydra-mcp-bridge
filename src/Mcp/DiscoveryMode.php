<?php

declare(strict_types=1);

namespace App\Mcp;

enum DiscoveryMode: string
{
    /** Typed tools registered per session as resources are read, announced with list_changed. */
    case Dynamic = 'dynamic';

    /** A fixed tool list: reads describe the available operations, a second tool invokes them by URI. */
    case Static = 'static';

    /** read_api_resource alone in tools/list, the typed tools of each class served as groups (io.modelcontextprotocol/groups). */
    case Groups = 'groups';
}
