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
     * @param \stdClass             $compactJsonLd   the original compact JSON-LD, whose @id is the IRI the client sees
     * @param array<string, string> $entrypointLinks entrypoint link name => collection IRI
     *
     * @return StoredAffordance[]
     */
    public function extractAffordances(JsonLdDocument $doc, \stdClass $compactJsonLd, array $entrypointLinks = []): array
    {
        $node = $doc->first();
        if (null === $node) {
            return [];
        }

        return array_merge(
            $this->parseSearch($node, $compactJsonLd, $entrypointLinks),
            $this->parseInlineOperations($node, $compactJsonLd),
        );
    }

    /**
     * The links of an entrypoint representation: link name => collection IRI.
     *
     * @return array<string, string>
     */
    public function entrypointLinks(\stdClass $compactEntrypoint): array
    {
        $links = [];
        foreach (get_object_vars($compactEntrypoint) as $name => $value) {
            if ($value instanceof \stdClass) {
                $value = $value->{'@id'} ?? null;
            }

            if ('@' !== ($name[0] ?? '') && \is_string($value)) {
                $links[$name] = $value;
            }
        }

        return $links;
    }

    /**
     * The documented classes a client can reach by following links from the entrypoint, in documentation order.
     * Classes only met as errors (hydra:Error, ConstraintViolation) are never reached.
     *
     * @param array<string, string> $entrypointLinks entrypoint link name => collection IRI
     *
     * @return list<array{id: string, title: string, description: ?string}>
     */
    public function reachableClasses(JsonLdDocument $docs, array $entrypointLinks): array
    {
        $docsNode = $docs->first();
        if (null === $docsNode) {
            return [];
        }

        $classMap = $this->buildClassMap($docsNode);
        $pending = array_values(array_intersect_key($this->entrypointLinkClasses($docs), $entrypointLinks));
        $reached = [];
        while (null !== $classId = array_shift($pending)) {
            $class = $this->resolveClass($classId, $classMap);
            $id = $class?->nodeId();
            if (null === $class || null === $id || isset($reached[$id])) {
                continue;
            }

            $reached[$id] = true;
            foreach ($class->all(Vocab::SUPPORTED_PROPERTY_PROP) as $supportedProp) {
                $property = $supportedProp->first(Vocab::PROPERTY);
                if (null !== $property && \in_array(Vocab::LINK, $property->types(), true) && null !== $target = $this->resolveTargetClass($property)) {
                    $pending[] = $target;
                }
            }
        }

        $classes = [];
        foreach ($classMap as $id => $class) {
            if (isset($reached[$id])) {
                $classes[] = ['id' => $id, 'title' => $class->value(Vocab::TITLE) ?? $this->localName($id), 'description' => $class->value(Vocab::DESCRIPTION)];
            }
        }

        return $classes;
    }

    /**
     * @return array<string, string> entrypoint link name => @id of the class its collection holds
     */
    public function entrypointLinkClasses(JsonLdDocument $docs): array
    {
        $docsNode = $docs->first();
        if (null === $docsNode) {
            return [];
        }

        $links = [];
        foreach ($this->buildClassMap($docsNode) as $id => $class) {
            if ('Entrypoint' !== $this->localName($id) && 'Entrypoint' !== $class->value(Vocab::TITLE)) {
                continue;
            }

            foreach ($class->all(Vocab::SUPPORTED_PROPERTY_PROP) as $supportedProp) {
                $property = $supportedProp->first(Vocab::PROPERTY);
                $name = $property?->value(Vocab::LABEL) ?? $this->fragmentName($property?->nodeId());
                $classId = null !== $property ? $this->resolveTargetClass($property) : null;
                if (null !== $name && null !== $classId) {
                    $links[$name] = $classId;
                }
            }
        }

        return $links;
    }

    public function isSameClass(string $classId, string $otherClassId): bool
    {
        return $classId === $otherClassId || $this->localName($classId) === $this->localName($otherClassId);
    }

    public function entrypointIri(JsonLdDocument $docs): ?string
    {
        return $docs->first()?->id(Vocab::ENTRYPOINT);
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
                $object = $assertion->id(Vocab::OBJECT);
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
     * Operations on the managed class and on the hydra:Link properties that reach it or start from it.
     *
     * Class operations are supported by instances and take the target "uri". An operation on an entrypoint
     * link targets the collection the entrypoint gives for that link. An operation on another class's link
     * takes the IRI of the resource holding the link, named after that class; the link is followed at call time.
     *
     * @param JsonLdDocument        $docs            the expanded docs.jsonld document
     * @param string                $managedClassId  the @id (or local name) of the managed class
     * @param array<string, string> $entrypointLinks entrypoint link name => collection IRI
     *
     * @return StoredAffordance[]
     */
    public function extractClassOperations(JsonLdDocument $docs, string $managedClassId, array $entrypointLinks): array
    {
        $docsNode = $docs->first();
        if (null === $docsNode) {
            return [];
        }

        $classMap = $this->buildClassMap($docsNode);
        $targetClass = $this->resolveClass($managedClassId, $classMap);
        if (null === $targetClass) {
            return [];
        }

        $managedClassId = $targetClass->nodeId() ?? $managedClassId;
        $resourceName = $targetClass->value(Vocab::TITLE) ?? $this->localName($managedClassId);
        $affordances = $this->linkOperations($managedClassId, $entrypointLinks, $classMap);

        foreach ($targetClass->all(Vocab::SUPPORTED_OPERATION) as $operation) {
            $method = $operation->value(Vocab::METHOD);
            if (null === $method) {
                continue;
            }

            $method = strtoupper($method);
            $affordances[] = $this->buildOperationAffordance(
                $operation,
                $method,
                $resourceName,
                $this->expectedClassId($operation, $managedClassId),
                $classMap,
                ['uri' => 'The URI of the resource to operate on'],
            );
        }

        $byName = [];
        foreach ($affordances as $affordance) {
            $byName[$affordance->name] ??= $affordance;
        }

        return array_values($byName);
    }

    /**
     * @param array<string, string>     $entrypointLinks
     * @param array<string, JsonLdNode> $classMap
     *
     * @return StoredAffordance[]
     */
    private function linkOperations(string $managedClassId, array $entrypointLinks, array $classMap): array
    {
        $affordances = [];
        $managedName = $this->localName($managedClassId);

        foreach ($classMap as $domainId => $domainClass) {
            $isEntrypoint = 'Entrypoint' === $this->localName($domainId) || 'Entrypoint' === $domainClass->value(Vocab::TITLE);
            $domainName = $domainClass->value(Vocab::TITLE) ?? $this->localName($domainId);

            foreach ($domainClass->all(Vocab::SUPPORTED_PROPERTY_PROP) as $supportedProp) {
                $property = $supportedProp->first(Vocab::PROPERTY);
                if (null === $property || !\in_array(Vocab::LINK, $property->types(), true)) {
                    continue;
                }

                $operations = $property->all(Vocab::SUPPORTED_OPERATION);
                $rangeId = $this->resolveTargetClass($property);
                $reachesManaged = null !== $rangeId && $this->localName($rangeId) === $managedName;
                $startsFromManaged = !$isEntrypoint && $this->localName($domainId) === $managedName;
                if ([] === $operations || (!$reachesManaged && !$startsFromManaged)) {
                    continue;
                }

                $linkName = $property->value(Vocab::LABEL) ?? $this->fragmentName($property->nodeId());
                if (null === $linkName) {
                    continue;
                }

                if ($isEntrypoint && !isset($entrypointLinks[$linkName])) {
                    continue;
                }

                foreach ($operations as $operation) {
                    $method = $operation->value(Vocab::METHOD);
                    if (null === $method) {
                        continue;
                    }

                    $method = strtoupper($method);
                    $expects = $this->expectedClassId($operation, $rangeId);

                    if ($isEntrypoint) {
                        $affordances[] = $this->buildOperationAffordance($operation, $method, $linkName, $expects, $classMap, [], uri: $entrypointLinks[$linkName]);
                        continue;
                    }

                    $parentParam = $this->toSnakeCase($domainName);
                    if ($this->bodyDeclares($expects, $classMap, $method, $parentParam)) {
                        $parentParam .= '_iri';
                    }

                    $affordances[] = $this->buildOperationAffordance(
                        $operation,
                        $method,
                        $linkName,
                        $expects,
                        $classMap,
                        [$parentParam => \sprintf('IRI of the %s whose "%s" link is the target', $domainName, $linkName)],
                        link: $linkName,
                        parentParam: $parentParam,
                    );
                }
            }
        }

        return $affordances;
    }

    private function expectedClassId(JsonLdNode $operation, ?string $default): ?string
    {
        return $operation->id(Vocab::EXPECTS) ?? $default;
    }

    /**
     * @param array<string, JsonLdNode> $classMap
     */
    private function bodyDeclares(?string $classId, array $classMap, string $method, string $name): bool
    {
        if ('GET' === $method || 'DELETE' === $method) {
            return false;
        }

        return isset($this->collectBodyProperties($this->resolveClass($classId, $classMap), $method)[0][$name]);
    }

    /**
     * @param array<string, JsonLdNode> $classMap
     * @param array<string, string>     $targetParams arguments locating the target, name => description
     */
    private function buildOperationAffordance(
        JsonLdNode $operation,
        string $method,
        string $resourceName,
        ?string $expectsClassId,
        array $classMap,
        array $targetParams,
        ?string $uri = null,
        ?string $link = null,
        ?string $parentParam = null,
    ): StoredAffordance {
        // Use the operation's own name (hydra:title) as tool name — never guess from HTTP method
        $operationTitle = $operation->value(Vocab::TITLE);
        $toolName = $this->toSnakeCase($operationTitle ?? $resourceName);

        $description = $operation->value(Vocab::DESCRIPTION)
            ?? $operationTitle
            ?? $toolName;

        [$properties, $required] = 'GET' === $method || 'DELETE' === $method
            ? [[], []]
            : $this->collectBodyProperties($this->resolveClass($expectsClassId, $classMap), $method);

        return new StoredAffordance(
            name: $toolName,
            description: $description,
            inputSchema: $this->assembleSchema($properties, $required, $method, $targetParams),
            type: 'operation',
            uri: $uri,
            method: $method,
            link: $link,
            parentParam: $parentParam,
        );
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
     * Resolve the target class @id from an expanded property's hydra:memberAssertion, falling back to rdfs:range.
     */
    private function resolveTargetClass(JsonLdNode $property): ?string
    {
        // Hydra Core 5.2: a rdf:type member assertion makes every member an instance of its object
        foreach ($property->all(Vocab::MEMBER_ASSERTION) as $assertion) {
            if (Vocab::RDF_TYPE === $assertion->id(Vocab::PROPERTY)) {
                $object = $assertion->id(Vocab::OBJECT);
                if (null !== $object) {
                    return $object;
                }
            }
        }

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
     * A search on an entrypoint collection targets it; any other collection of that kind is given at call time.
     *
     * @param array<string, string> $entrypointLinks
     *
     * @return StoredAffordance[]
     */
    private function parseSearch(JsonLdNode $node, \stdClass $compactJsonLd, array $entrypointLinks): array
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
        $paramNames = array_keys($inputSchema['properties']);
        $collectionUri = $compactJsonLd->{'@id'} ?? $node->nodeId();
        $entrypointCollections = array_map(Iri::path(...), $entrypointLinks);
        $isEntrypointCollection = \is_string($collectionUri) && \in_array(Iri::path($collectionUri), $entrypointCollections, true);

        if (!$isEntrypointCollection) {
            $inputSchema['properties'] = ['collection' => ['type' => 'string', 'description' => "IRI of the {$resourceName} collection to search"]] + $inputSchema['properties'];
            $inputSchema['required'] = ['collection', ...$inputSchema['required'] ?? []];
        }

        return [
            new StoredAffordance(
                name: 'search_'.$resourceName,
                description: "Search {$resourceName} by: ".implode(', ', $paramNames),
                inputSchema: $inputSchema,
                type: 'search',
                uri: $isEntrypointCollection ? $collectionUri : null,
                paramNames: $paramNames,
            ),
        ];
    }

    /**
     * Inline hydra:operation of a resource: run only on the resources that advertised it.
     *
     * @return StoredAffordance[]
     */
    private function parseInlineOperations(JsonLdNode $node, \stdClass $compactJsonLd): array
    {
        $operations = $node->all(Vocab::OPERATION_PROP);
        if ([] === $operations) {
            return [];
        }

        $resourceUri = $compactJsonLd->{'@id'} ?? $node->nodeId();
        if (!\is_string($resourceUri)) {
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
            $toolName = $this->toSnakeCase($operationTitle ?? $resourceName);
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

            $affordances[] = new StoredAffordance(
                name: $toolName,
                description: $description,
                inputSchema: $this->assembleSchema($properties, $required, $method, ['uri' => 'The URI of a resource that lists this operation in its hydra:operation']),
                type: 'operation',
                method: $method,
                advertisedBy: [$resourceUri],
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
     * "https://localhost/admin/books" → "books".
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
     * "https://localhost/#Entrypoint/book" → "book".
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
     * Assemble a JSON Schema envelope for a tool's input: the required target arguments, then the body
     * properties, or a free "payload" when a body is expected but its properties are unknown.
     *
     * @param array<string, array<string, string>> $properties
     * @param string[]                             $required
     * @param array<string, string>                $targetParams name => description
     */
    private function assembleSchema(array $properties, array $required, string $method, array $targetParams): array
    {
        if ([] === $properties && 'DELETE' !== $method && 'GET' !== $method) {
            $properties = ['payload' => ['type' => 'object', 'description' => 'JSON payload for the request']];
        }

        $targets = array_map(static fn (string $description): array => ['type' => 'string', 'description' => $description], $targetParams);
        $properties = $targets + $properties;
        $required = [...array_keys($targetParams), ...$required];

        $schema = ['type' => 'object', 'properties' => [] === $properties ? new \stdClass() : $properties];
        if ([] !== $required) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
