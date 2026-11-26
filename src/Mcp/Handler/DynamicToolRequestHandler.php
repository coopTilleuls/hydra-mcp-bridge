<?php

declare(strict_types=1);

namespace App\Mcp\Handler;

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
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Handles all dynamically discovered tools (search + operation).
 * Implements RequestHandlerInterface so it intercepts CallToolRequest
 * before the SDK's CallToolHandler — no container resolution needed.
 */
final class DynamicToolRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly HttpClientInterface $hydraClient,
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
            $result = match ($config->type) {
                'search' => $this->handleSearch($request, $config),
                'operation' => $this->handleOperation($request, $config),
                default => throw new \RuntimeException(\sprintf('Unknown affordance type "%s".', $config->type)),
            };

            return new Response($request->getId(), new CallToolResult([new TextContent($result)]));
        } catch (\Throwable $e) {
            $this->logger->error('Dynamic tool error', [
                'tool' => $request->name,
                'type' => $config->type,
                'exception' => $e->getMessage(),
            ]);

            return Error::forInternalError($e->getMessage(), $request->getId());
        }
    }

    private function handleSearch(CallToolRequest $request, \App\Mcp\StoredAffordance $config): string
    {
        $query = [];
        foreach ($config->paramNames as $name) {
            if (isset($request->arguments[$name]) && '' !== $request->arguments[$name]) {
                $query[$name] = $request->arguments[$name];
            }
        }

        return $this->hydraClient->request('GET', $config->uri, [
            'query' => $query,
        ])->getContent();
    }

    private function handleOperation(CallToolRequest $request, \App\Mcp\StoredAffordance $config): string
    {
        $contentType = 'PATCH' === $config->method ? 'application/merge-patch+json' : 'application/ld+json';
        $options = [
            'headers' => ['Content-Type' => $contentType],
        ];

        if ('DELETE' !== $config->method) {
            // If the input schema has a "payload" property, use it as the body directly.
            // Otherwise, the arguments ARE the properties (from docs.jsonld discovery).
            $payload = $request->arguments['payload'] ?? null;
            $body = null !== $payload ? $payload : ($request->arguments ?? []);
            unset($body['uri']);

            if ([] !== $body) {
                $options['json'] = $body;
            }
        }

        // Use the agent-supplied URI directly when available (instance operations),
        // otherwise fall back to the stored collection URI (POST).
        $uri = isset($request->arguments['uri']) ? $request->arguments['uri'] : $config->uri;

        return $this->hydraClient->request($config->method, $uri, $options)->getContent();
    }
}
