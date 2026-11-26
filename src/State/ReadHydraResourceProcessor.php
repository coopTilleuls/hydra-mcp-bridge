<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\JsonLd\Expander;
use App\JsonLd\JsonLdDocument;
use App\JsonLd\Vocab;
use App\Mcp\AffordanceStore;
use App\Mcp\DiscoveryMode;
use App\Mcp\DiscoveryModeResolver;
use App\Mcp\DynamicToolRegistry;
use App\Mcp\HydraResponseParser;
use App\Mcp\ResourceDescriber;
use App\Mcp\StoredAffordance;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Notification\ToolListChangedNotification;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReadHydraResourceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly HttpClientInterface $hydraClient,
        private readonly Expander $expander,
        private readonly HydraResponseParser $parser,
        private readonly DynamicToolRegistry $dynamicToolRegistry,
        private readonly AffordanceStore $affordanceStore,
        private readonly LoggerInterface $logger,
        private readonly DiscoveryModeResolver $modeResolver,
        private readonly ResourceDescriber $describer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $session = $context['mcp_session'];
        $uri = $data->uri;
        $this->logger->info('Navigating to Hydra resource', ['uri' => $uri]);

        if (DiscoveryMode::Static === $this->modeResolver->forSession($session)) {
            return $this->describe($uri);
        }

        try {
            $response = $this->hydraClient->request('GET', $uri);
            $content = $response->getContent();
            $compactJsonLd = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch Hydra resource', ['uri' => $uri, 'error' => $e->getMessage()]);

            return CallToolResult::error([
                new TextContent("Error fetching {$uri}: {$e->getMessage()}"),
            ]);
        }

        // Expand JSON-LD to canonical form with full IRIs
        // Clone because sweetrdf/json-ld mutates the input stdClass (strips @context)
        try {
            $expanded = $this->expander->expand(clone $compactJsonLd);
        } catch (\Throwable $e) {
            $this->logger->warning('JSON-LD expansion failed, skipping affordance discovery', ['uri' => $uri, 'error' => $e->getMessage()]);
            $expanded = new JsonLdDocument([]);
        }

        $affordances = [];
        $entrypointLinks = $this->affordanceStore->getEntrypointLinks($session);

        if ($this->isEntrypoint($compactJsonLd)) {
            // Phase 1: cache docs.jsonld URL and the entrypoint links — no tool registration
            $this->cacheDocsUrl($session, $response);
            $entrypointLinks = $this->parser->entrypointLinks($compactJsonLd);
            $this->affordanceStore->saveEntrypointLinks($session, $entrypointLinks);
        } elseif ($this->isCollection($expanded)) {
            // Phase 2: collection visit — discover CRUD tools for the managed class
            $expandedDocs = $this->getOrFetchDocs($session, $response);
            $entrypointLinks ??= $this->fetchEntrypointLinks($session, $expandedDocs);
            $affordances = $this->discoverFromCollection($expanded, $compactJsonLd, $expandedDocs, $entrypointLinks ?? []);
        }

        // Discover inline affordances (hydra:search, hydra:operation) — always
        if (!$expanded->isEmpty()) {
            $inline = $this->parser->extractAffordances($expanded, $compactJsonLd, $entrypointLinks ?? []);
            $affordances = array_merge($affordances, $inline);
        }

        $newToolsRegistered = false;

        foreach ($affordances as $affordance) {
            if ($this->dynamicToolRegistry->registerAffordance($affordance, $session)) {
                $this->logger->info('Registered dynamic tool', ['name' => $affordance->name, 'type' => $affordance->type]);
                $newToolsRegistered = true;
            }
        }

        if ($newToolsRegistered) {
            new ClientGateway($session)->notify(new ToolListChangedNotification());
        }

        return new CallToolResult(
            [new TextContent($content)],
        );
    }

    private function describe(string $uri): CallToolResult
    {
        try {
            $described = $this->describer->describe($uri)->toArray();
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch Hydra resource', ['uri' => $uri, 'error' => $e->getMessage()]);

            return CallToolResult::error([new TextContent("Error fetching {$uri}: {$e->getMessage()}")]);
        }

        return new CallToolResult(
            [new TextContent(json_encode($described, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES))],
            structuredContent: $described,
        );
    }

    private function isEntrypoint(\stdClass $jsonLd): bool
    {
        $type = $jsonLd->{'@type'} ?? null;

        return 'Entrypoint' === $type || 'hydra:Entrypoint' === $type;
    }

    private function isCollection(JsonLdDocument $doc): bool
    {
        $types = $doc->first()?->types() ?? [];

        return \in_array(Vocab::COLLECTION, $types, true);
    }

    /**
     * Phase 1: Extract and cache the docs.jsonld URL from the Link header.
     */
    private function cacheDocsUrl(SessionInterface $session, $response): void
    {
        $docsUrl = $this->extractDocsUrl($response);
        if (null === $docsUrl) {
            $this->logger->debug('No hydra:apiDocumentation Link header found on entrypoint.');

            return;
        }

        $this->logger->info('Cached docs.jsonld URL from entrypoint', ['url' => $docsUrl]);
        $this->affordanceStore->saveDocsUrl($session, $docsUrl);
    }

    /**
     * Phase 2: On collection visit, resolve the managed class and register typed CRUD tools.
     *
     * @param array<string, string> $entrypointLinks
     *
     * @return StoredAffordance[]
     */
    private function discoverFromCollection(JsonLdDocument $expanded, \stdClass $compactJsonLd, ?JsonLdDocument $expandedDocs, array $entrypointLinks): array
    {
        $managedClassId = $this->parser->resolveManagedClass($expanded, $compactJsonLd);
        if (null === $managedClassId) {
            $this->logger->debug('Could not resolve managed class for collection', ['uri' => $compactJsonLd->{'@id'} ?? '?']);

            return [];
        }

        $this->logger->info('Resolved managed class for collection', ['class' => $managedClassId]);

        if (null === $expandedDocs) {
            return [];
        }

        return $this->parser->extractClassOperations($expandedDocs, $managedClassId, $entrypointLinks);
    }

    /**
     * The entrypoint links, read from the entrypoint the documentation declares when it was not visited.
     *
     * @return array<string, string>|null
     */
    private function fetchEntrypointLinks(SessionInterface $session, ?JsonLdDocument $expandedDocs): ?array
    {
        $entrypoint = null !== $expandedDocs ? $this->parser->entrypointIri($expandedDocs) : null;
        if (null === $entrypoint) {
            return null;
        }

        try {
            $compact = json_decode($this->hydraClient->request('GET', $entrypoint)->getContent(), false, 512, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch the entrypoint', ['url' => $entrypoint, 'error' => $e->getMessage()]);

            return null;
        }

        if (!$compact instanceof \stdClass) {
            return null;
        }

        $links = $this->parser->entrypointLinks($compact);
        $this->affordanceStore->saveEntrypointLinks($session, $links);

        return $links;
    }

    /**
     * Get cached docs.jsonld or fetch it (from cached URL or response Link header).
     */
    private function getOrFetchDocs(SessionInterface $session, $response): ?JsonLdDocument
    {
        // Try cache first
        $cached = $this->affordanceStore->getDocsCache($session);
        if (null !== $cached) {
            return JsonLdDocument::fromExpanded($cached);
        }

        // Get URL from store (Phase 1 cache) or from this response's Link header
        $docsUrl = $this->affordanceStore->getDocsUrl($session) ?? $this->extractDocsUrl($response);
        if (null === $docsUrl) {
            $this->logger->debug('No docs.jsonld URL available for collection discovery.');

            return null;
        }

        $this->logger->info('Fetching API documentation for collection discovery', ['url' => $docsUrl]);

        try {
            $docsResponse = $this->hydraClient->request('GET', $docsUrl, [
                'headers' => ['Accept' => 'application/ld+json'],
            ]);
            $docsCompact = json_decode($docsResponse->getContent(), false, 512, \JSON_THROW_ON_ERROR);
            $expandedDocs = $this->expander->expand($docsCompact);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch/expand API documentation', ['url' => $docsUrl, 'error' => $e->getMessage()]);

            return null;
        }

        // Cache for subsequent collection visits
        $this->affordanceStore->saveDocsCache($session, $expandedDocs->toExpandedArray());

        return $expandedDocs;
    }

    /**
     * Extract the docs.jsonld URL from the response Link header.
     */
    private function extractDocsUrl($response): ?string
    {
        $linkHeaders = $response->getHeaders(false)['link'] ?? [];

        foreach ($linkHeaders as $linkHeader) {
            if (str_contains($linkHeader, 'http://www.w3.org/ns/hydra/core#apiDocumentation')) {
                if (preg_match('/<([^>]+)>/', $linkHeader, $matches)) {
                    return $matches[1];
                }
            }
        }

        return null;
    }
}
