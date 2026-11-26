<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Register a discovered affordance as a dynamic MCP tool. An inline operation seen again on another
     * resource is extended to that resource; any other existing tool is kept as is.
     *
     * @return bool true if a new tool was registered, false if it already existed
     */
    public function registerAffordance(StoredAffordance $affordance, SessionInterface $session): bool
    {
        $existing = $this->store->get($session, $affordance->name);

        if (null === $existing) {
            $this->store->save($session, $affordance);
            $this->registerTool($affordance);

            return true;
        }

        if (!$existing->sameBinding($affordance)) {
            $this->logger->warning('A discovered tool has the name of another tool with a different target and is ignored', [
                'name' => $affordance->name,
                'existing' => get_object_vars($existing),
                'ignored' => get_object_vars($affordance),
            ]);
        } elseif ([] !== $affordance->advertisedBy) {
            $extended = array_reduce($affordance->advertisedBy, static fn (StoredAffordance $tool, string $resource): StoredAffordance => $tool->withAdvertisedBy($resource), $existing);
            if ($extended !== $existing) {
                $this->store->save($session, $extended);
            }
        }

        $this->registerTool($existing);

        return false;
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
