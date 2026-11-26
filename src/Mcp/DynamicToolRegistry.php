<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The registry is shared by every request a worker serves, so tools registered for one session are
 * removed on reset; the next request restores its own session's tools from the AffordanceStore.
 */
final class DynamicToolRegistry implements ResetInterface
{
    /**
     * Handler string stored in the registry's ToolReference.
     * Never resolved by ReferenceHandler — DynamicToolRequestHandler intercepts first.
     */
    public const HANDLER = 'dynamic_tool_handler';

    /** @var array<string, true> */
    private array $registeredTools = [];

    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly AffordanceStore $store,
    ) {
    }

    /**
     * Register a discovered affordance as a dynamic MCP tool.
     *
     * @return bool true if a new tool was registered, false if it already existed
     */
    public function registerAffordance(StoredAffordance $affordance, SessionInterface $session): bool
    {
        if (isset($this->registeredTools[$affordance->name])) {
            return false;
        }

        // Persist config so the handler can look it up by tool name
        $this->store->save($session, $affordance);

        $this->registerTool($affordance);

        return true;
    }

    /**
     * Restore previously discovered tools from persistent storage.
     */
    public function restoreFromStore(?SessionInterface $session = null): void
    {
        foreach ($this->store->all($session) as $affordance) {
            $this->registerTool($affordance);
        }
    }

    public function reset(): void
    {
        foreach (array_keys($this->registeredTools) as $name) {
            $this->registry->unregisterTool($name);
        }
        $this->registeredTools = [];
    }

    private function registerTool(StoredAffordance $affordance): void
    {
        if (isset($this->registeredTools[$affordance->name])) {
            return;
        }

        $tool = new Tool(
            name: $affordance->name,
            title: null,
            inputSchema: $affordance->inputSchema,
            description: $affordance->description,
            annotations: null,
        );

        $this->registry->registerTool($tool, self::HANDLER);
        $this->registeredTools[$affordance->name] = true;
    }
}
