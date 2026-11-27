<?php

declare(strict_types=1);

namespace App\Mcp\Handler;

use App\Mcp\AffordanceInvoker;
use App\Mcp\AffordanceStore;
use App\Mcp\DiscoveryMode;
use App\Mcp\DiscoveryModeResolver;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Handles all dynamically discovered tools (search + operation).
 * Implements RequestHandlerInterface so it intercepts CallToolRequest
 * before the SDK's CallToolHandler — no container resolution needed.
 */
final class DynamicToolRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AffordanceInvoker $invoker,
        private readonly AffordanceStore $store,
        private readonly DiscoveryModeResolver $modeResolver,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function supports(Request $request): bool
    {
        if (!$request instanceof CallToolRequest || DiscoveryMode::Dynamic !== $this->modeResolver->current()) {
            return false;
        }

        // null session — AffordanceStore falls back to resolving from store via header
        return null !== $this->store->get(null, $request->name);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $config = $this->store->get($session, $request->name);

        if (null === $config) {
            return Error::forMethodNotFound(\sprintf('No affordance config found for tool "%s".', $request->name), $request->getId());
        }

        try {
            return new Response($request->getId(), new CallToolResult([new TextContent($this->invoker->invoke($config, $request->arguments ?? []))]));
        } catch (\Throwable $e) {
            $this->logger->error('Dynamic tool error', [
                'tool' => $request->name,
                'type' => $config->type,
                'exception' => $e->getMessage(),
            ]);

            return Error::forInternalError($e->getMessage(), $request->getId());
        }
    }
}
