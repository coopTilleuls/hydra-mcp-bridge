<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use App\Mcp\StoredAffordance;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Request;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Tool;
use Mcp\Server\Handler\Request\RequestHandlerInterface;
use Mcp\Server\Session\SessionInterface;

final readonly class GroupsRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private ToolGroups $groups,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListGroupsRequest || $request instanceof GetGroupRequest;
    }

    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        if ($request instanceof ListGroupsRequest) {
            return new Response($request->getId(), new ListGroupsResult(array_values($this->groups->groups())));
        }

        \assert($request instanceof GetGroupRequest);
        $tools = $this->groups->tools($request->name);
        if (null === $tools) {
            return Error::forResourceNotFound(\sprintf('Unknown group "%s".', $request->name), $request->getId());
        }

        return new Response($request->getId(), new GetGroupResult(array_map(
            static fn (StoredAffordance $tool): Tool => new Tool($tool->name, null, $tool->inputSchema, $tool->description, null),
            $tools,
        )));
    }
}
