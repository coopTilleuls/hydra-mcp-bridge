<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Runs a typed tool upstream: a search as GET with its query, an operation with its HTTP method and JSON-LD body.
 */
final readonly class AffordanceInvoker
{
    public function __construct(
        #[Autowire(service: 'hydra.client')]
        private HttpClientInterface $hydraClient,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function invoke(StoredAffordance $config, array $arguments): string
    {
        return match ($config->type) {
            'search' => $this->search($config, $arguments),
            'operation' => $this->operation($config, $arguments),
            default => throw new \RuntimeException(\sprintf('Unknown affordance type "%s".', $config->type)),
        };
    }

    private function search(StoredAffordance $config, array $arguments): string
    {
        $query = [];
        foreach ($config->paramNames as $name) {
            if (isset($arguments[$name]) && '' !== $arguments[$name]) {
                $query[$name] = $arguments[$name];
            }
        }

        return $this->hydraClient->request('GET', $this->target($config, $arguments), [
            'query' => $query,
        ])->getContent();
    }

    private function operation(StoredAffordance $config, array $arguments): string
    {
        $contentType = 'PATCH' === $config->method ? 'application/merge-patch+json' : 'application/ld+json';
        $options = [
            'headers' => ['Content-Type' => $contentType],
        ];
        $target = $this->target($config, $arguments);

        if ('DELETE' !== $config->method) {
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

    private function target(StoredAffordance $config, array $arguments): string
    {
        if (null !== $config->link) {
            return $this->followLink($this->argument($arguments, $config->parentParam ?? ''), $config->link);
        }

        if ($config->declaresArgument('uri')) {
            $uri = $this->argument($arguments, 'uri');
            if ([] === $config->advertisedBy) {
                return $uri;
            }

            foreach ($config->advertisedBy as $resource) {
                if (Iri::path($resource) === Iri::path($uri)) {
                    return $resource;
                }
            }

            throw new \InvalidArgumentException(\sprintf('"%s" is only available on %s. Read "%s" first: run it only if that resource lists it in hydra:operation.', $config->name, implode(', ', $config->advertisedBy), $uri));
        }

        if ('search' === $config->type && $config->declaresArgument('collection')) {
            return $this->argument($arguments, 'collection');
        }

        return $config->uri ?? throw new \LogicException(\sprintf('Tool "%s" has no target.', $config->name));
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

    private function argument(array $arguments, string $name): string
    {
        $value = $arguments[$name] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw new \InvalidArgumentException(\sprintf('Argument "%s" is required.', $name));
        }

        return $value;
    }
}
