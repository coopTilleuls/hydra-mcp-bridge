<?php

declare(strict_types=1);

namespace App\Mcp;

use App\JsonLd\JsonLdDocument;
use App\JsonLd\JsonLdNode;
use App\JsonLd\Vocab;

/**
 * Parses expanded JSON-LD responses to extract Hydra affordances.
 *
 * All input is expected to be in JSON-LD expanded form (full IRIs, values as arrays).
 * Use {@see \App\JsonLd\Expander} to expand compact JSON-LD before passing to this parser.
 */
final class HydraResponseParser
{
    /**
     * Extract affordances from an expanded resource response (inline hydra:search and hydra:operation).
     *
     * @param JsonLdDocument $doc          The expanded JSON-LD document
     * @param array          $compactJsonLd The original compact JSON-LD (for collection @id used in search URIs)
     *
     * @return StoredAffordance[]
     */
    public function extractAffordances(JsonLdDocument $doc, \stdClass $compactJsonLd): array
    {
        $node = $doc->first();
        if (null === $node) {
            return [];
        }

        return array_merge(
            $this->parseSearch($node, $compactJsonLd),
            $this->parseInlineOperations($node),
        );
    }

    /**
     * Resolve the managed class IRI from a collection's expanded JSON-LD.
     *
     * Strategy:
     * 1. hydra:memberAssertion with property=rdf:type → use object as class IRI
     * 2. Fallback: resolve from the compact @context — expand the context URL
     *    against the vocabulary base to derive the full class IRI
     *    (e.g., @context "/contexts/Book" + @vocab "https://localhost/docs.jsonld#" → "https://localhost/docs.jsonld#Book")
     */
    public function resolveManagedClass(JsonLdDocument $doc, ?\stdClass $compactJsonLd = null): ?string
    {
        $node = $doc->first();
        if (null === $node) {
            return null;
        }

        // Strategy 1: hydra:memberAssertion
        foreach ($node->all(Vocab::MEMBER_ASSERTION) as $assertion) {
            $property = $assertion->id(Vocab::PROPERTY);
            if (Vocab::RDF_TYPE === $property) {
                $object = $assertion->id(Vocab::HYDRA.'object');
                if (null !== $object) {
                    return $object;
                }
            }
        }

        // Strategy 2: resolve class IRI from the compact @context
        if (null !== $compactJsonLd) {
            return $this->resolveClassFromContext($compactJsonLd);
        }

        return null;
    }

