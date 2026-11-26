<?php

declare(strict_types=1);

namespace App\Mcp;

use App\ApiResource\InvokeApiOperation;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ResourceReference;
use Mcp\Capability\Registry\ResourceTemplateReference;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Capability\RegistryInterface;
use Mcp\Exception\ToolNotFoundException;
use Mcp\Schema\Page;
use Mcp\Schema\Prompt;
use Mcp\Schema\ResourceDefinition;
use Mcp\Schema\ResourceTemplate;
use Mcp\Schema\Tool;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Decorates the MCP Registry to lazily restore dynamically discovered tools
 * from persistent storage. This ensures tools survive PHP process boundaries
 * and the DiscoveryLoader's clear() during server build.
 *
 * Each discovery mode only sees its own tools: the static mode never the dynamic ones,
 * the dynamic mode never the invocation tool.
 */
final class RestoringRegistryDecorator implements RegistryInterface, ResetInterface
{
    private bool $restored = false;

    public function __construct(
        private readonly RegistryInterface $inner,
        private readonly DynamicToolRegistry $dynamicToolRegistry,
        private readonly DiscoveryModeResolver $modeResolver,
    ) {
    }

    public function getTools(?int $limit = null, ?string $cursor = null): Page
    {
        $this->restoreIfNeeded();
        $page = $this->inner->getTools($limit, $cursor);

        return new Page(array_filter($page->references, fn (Tool $tool): bool => $this->isVisible($tool->name)), $page->nextCursor);
    }

    public function getTool(string $name): ToolReference
    {
        $this->restoreIfNeeded();

        return $this->isVisible($name) ? $this->inner->getTool($name) : throw new ToolNotFoundException($name);
    }

    public function hasTools(): bool
    {
        $this->restoreIfNeeded();

        return [] !== $this->getTools()->references;
    }

    public function registerTool(Tool $tool, callable|array|string $handler): ToolReference
    {
        return $this->inner->registerTool($tool, $handler);
    }

    public function registerResource(ResourceDefinition $resource, callable|array|string $handler): ResourceReference
    {
        return $this->inner->registerResource($resource, $handler);
    }

    public function registerResourceTemplate(ResourceTemplate $template, callable|array|string $handler, array $completionProviders = []): ResourceTemplateReference
    {
        return $this->inner->registerResourceTemplate($template, $handler, $completionProviders);
    }

    public function registerPrompt(Prompt $prompt, callable|array|string $handler, array $completionProviders = []): PromptReference
    {
        return $this->inner->registerPrompt($prompt, $handler, $completionProviders);
    }

    public function unregisterTool(string $name): void
    {
        $this->inner->unregisterTool($name);
    }

    public function unregisterResource(string $uri): void
    {
        $this->inner->unregisterResource($uri);
    }

    public function unregisterResourceTemplate(string $uriTemplate): void
    {
        $this->inner->unregisterResourceTemplate($uriTemplate);
    }

    public function unregisterPrompt(string $name): void
    {
        $this->inner->unregisterPrompt($name);
    }

    public function publishesListChanges(): bool
    {
        return $this->inner->publishesListChanges();
    }

    public function hasTool(string $name): bool
    {
        $this->restoreIfNeeded();

        return $this->inner->hasTool($name) && $this->isVisible($name);
    }

    public function hasResource(string $uri): bool
    {
        return $this->inner->hasResource($uri);
    }

    public function hasResourceTemplate(string $uriTemplate): bool
    {
        return $this->inner->hasResourceTemplate($uriTemplate);
    }

    public function hasPrompt(string $name): bool
    {
        return $this->inner->hasPrompt($name);
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

    public function reset(): void
    {
        $this->restored = false;
    }

    private function isVisible(string $name): bool
    {
        return match ($this->modeResolver->current()) {
            DiscoveryMode::Static => !$this->inner->hasTool($name) || DynamicToolRegistry::HANDLER !== $this->inner->getTool($name)->handler,
            DiscoveryMode::Dynamic => InvokeApiOperation::TOOL_NAME !== $name,
        };
    }

    private function restoreIfNeeded(): void
    {
        if ($this->restored || DiscoveryMode::Static === $this->modeResolver->current()) {
            return;
        }
        $this->restored = true;

        $this->dynamicToolRegistry->restoreFromStore();
    }
}
