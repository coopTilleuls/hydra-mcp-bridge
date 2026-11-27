<?php

declare(strict_types=1);

namespace App\Mcp\Groups;

use App\JsonLd\Expander;
use App\JsonLd\JsonLdDocument;
use App\Mcp\ApiDocumentation;
use App\Mcp\HydraResponseParser;
use App\Mcp\StoredAffordance;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The typed tools of each documented class, read from the API documentation the entrypoint links to, with no
 * per-client state: the tools a dynamic session discovers by navigating, minus the inline-only operations.
 * A class's search tool comes from the hydra:search of the entrypoint collection holding it.
 */
final class ToolGroups implements ResetInterface
{
    private const string ENTRYPOINT = '/';

    /** @var array{docs: JsonLdDocument, links: array<string, string>}|false|null */
    private array|false|null $api = null;

    /** @var array<string, list<StoredAffordance>> */
    private array $tools = [];

    public function __construct(
        #[Autowire(service: 'hydra.client')]
        private readonly HttpClientInterface $hydraClient,
        private readonly ApiDocumentation $documentation,
        private readonly Expander $expander,
        private readonly HydraResponseParser $parser,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, Group> by name
     */
    public function groups(): array
    {
        $api = $this->api();
        if (null === $api) {
            return [];
        }

        $groups = [];
        foreach ($this->parser->reachableClasses($api['docs'], $api['links']) as $class) {
            $group = new Group(
                self::name($class['title']),
                $class['title'],
                $class['description'] ?? \sprintf('Operations on %s resources.', $class['title']),
                $class['id'],
            );
            if ([] !== $this->classOperations($group)) {
                $groups[$group->name] = $group;
            }
        }

        return $groups;
    }

    /**
     * @return list<StoredAffordance>|null null for an unknown group
     */
    public function tools(string $name): ?array
    {
        $group = $this->groups()[$name] ?? null;
        if (null === $group) {
            return null;
        }

        return $this->tools[$name] ??= [...$this->classOperations($group), ...$this->searches($group)];
    }

    public function find(string $toolName): ?StoredAffordance
    {
        $groups = $this->groups();
        foreach ($groups as $group) {
            foreach ($this->classOperations($group) as $tool) {
                if ($toolName === $tool->name) {
                    return $tool;
                }
            }
        }

        if (!str_starts_with($toolName, 'search_')) {
            return null;
        }

        foreach ($groups as $group) {
            foreach ($this->searches($group) as $tool) {
                if ($toolName === $tool->name) {
                    return $tool;
                }
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->api = null;
        $this->tools = [];
    }

    /**
     * @return list<StoredAffordance>
     */
    private function classOperations(Group $group): array
    {
        $api = $this->api();

        return null === $api ? [] : $this->parser->extractClassOperations($api['docs'], $group->classId, $api['links']);
    }

    /**
     * @return list<StoredAffordance>
     */
    private function searches(Group $group): array
    {
        $api = $this->api();
        if (null === $api) {
            return [];
        }

        $searches = [];
        foreach ($this->parser->entrypointLinkClasses($api['docs']) as $link => $classId) {
            if (!isset($api['links'][$link]) || !$this->parser->isSameClass($classId, $group->classId)) {
                continue;
            }

            try {
                $collection = json_decode($this->hydraClient->request('GET', $api['links'][$link])->getContent(), false, 512, \JSON_THROW_ON_ERROR);
                $expanded = $this->expander->expand(clone $collection);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to read a collection for its search', ['link' => $link, 'error' => $e->getMessage()]);
                continue;
            }

            foreach ($this->parser->extractAffordances($expanded, $collection, $api['links']) as $affordance) {
                if ('search' === $affordance->type) {
                    $searches[] = $affordance;
                }
            }
        }

        return $searches;
    }

    /**
     * @return array{docs: JsonLdDocument, links: array<string, string>}|null
     */
    private function api(): ?array
    {
        if (null === $this->api) {
            $this->api = false;
            try {
                $response = $this->hydraClient->request('GET', self::ENTRYPOINT);
                $entrypoint = json_decode($response->getContent(), false, 512, \JSON_THROW_ON_ERROR);
                $docs = $this->documentation->forResponse($response);
                if ($entrypoint instanceof \stdClass && null !== $docs) {
                    $this->api = ['docs' => $docs, 'links' => $this->parser->entrypointLinks($entrypoint)];
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to read the entrypoint for groups', ['error' => $e->getMessage()]);
            }
        }

        return $this->api ?: null;
    }

    private static function name(string $title): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $title))), '-');
    }
}