    /**
     * Resolve the managed class IRI from the compact JSON-LD @context.
     *
     * When the @context is a string URL like "/contexts/Book", we extract the
     * class name from the URL path. The expanded document's vocabulary base
     * (from @vocab in the dereferenced context) provides the namespace.
     * Together they form the full class IRI.
     *
     * If @context is an object/array (already inline), we look for @vocab directly.
     */
    private function resolveClassFromContext(\stdClass $compactJsonLd): ?string
    {
        $context = $compactJsonLd->{'@context'} ?? null;

        // @context is a URL string like "/contexts/Book"
        if (\is_string($context) && preg_match('#/contexts/(\w+)$#', $context, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Extract operations for a single class from expanded docs.jsonld.
     *
     * @param JsonLdDocument $docs            The expanded docs.jsonld document
     * @param string         $managedClassId  The @id of the managed class to extract operations for
     * @param string         $collectionUri   The collection URI (used as target for POST operations)
     *
     * @return StoredAffordance[]
     */
    public function extractClassOperations(JsonLdDocument $docs, string $managedClassId, string $collectionUri): array
    {
        $docsNode = $docs->first();
        if (null === $docsNode) {
            return [];
        }

        $supportedClasses = $docsNode->all(Vocab::SUPPORTED_CLASS);
        if ([] === $supportedClasses) {
            return [];
        }

        // Build class map for expects lookups
        $classMap = [];
        foreach ($supportedClasses as $class) {
            $classId = $class->nodeId();
            if (null !== $classId) {
                $classMap[$classId] = $class;
            }
        }

        $targetClass = $this->resolveClass($managedClassId, $classMap);
        if (null === $targetClass) {
            return [];
        }

        // Derive a resource name from the class title or IRI fragment
        $resourceName = $targetClass->value(Vocab::TITLE)
            ?? $this->localName($managedClassId);

        $affordances = [];

        // Extract operations from the class's supportedOperations
        foreach ($targetClass->all(Vocab::SUPPORTED_OPERATION) as $operation) {
            $method = $operation->value(Vocab::METHOD);
            if (null === $method) {
                continue;
            }

            $method = strtoupper($method);
            $uri = $collectionUri;

            $isInstance = 'POST' !== $method;
            $affordance = $this->buildOperationAffordance(
                $operation,
                $method,
                $resourceName,
                $uri,
                $managedClassId,
                $classMap,
                $isInstance,
            );

            if (null !== $affordance) {
                $affordances[] = $affordance;
            }
        }

        // Also check supportedProperty operations (entrypoint-style)
        $entrypointClass = null;
        foreach ($supportedClasses as $class) {
            $title = $class->value(Vocab::TITLE);
            if ('Entrypoint' === $title) {
                $entrypointClass = $class;
                break;
            }
        }

        if (null !== $entrypointClass) {
            $affordances = array_merge(
                $affordances,
                $this->extractPropertyOperationsForClass($entrypointClass, $managedClassId, $collectionUri, $classMap),
            );
        }

        return $affordances;
    }

    /**
     * Extract operations from Entrypoint supportedProperty that target a specific class.
     *
     * @param array<string, JsonLdNode> $classMap
     *
     * @return StoredAffordance[]
     */
    private function extractPropertyOperationsForClass(JsonLdNode $entrypointClass, string $managedClassId, string $collectionUri, array $classMap): array
    {
        $affordances = [];

        foreach ($entrypointClass->all(Vocab::SUPPORTED_PROPERTY_PROP) as $supportedProp) {
            $property = $supportedProp->first(Vocab::PROPERTY);
            if (null === $property) {
                continue;
            }

            // Check if this property targets the managed class
            $targetClassId = $this->resolveTargetClass($property);
            if ($targetClassId !== $managedClassId && $this->localName($targetClassId ?? '') !== $this->localName($managedClassId)) {
                continue;
            }

            $propertyName = $property->value(Vocab::LABEL)
                ?? $this->fragmentName($property->nodeId());

            if (null === $propertyName) {
                continue;
            }

            foreach ($property->all(Vocab::SUPPORTED_OPERATION) as $operation) {
                $method = $operation->value(Vocab::METHOD);
                if (null === $method) {
                    continue;
                }

                $affordance = $this->buildOperationAffordance(
                    $operation,
                    strtoupper($method),
                    $propertyName,
                    $collectionUri,
                    $targetClassId,
                    $classMap,
                );

                if (null !== $affordance) {
                    $affordances[] = $affordance;
                }
            }
        }

        return $affordances;
    }

    /**
     * @param array<string, JsonLdNode> $classMap
     */
    private function buildOperationAffordance(
        JsonLdNode $operation,
        string $method,
        string $resourceName,
        string $uri,
        ?string $expectsClassId,
        array $classMap,
        bool $isInstance = false,
    ): ?StoredAffordance {
        // Use the operation's own name (hydra:title) as tool name — never guess from HTTP method
        $operationTitle = $operation->value(Vocab::TITLE);
        $toolName = null !== $operationTitle
            ? $this->toSnakeCase($operationTitle)
            : $this->toSnakeCase($resourceName);

        $description = $operation->value(Vocab::DESCRIPTION)
            ?? $operationTitle
            ?? $toolName;

        $expects = $operation->id(Vocab::EXPECTS) ?? $expectsClassId;
        $inputSchema = $this->buildInputSchemaFromClass($expects, $classMap, $method, $isInstance);

        return new StoredAffordance(
            name: $toolName,
            description: $description,
            inputSchema: $inputSchema,
            type: 'operation',
            uri: $uri,
            method: $method,
        );
    }

    /**
     * @param array<string, JsonLdNode> $classMap
     */
    private function buildInputSchemaFromClass(?string $classId, array $classMap, string $method, bool $isInstance): array
    {
        $needsUri = $isInstance && 'POST' !== $method;

        // DELETE and GET don't need body properties — only the uri parameter
        if ('DELETE' === $method || 'GET' === $method) {
            return $this->assembleSchema([], [], $method, $needsUri);
        }

        [$properties, $required] = $this->collectBodyProperties($this->resolveClass($classId, $classMap), $method);

        return $this->assembleSchema($properties, $required, $method, $needsUri);
    }

    /**
     * @return array{0: array<string, array<string, string>>, 1: list<string>}
     */
    private function collectBodyProperties(?JsonLdNode $class, string $method): array
    {
        $properties = [];
        $required = [];

        if (null === $class) {
            return [$properties, $required];
        }

        foreach ($class->all(Vocab::SUPPORTED_PROPERTY_PROP) as $supportedProp) {
            $writeable = $supportedProp->value(Vocab::WRITEABLE);
            if (false === $writeable) {
                continue;
            }

            $propDef = $supportedProp->first(Vocab::PROPERTY);
            $propName = $supportedProp->value(Vocab::TITLE)
                ?? $propDef?->value(Vocab::LABEL);

            if (null === $propName || !\is_string($propName)) {
                continue;
            }

            $range = $propDef ? $this->getFirstRangeId($propDef) : null;
            $propSchema = ['type' => $this->iriToJsonSchemaType($range)];
            $propDescription = $supportedProp->value(Vocab::DESCRIPTION);
            if (null !== $propDescription) {
                $propSchema['description'] = $propDescription;
            }

            $properties[$propName] = $propSchema;

            // PATCH is a partial update — no properties should be required
            if ('PATCH' !== $method && true === $supportedProp->value(Vocab::REQUIRED)) {
                $required[] = $propName;
            }
        }

        return [$properties, $required];
    }

    // --- Stateless descriptions: operations described in a result, invoked by URI ---

    /**
     * Operations the documentation declares for the first of the candidate classes it knows.
     * "collection" operations target the collection URI, "member" operations target each member URI.
     *
     * @param list<string> $classCandidates class IRIs or local names, most specific first
     *
     * @return array{collection: list<OperationDescription>, member: list<OperationDescription>}
     */
    public function describeClassOperations(JsonLdDocument $docs, array $classCandidates): array
    {
        $described = ['collection' => [], 'member' => []];
        $docsNode = $docs->first();
        if (null === $docsNode) {
            return $described;
        }

        $classMap = $this->buildClassMap($docsNode);
        $class = null;
        foreach ($classCandidates as $candidate) {
            if (null !== $class = $this->resolveClass($candidate, $classMap)) {
                break;
            }
        }

        if (null === $class) {
            return $described;
        }

        $classId = $class->nodeId();

        foreach ($class->all(Vocab::SUPPORTED_OPERATION) as $operation) {
            $description = $this->describeOperation($operation, $classId, $classMap);
            if (null !== $description) {
                $described['POST' === $description->method ? 'collection' : 'member'][] = $description;
            }
        }

        foreach ($classMap as $entrypointClass) {
            if ('Entrypoint' !== $entrypointClass->value(Vocab::TITLE)) {
                continue;
            }

            foreach ($entrypointClass->all(Vocab::SUPPORTED_PROPERTY_PROP) as $supportedProp) {
                $property = $supportedProp->first(Vocab::PROPERTY);
                $targetClassId = null !== $property ? $this->resolveTargetClass($property) : null;
                if (null === $targetClassId || $this->localName($targetClassId) !== $this->localName($classId ?? '')) {
                    continue;
                }

                foreach ($property->all(Vocab::SUPPORTED_OPERATION) as $operation) {
                    $description = $this->describeOperation($operation, $targetClassId, $classMap);
                    if (null !== $description) {
                        $described['collection'][] = $description;
                    }
                }
            }
        }

        return $described;
    }

    /**
     * Inline hydra:operation of the resource. An expects given inline with its supportedProperty
     * is read as is, one given by reference is resolved against the documentation when available.
     *
     * @return list<OperationDescription>
     */
    public function describeInlineOperations(JsonLdDocument $doc, ?JsonLdDocument $docs = null): array
    {
        $node = $doc->first();
        if (null === $node) {
            return [];
        }

        $docsNode = $docs?->first();
        $classMap = null !== $docsNode ? $this->buildClassMap($docsNode) : [];
        $described = [];

        foreach ($node->all(Vocab::OPERATION_PROP) as $operation) {
            $description = $this->describeOperation($operation, null, $classMap);
            if (null !== $description) {
                $described[] = $description;
            }
        }

        return $described;
    }

    /**
     * The resource's hydra:search template as a GET operation whose input is the template mapping.
     */
    public function describeSearch(JsonLdDocument $doc): ?OperationDescription
    {
        $node = $doc->first();
        $search = $node?->first(Vocab::SEARCH);
        if (null === $search) {
            return null;
        }

        $inputSchema = $this->searchSchema($search);
        if (null === $inputSchema) {
            return null;
        }

        return new OperationDescription(
            method: 'GET',
            title: 'search',
            description: 'Search '.$this->deriveResourceName($node).' by: '.implode(', ', array_keys($inputSchema['properties'])),
            inputSchema: $inputSchema,
        );
    }

    /**
     * @param array<string, JsonLdNode> $classMap
     */
    private function describeOperation(JsonLdNode $operation, ?string $defaultExpects, array $classMap): ?OperationDescription
    {
        $method = $operation->value(Vocab::METHOD);
        if (!\is_string($method)) {
            return null;
        }

        $method = strtoupper($method);
        $title = $operation->value(Vocab::TITLE);
        $description = $operation->value(Vocab::DESCRIPTION) ?? $title ?? $method;

        if ('GET' === $method || 'DELETE' === $method) {
            return new OperationDescription($method, $title, $description, ['type' => 'object', 'properties' => new \stdClass()]);
        }

        $expects = $operation->first(Vocab::EXPECTS);
        $expectsClass = null !== $expects && [] !== $expects->all(Vocab::SUPPORTED_PROPERTY_PROP)
            ? $expects
            : $this->resolveClass($operation->id(Vocab::EXPECTS) ?? $defaultExpects, $classMap);

        [$properties, $required] = $this->collectBodyProperties($expectsClass, $method);

        $inputSchema = ['type' => 'object', 'properties' => [] === $properties ? new \stdClass() : $properties];
        if ([] !== $required) {
            $inputSchema['required'] = $required;
        }

        return new OperationDescription($method, $title, $description, $inputSchema);
    }

    /**
     * @return array<string, JsonLdNode>
     */
    private function buildClassMap(JsonLdNode $docsNode): array
    {
        $classMap = [];
        foreach ($docsNode->all(Vocab::SUPPORTED_CLASS) as $class) {
            $classId = $class->nodeId();
            if (null !== $classId) {
                $classMap[$classId] = $class;
            }
        }

        return $classMap;
    }

    /**
     * @return array{type: 'object', properties: array<string, array<string, string>>, required?: list<string>}|null
     */
    private function searchSchema(JsonLdNode $search): ?array
    {
        if (null === $search->value(Vocab::TEMPLATE)) {
            return null;
        }

        $properties = [];
        $required = [];
        foreach ($search->all(Vocab::MAPPING) as $mapping) {
            $variable = $mapping->value(Vocab::VARIABLE);
            if (null === $variable) {
                continue;
            }

            $properties[$variable] = [
                'type' => 'string',
                'description' => "Filter by {$variable}",
            ];

            if ($mapping->value(Vocab::REQUIRED) ?? false) {
                $required[] = $variable;
            }
        }

        if ([] === $properties) {
            return null;
        }

        $inputSchema = ['type' => 'object', 'properties' => $properties];
        if ([] !== $required) {
            $inputSchema['required'] = $required;
        }

        return $inputSchema;
    }

    /**
     * Look up a class in the classMap by @id, with local-name fallback.
     *
     * @param array<string, JsonLdNode> $classMap
     */
    private function resolveClass(?string $classId, array $classMap): ?JsonLdNode
    {
        if (null === $classId) {
            return null;
        }

        if (isset($classMap[$classId])) {
            return $classMap[$classId];
        }

        // Fallback: match by local name
        $localName = $this->localName($classId);
        foreach ($classMap as $id => $class) {
            if ($this->localName($id) === $localName) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Resolve the target class @id from an expanded property's rdfs:range.
     */
    private function resolveTargetClass(JsonLdNode $property): ?string
    {
        foreach ($property->all(Vocab::RANGE) as $rangeEntry) {
            $id = $rangeEntry->nodeId();
            if (\is_string($id) && !str_ends_with($id, 'Collection')) {
                return $id;
            }

            // owl:equivalentClass → owl:allValuesFrom → @id
            $equiv = $rangeEntry->first(Vocab::EQUIVALENT_CLASS);
            if (null !== $equiv) {
                $allValuesFrom = $equiv->id(Vocab::ALL_VALUES_FROM);
                if (null !== $allValuesFrom) {
                    return $allValuesFrom;
                }
            }
        }

        return null;
    }

    /**
     * Get the first @id from rdfs:range entries.
     */
    private function getFirstRangeId(JsonLdNode $propDef): ?string
    {
        foreach ($propDef->all(Vocab::RANGE) as $entry) {
            $id = $entry->nodeId();
            if (\is_string($id)) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Map full IRI range types to JSON Schema types.
     */
    private function iriToJsonSchemaType(?string $iri): string
    {
        if (null === $iri) {
            return 'string';
        }

        return match ($iri) {
            Vocab::XSD.'integer' => 'integer',
            Vocab::XSD.'decimal', Vocab::XSD.'float', Vocab::XSD.'double' => 'number',
            Vocab::XSD.'boolean' => 'boolean',
            default => 'string',
        };
    }

    // --- Inline response parsing ---

    /**
     * @return StoredAffordance[]
     */
    private function parseSearch(JsonLdNode $node, \stdClass $compactJsonLd): array
    {
        $search = $node->first(Vocab::SEARCH);
        if (null === $search) {
            return [];
        }

        $inputSchema = $this->searchSchema($search);
        if (null === $inputSchema) {
            return [];
        }

        $resourceName = $this->deriveResourceName($node);
        $toolName = 'search_'.$resourceName;

        // Use the compact @id for the collection URI (relative path)
        $collectionUri = $compactJsonLd->{'@id'} ?? $search->value(Vocab::TEMPLATE);
        $paramNames = array_keys($inputSchema['properties']);

        return [
            new StoredAffordance(
                name: $toolName,
                description: "Search {$resourceName} by: ".implode(', ', $paramNames),
                inputSchema: $inputSchema,
                type: 'search',
                uri: $collectionUri,
                paramNames: $paramNames,
            ),
        ];
    }

    /**
     * Parse inline hydra:operation from an expanded resource response.
     *
     * @return StoredAffordance[]
     */
    private function parseInlineOperations(JsonLdNode $node): array
    {
        $operations = $node->all(Vocab::OPERATION_PROP);
        if ([] === $operations) {
            return [];
        }

        $resourceUri = $node->nodeId();
        if (null === $resourceUri) {
            return [];
        }

        $resourceName = $this->deriveResourceName($node);
        $affordances = [];

        foreach ($operations as $operation) {
            $method = $operation->value(Vocab::METHOD);
            if (null === $method) {
                continue;
            }

            $method = strtoupper($method);
            $operationTitle = $operation->value(Vocab::TITLE);
            $toolName = null !== $operationTitle
                ? $this->toSnakeCase($operationTitle)
                : $this->toSnakeCase($resourceName);
            $description = $operation->value(Vocab::DESCRIPTION)
                ?? $operationTitle
                ?? $toolName;

            $properties = [];
            $required = [];

            // Inline expects with supportedProperty
            $expectsNode = $operation->first(Vocab::EXPECTS);
            if (null !== $expectsNode) {
                foreach ($expectsNode->all(Vocab::SUPPORTED_PROPERTY_PROP) as $prop) {
                    $propDef = $prop->first(Vocab::PROPERTY);
                    $propName = $propDef
                        ? ($propDef->value(Vocab::TITLE) ?? $this->fragmentName($propDef->nodeId()))
                        : null;

                    if (null === $propName || !\is_string($propName)) {
                        continue;
                    }
                    $properties[$propName] = ['type' => 'string'];
                    if (true === $prop->value(Vocab::REQUIRED)) {
                        $required[] = $propName;
                    }
                }
            }

            $inputSchema = $this->assembleSchema($properties, $required, $method, false);

            $affordances[] = new StoredAffordance(
                name: $toolName,
                description: $description,
                inputSchema: $inputSchema,
                type: 'operation',
                uri: $resourceUri,
                method: $method,
            );
        }

        return $affordances;
    }

    private function deriveResourceName(JsonLdNode $node): string
    {
        $types = $node->types();
        foreach ($types as $type) {
            if (Vocab::COLLECTION !== $type) {
                return $this->toSnakeCase($this->localName($type));
            }
        }

        $id = $node->nodeId() ?? '';
        $segments = explode('/', trim($id, '/'));
        $lastSegment = end($segments) ?: 'resource';

        return $this->toSnakeCase($lastSegment);
    }

    /**
     * Extract the local/fragment name from a full IRI.
     * "https://localhost/#Book" → "Book"
     * "http://www.w3.org/ns/hydra/core#Collection" → "Collection"
     * "https://localhost/admin/books" → "books"
     */
    private function localName(string $iri): string
    {
        if (str_contains($iri, '#')) {
            return substr($iri, strrpos($iri, '#') + 1);
        }

        return basename($iri);
    }

    /**
     * Extract the property name from an @id with fragment.
     * "https://localhost/#Entrypoint/book" → "book"
     */
    private function fragmentName(?string $iri): ?string
    {
        if (null === $iri) {
            return null;
        }

        if (str_contains($iri, '/')) {
            return substr($iri, strrpos($iri, '/') + 1);
        }

        if (str_contains($iri, '#')) {
            return substr($iri, strrpos($iri, '#') + 1);
        }

        return $iri;
    }

    private function toSnakeCase(string $input): string
    {
        $result = preg_replace('/[A-Z]/', '_$0', $input);
        $result = strtolower(trim($result, '_'));
        $result = preg_replace('/_+/', '_', $result);

        return trim($result, '_');
    }

    /**
     * Assemble a JSON Schema envelope for a tool's input.
     *
     * Handles: uri property injection, DELETE/GET → minimal schema,
     * no properties → payload fallback, else properties+required.
     *
     * @param array<string, array<string, string>> $properties
     * @param string[]                             $required
     */
    private function assembleSchema(array $properties, array $required, string $method, bool $needsUri): array
    {
        if ($needsUri) {
            $properties = array_merge([
                'uri' => [
                    'type' => 'string',
                    'description' => 'The URI of the resource to operate on',
                ],
            ], $properties);
            array_unshift($required, 'uri');
        }

        if ('DELETE' === $method || 'GET' === $method) {
            $schema = ['type' => 'object', 'properties' => [] === $properties ? new \stdClass() : $properties];
            if ([] !== $required) {
                $schema['required'] = $required;
            }

            return $schema;
        }

        if ([] === $properties || ($needsUri && 1 === \count($properties))) {
            return [
                'type' => 'object',
                'properties' => array_merge($properties, [
                    'payload' => [
                        'type' => 'object',
                        'description' => 'JSON payload for the request',
                    ],
                ]),
            ];
        }

        $schema = ['type' => 'object', 'properties' => $properties];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
