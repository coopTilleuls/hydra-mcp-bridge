<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Event\ResponseEvent;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\InitializeResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The configured server instructions describe the static mode, which server/discover always serves.
 * A dynamic or groups handshake gets typed-tool instructions instead: telling it to call
 * invoke_api_operation, a tool it never lists, would mislead the model.
 */
final readonly class DynamicModeInstructions
{
    public function __construct(
        private DiscoveryModeResolver $modeResolver,
        #[Autowire(param: 'app.mcp.dynamic_instructions')]
        private string $instructions,
        #[Autowire(param: 'app.mcp.groups_instructions')]
        private string $groupsInstructions,
    ) {
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        $result = $response->result;

        if (!$result instanceof InitializeResult) {
            return;
        }

        $instructions = match ($this->modeResolver->forSession($event->getSession(), $event->getRequest())) {
            DiscoveryMode::Dynamic => $this->instructions,
            DiscoveryMode::Groups => $this->groupsInstructions,
            DiscoveryMode::Static => null,
        };
        if (null === $instructions) {
            return;
        }

        $event->setResponse(new Response($response->id, new InitializeResult(
            $result->capabilities,
            $result->serverInfo,
            $instructions,
            $result->meta,
            $result->protocolVersion,
        )));
    }
}
