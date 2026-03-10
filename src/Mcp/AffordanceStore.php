<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionInterface;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Persists affordance configuration (URI, params, method) per MCP session.
 * Uses the MCP session's built-in key-value storage instead of file I/O.
 *
 * Write methods require the active SessionInterface (from handler context).
 * Read methods accept an optional session — when null, a read-only session
 * is resolved from the session store using the Mcp-Session-Id header.
 */
final class AffordanceStore
{
    private const KEY_AFFORDANCES = 'hydra.affordances';
    private const KEY_DOCS_URL = 'hydra.docs_url';
    private const KEY_DOCS_CACHE = 'hydra.docs_cache';

    /** @var array<string, array<string, mixed>> in-memory cache (for stdio / same-process reads) */
    private array $affordancesCache = [];
    private ?string $docsUrlCache = null;
    private ?array $docsCacheCache = null;

    public function __construct(
        private readonly SessionStoreInterface $sessionStore,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function get(?SessionInterface $session, string $toolName): ?StoredAffordance
    {
        // Check in-memory cache first (handles stdio + same-process)
        if (isset($this->affordancesCache[$toolName])) {
            return new StoredAffordance(...$this->affordancesCache[$toolName]);
        }

        $session ??= $this->resolveSession();
        if (null === $session) {
            return null;
        }

        $data = $session->get(self::KEY_AFFORDANCES, []);

        foreach ($data as $entry) {
            if ($entry['name'] === $toolName) {
                return new StoredAffordance(...$entry);
            }
        }

        return null;
    }

    public function has(?SessionInterface $session, string $toolName): bool
    {
        return null !== $this->get($session, $toolName);
    }

    public function save(SessionInterface $session, StoredAffordance $affordance): void
    {
        $data = $session->get(self::KEY_AFFORDANCES, []);

        // Avoid duplicates
        foreach ($data as $entry) {
            if ($entry['name'] === $affordance->name) {
                return;
            }
        }

        $entry = get_object_vars($affordance);
        $data[] = $entry;

        $session->set(self::KEY_AFFORDANCES, $data);
        $this->affordancesCache[$affordance->name] = $entry;
    }

    public function saveDocsUrl(SessionInterface $session, string $url): void
    {
        $session->set(self::KEY_DOCS_URL, $url);
        $this->docsUrlCache = $url;
    }

    public function getDocsUrl(?SessionInterface $session): ?string
    {
        if (null !== $this->docsUrlCache) {
            return $this->docsUrlCache;
        }

        $session ??= $this->resolveSession();
        if (null === $session) {
            return null;
        }

        return $session->get(self::KEY_DOCS_URL);
    }

    public function saveDocsCache(SessionInterface $session, array $expandedDocs): void
    {
        $session->set(self::KEY_DOCS_CACHE, $expandedDocs);
        $this->docsCacheCache = $expandedDocs;
    }

    public function getDocsCache(?SessionInterface $session): ?array
    {
        if (null !== $this->docsCacheCache) {
            return $this->docsCacheCache;
        }

        $session ??= $this->resolveSession();
        if (null === $session) {
            return null;
        }

        $cached = $session->get(self::KEY_DOCS_CACHE);
        if (null === $cached) {
            return null;
        }

        // Session stores JSON with associative arrays; convert back to stdClass
        // for JsonLdDocument::fromExpanded() which expects stdClass nodes.
        return json_decode(json_encode($cached));
    }

    /**
     * @return list<StoredAffordance>
     */
    public function all(?SessionInterface $session): array
    {
        $session ??= $this->resolveSession();
        if (null === $session) {
            // Fall back to in-memory cache (stdio transport)
            $result = [];
            foreach ($this->affordancesCache as $entry) {
                $result[] = new StoredAffordance(...$entry);
            }

            return $result;
        }

        $data = $session->get(self::KEY_AFFORDANCES, []);
        $result = [];
        foreach ($data as $entry) {
            $result[] = new StoredAffordance(...$entry);
            $this->affordancesCache[$entry['name']] = $entry;
        }

        return $result;
    }

    /**
     * Resolve a read-only session from the store using the Mcp-Session-Id header.
     * Returns null for stdio transport (no HTTP request).
     */
    private function resolveSession(): ?SessionInterface
    {
        $sessionId = $this->requestStack->getCurrentRequest()?->headers->get('Mcp-Session-Id');
        if (null === $sessionId) {
            return null;
        }

        return new Session($this->sessionStore, Uuid::fromString($sessionId));
    }
}
