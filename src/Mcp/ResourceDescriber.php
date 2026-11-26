<?php

declare(strict_types=1);

namespace App\Mcp;

use App\JsonLd\Expander;
use App\JsonLd\JsonLdDocument;
use App\JsonLd\Vocab;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads a resource and describes the operations available on it, from the request alone:
 * class-level operations from docs.jsonld, replaced by the resource's inline hydra:operation
 * when it carries any, and its hydra:search as the GET operation.
 */
final readonly class ResourceDescriber
{
    public function __construct(
        #[Autowire(service: 'hydra.client')]
        private HttpClientInterface $hydraClient,
        private Expander $expander,
        private HydraResponseParser $parser,
        private ApiDocumentation $documentation,
        private LoggerInterface $logger,
    ) {
    }

    public function describe(string $uri): DescribedResource
    {
        $response = $this->hydraClient->request('GET', $uri);
        $resource = json_decode($response->getContent(), false, 512, \JSON_THROW_ON_ERROR);

        try {
            // sweetrdf/json-ld strips @context from its input
            $expanded = $this->expander->expand(clone $resource);
        } catch (\Throwable $e) {
            $this->logger->warning('JSON-LD expansion failed, no operations described', ['uri' => $uri, 'error' => $e->getMessage()]);
            $expanded = new JsonLdDocument([]);
        }

        $self = \is_string($resource->{'@id'} ?? null) ? $resource->{'@id'} : $uri;
        $types = $expanded->first()?->types() ?? [];
        $isCollection = \in_array(Vocab::COLLECTION, $types, true);
        $docs = $this->documentation->forResponse($response);

        $classOperations = ['collection' => [], 'member' => []];
        if (null !== $docs) {
            $candidates = $isCollection ? [] : array_values(array_diff($types, [Vocab::COLLECTION]));
            if (null !== $managedClass = $this->parser->resolveManagedClass($expanded, $resource)) {
                $candidates[] = $managedClass;
            }

            $classOperations = $this->parser->describeClassOperations($docs, $candidates);
        }

        $inline = $this->parser->describeInlineOperations($expanded, $docs);
        $operations = [] !== $inline ? $inline : $classOperations[$isCollection ? 'collection' : 'member'];

        if (null !== $search = $this->parser->describeSearch($expanded)) {
            $operations[] = $search;
        }

        return new DescribedResource(
            uri: $self,
            resource: $resource,
            operations: self::onePerMethod($operations),
            memberOperations: $isCollection ? self::onePerMethod($classOperations['member']) : [],
        );
    }

    /**
     * @param list<OperationDescription> $operations
     *
     * @return list<OperationDescription> the last description of each method wins
     */
    private static function onePerMethod(array $operations): array
    {
        $byMethod = [];
        foreach ($operations as $operation) {
            $byMethod[$operation->method] = $operation;
        }

        return array_values($byMethod);
    }
}
