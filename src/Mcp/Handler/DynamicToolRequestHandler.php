<?php

declare(strict_types=1);

namespace App\Mcp\Handler;

use App\Mcp\AffordanceStore;
use App\Mcp\DiscoveryMode;
use App\Mcp\DiscoveryModeResolver;
use App\Mcp\Iri;
use App\Mcp\StoredAffordance;
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

    private function handleSearch(CallToolRequest $request, StoredAffordance $config): string
    {
        $query = [];
        foreach ($config->paramNames as $name) {
            if (isset($request->arguments[$name]) && '' !== $request->arguments[$name]) {
                $query[$name] = $request->arguments[$name];
            }
        }

        return $this->hydraClient->request('GET', $this->target($request, $config), [
            'query' => $query,
        ])->getContent();
    }

    private function handleOperation(CallToolRequest $request, StoredAffordance $config): string
    {
        $contentType = 'PATCH' === $config->method ? 'application/merge-patch+json' : 'application/ld+json';
        $options = [
            'headers' => ['Content-Type' => $contentType],
        ];
        $target = $this->target($request, $config);

        if ('DELETE' !== $config->method) {
            $arguments = $request->arguments ?? [];
            $body = $config->declaresArgument('payload') && isset($arguments['payload'])
                ? $arguments['payload']
                : array_diff_key(
                    array_intersect_key($arguments, (array) ($config->inputSchema['properties'] ?? [])),
                    array_flip(['uri', 'payload', $config->parentParam ?? '']),
                );

            if ([] !== $body) {
                $options['json'] = $body;
            }
        }

        return $this->hydraClient->request($config->method, $target, $options)->getContent();
    }

    private function target(CallToolRequest $request, StoredAffordance $config): string
    {
        if (null !== $config->link) {
            return $this->followLink($this->argument($request, $config->parentParam ?? ''), $config->link);
        }

        if ($config->declaresArgument('uri')) {
            $uri = $this->argument($request, 'uri');
            if ([] === $config->advertisedBy) {
                return $uri;
            }

            foreach ($config->advertisedBy as $resource) {
                if (Iri::path($resource) === Iri::path($uri)) {
                    return $resource;
                }
            }

            throw new \InvalidArgumentException(\sprintf('"%s" is only available on %s. Read "%s" first: run it only if that resource lists it in hydra:operation.', $request->name, implode(', ', $config->advertisedBy), $uri));
        }

        if ('search' === $config->type && $config->declaresArgument('collection')) {
            return $this->argument($request, 'collection');
        }

        return $config->uri ?? throw new \LogicException(\sprintf('Tool "%s" has no target.', $request->name));
    }

    private function followLink(string $parent, string $link): string
    {
        $representation = json_decode($this->hydraClient->request('GET', $parent)->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        $target = $representation->{$link} ?? null;
        if ($target instanceof \stdClass) {
            $target = $target->{'@id'} ?? null;
        }

        if (!\is_string($target) || '' === $target) {
            throw new \RuntimeException(\sprintf('"%s" has no "%s" link.', $parent, $link));
        }

        return $target;
    }

    private function argument(CallToolRequest $request, string $name): string
    {
        $value = $request->arguments[$name] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf('Argument "%s" is required.', $name));
        }

        return $value;
    }
}
