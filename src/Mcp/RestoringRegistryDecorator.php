<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\Discovery\DiscoveryState;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\Resource;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;

/**
 * Decorates the MCP Registry to lazily restore dynamically discovered tools
 * from persistent storage. This ensures tools survive PHP process boundaries
 * and the DiscoveryLoader's clear() during server build.
 */
final class RestoringRegistryDecorator implements RegistryInterface
{
    private bool $restored = false;

    public function __construct(
        private readonly RegistryInterface $inner,
        private readonly DynamicToolRegistry $dynamicToolRegistry,
    ) {
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $this->restoreIfNeeded();

        return $this->inner->getTools($limit, $cursor);
    }

    public function getTool(string $name): ToolReference
    {
        $this->restoreIfNeeded();

        return $this->inner->getTool($name);
    }

    public function hasTools(): bool
    {
        $this->restoreIfNeeded();

        return $this->inner->hasTools();
    }

    public function registerTool(Tool $tool, callable|array|string $handler, bool $isManual = false): void
    {
        $this->inner->registerTool($tool, $handler, $isManual);
    }

    public function registerResource(Resource $resource, callable|array|string $handler, bool $isManual = false): void
    {
        $this->inner->registerResource($resource, $handler, $isManual);
    }

    public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = [], bool $isManual = false): void
    {
        $this->inner->registerResourceTemplate($template, $handler, $completionProviders, $isManual);
    }

    public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = [], bool $isManual = false): void
    {
        $this->inner->registerPrompt($prompt, $handler, $completionProviders, $isManual);
    }

    public function clear(): void
    {
        $this->restored = false;
        $this->inner->clear();
    }

    public function getDiscoveryState(): DiscoveryState
    {
        return $this->inner->getDiscoveryState();
    }

    public function setDiscoveryState(DiscoveryState $state): void
    {
        $this->restored = false;
        $this->inner->setDiscoveryState($state);
    }

    public function hasResources(): bool
    {
        return $this->inner->hasResources();
    }

    public function getResources(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResources($limit, $cursor);
    }

    public function getResource(string $uri, bool $includeTemplates = true): ResourceReference|ResourceTemplateReference
    {
        return $this->inner->getResource($uri, $includeTemplates);
    }

    public function hasResourceTemplates(): bool
    {
        return $this->inner->hasResourceTemplates();
    }

    public function getResourceTemplates(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getResourceTemplates($limit, $cursor);
    }

    public function getResourceTemplate(string $uriTemplate): ResourceTemplateReference
    {
        return $this->inner->getResourceTemplate($uriTemplate);
    }

    public function hasPrompts(): bool
    {
        return $this->inner->hasPrompts();
    }

    public function getPrompts(?int $limit = null, ?string $cursor = null): Page
    {
        return $this->inner->getPrompts($limit, $cursor);
    }

    public function getPrompt(string $name): PromptReference
    {
        return $this->inner->getPrompt($name);
    }

    private function restoreIfNeeded(): void
    {
        if ($this->restored) {
            return;
        }
        $this->restored = true;

        $this->dynamicToolRegistry->restoreFromStore();
    }
}
