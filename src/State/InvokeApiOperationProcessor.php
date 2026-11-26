<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\InvokeApiOperation;
use App\Mcp\OperationDescription;
use App\Mcp\ResourceDescriber;
use Mcp\Capability\Discovery\SchemaValidator;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Re-describes the target URI on every call, so only an operation the resource offers right now
 * is invoked, and its payload is validated against that operation's schema before going upstream.
 *
 * @implements ProcessorInterface<InvokeApiOperation, CallToolResult>
 */
final readonly class InvokeApiOperationProcessor implements ProcessorInterface
{
    public function __construct(
        #[Autowire(service: 'hydra.client')]
        private HttpClientInterface $hydraClient,
        private ResourceDescriber $describer,
        private LoggerInterface $logger,
        private SchemaValidator $validator = new SchemaValidator(),
    ) {
    }

    /**
     * @param InvokeApiOperation $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CallToolResult
    {
        $method = strtoupper($data->method);

        try {
            $resource = $this->describer->describe($data->uri);
        } catch (\Throwable $e) {
            return self::error("Could not read {$data->uri} to resolve its operations: {$e->getMessage()}");
        }

        $described = $resource->operation($method);
        if (null === $described) {
            $available = implode(', ', array_map(static fn (OperationDescription $o): string => $o->method, $resource->operations));

            return self::error("{$method} is not an operation of {$data->uri}. Available: ".('' === $available ? 'none' : $available).'.');
        }

        $violations = $this->validator->validateAgainstJsonSchema([] === $data->payload ? new \stdClass() : $data->payload, $described->inputSchema);
        if ([] !== $violations) {
            $lines = array_map(static fn (array $v): string => \sprintf('- %s: %s', '' === $v['pointer'] ? '/' : $v['pointer'], $v['message']), $violations);

            return self::error("Payload rejected for {$method} {$data->uri}, nothing was sent upstream:\n".implode("\n", $lines));
        }

        $this->logger->info('Invoking Hydra operation', ['uri' => $data->uri, 'method' => $method]);

        try {
            $response = $this->hydraClient->request($method, $data->uri, $this->requestOptions($method, $data->payload));
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (\Throwable $e) {
            return self::error("{$method} {$data->uri} failed: {$e->getMessage()}");
        }

        if ($status >= 400) {
            return self::error("{$method} {$data->uri} answered HTTP {$status}:\n{$body}");
        }

        return new CallToolResult([new TextContent('' === $body ? "{$method} {$data->uri} succeeded (HTTP {$status})." : $body)]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function requestOptions(string $method, array $payload): array
    {
        return match ($method) {
            'GET' => ['query' => $payload],
            'DELETE' => [],
            'PATCH' => ['json' => $payload, 'headers' => ['Content-Type' => 'application/merge-patch+json']],
            default => ['json' => $payload, 'headers' => ['Content-Type' => 'application/ld+json']],
        };
    }

    private static function error(string $message): CallToolResult
    {
        return CallToolResult::error([new TextContent($message)]);
    }
}
