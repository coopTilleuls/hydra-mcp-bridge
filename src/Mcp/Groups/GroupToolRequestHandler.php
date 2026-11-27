<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use App\Mcp\AffordanceInvoker;
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

/**
 * Calls a scoped tool by name. Loading its group first is not required: discovery is not access control.
 */
final readonly class GroupToolRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private ToolGroups $groups,
        private AffordanceInvoker $invoker,
        private DiscoveryModeResolver $modeResolver,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest
            && DiscoveryMode::Groups === $this->modeResolver->current()
            && null !== $this->groups->find($request->name);
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);
        $tool = $this->groups->find($request->name) ?? throw new \LogicException('supports() found it.');

        try {
            return new Response($request->getId(), new CallToolResult([new TextContent($this->invoker->invoke($tool, $request->arguments ?? []))]));
        } catch (\Throwable $e) {
            $this->logger->error('Scoped tool error', ['tool' => $request->name, 'exception' => $e->getMessage()]);

            return Error::forInternalError($e->getMessage(), $request->getId());
        }
    }
}
