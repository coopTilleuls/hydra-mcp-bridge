<?php

declare(strict_types=1);

namespace App\Mcp;

use App\JsonLd\Expander;
use App\JsonLd\JsonLdDocument;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Expanded docs.jsonld, cached application-wide by URL.
 *
 * The URL alone keys the cache because the gateway calls upstream with one static identity
 * (hydra.client). Forwarding per-user credentials would make the documentation vary by
 * credential, and the key would then have to include it.
 */
final readonly class ApiDocumentation
{
    private const string LINK_REL = 'http://www.w3.org/ns/hydra/core#apiDocumentation';

    public function __construct(
        #[Autowire(service: 'hydra.client')]
        private HttpClientInterface $hydraClient,
        private Expander $expander,
        private CacheInterface $cache,
        private LoggerInterface $logger,
        private int $ttl = 300,
    ) {
    }

    public function forResponse(ResponseInterface $response): ?JsonLdDocument
    {
        $url = self::linkedFrom($response);
        if (null === $url) {
            return null;
        }

        try {
            $expanded = $this->cache->get('hydra_docs.'.hash('xxh128', $url), function (ItemInterface $item) use ($url): string {
                $item->expiresAfter($this->ttl);
                $compact = json_decode($this->hydraClient->request('GET', $url)->getContent(), false, 512, \JSON_THROW_ON_ERROR);

                return json_encode($this->expander->expand($compact)->toExpandedArray(), \JSON_THROW_ON_ERROR);
            });
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to fetch/expand API documentation', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        return JsonLdDocument::fromExpanded(json_decode($expanded, false, 512, \JSON_THROW_ON_ERROR));
    }

    public static function linkedFrom(ResponseInterface $response): ?string
    {
        foreach ($response->getHeaders(false)['link'] ?? [] as $linkHeader) {
            if (str_contains($linkHeader, self::LINK_REL) && preg_match('/<([^>]+)>/', $linkHeader, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }
}
