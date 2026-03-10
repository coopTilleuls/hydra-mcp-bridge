<?php

declare(strict_types=1);

namespace App\JsonLd;

use ML\JsonLD\JsonLD;

/**
 * Expands JSON-LD documents into their canonical form with full IRIs.
 *
 * Single responsibility: JSON-LD expansion via sweetrdf/json-ld.
 * For navigating expanded nodes, use {@see JsonLdNode} and {@see JsonLdDocument}.
 */
final class Expander
{
    public function __construct(
        private readonly HttpClientDocumentLoader $documentLoader,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Expand a JSON-LD stdClass into canonical expanded form.
     */
    public function expand(\stdClass $jsonLd): JsonLdDocument
    {
        $jsonLd = $this->normalizeContext($jsonLd);

        $expanded = JsonLD::expand($jsonLd, [
            'base' => $this->baseUrl,
            'documentLoader' => $this->documentLoader,
        ]);

        return JsonLdDocument::fromExpanded($expanded);
    }

    /**
     * Normalize @context arrays that contain full JSON-LD nodes (e.g., API Platform's
     * docs.jsonld inlines the Hydra vocabulary as a node with @id, @type, etc.).
     * sweetrdf/json-ld expects context elements to be URIs or pure context objects.
     */
    private function normalizeContext(\stdClass $jsonLd): \stdClass
    {
        if (!isset($jsonLd->{'@context'}) || !\is_array($jsonLd->{'@context'})) {
            return $jsonLd;
        }

        $normalized = [];
        foreach ($jsonLd->{'@context'} as $entry) {
            if ($entry instanceof \stdClass && isset($entry->{'@context'})) {
                // Extract the inner @context from embedded JSON-LD nodes
                $normalized[] = $entry->{'@context'};
            } else {
                $normalized[] = $entry;
            }
        }

        $jsonLd->{'@context'} = $normalized;

        return $jsonLd;
    }
}
