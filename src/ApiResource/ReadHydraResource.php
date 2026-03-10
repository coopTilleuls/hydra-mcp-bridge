<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\McpTool;
use App\State\ReadHydraResourceProcessor;

#[McpTool(
    name: 'read_api_resource',
    description: 'Navigate to a Hydra API resource by URI. Returns JSON-LD content and may discover new tools from hypermedia controls. Use this to explore the API: start with "/" to see the entrypoint, then follow links.',
    processor: ReadHydraResourceProcessor::class,
    structuredContent: false,
)]
class ReadHydraResource
{
    public string $uri;
}
