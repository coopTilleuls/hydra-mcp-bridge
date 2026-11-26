<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\McpTool;
use App\State\InvokeApiOperationProcessor;

#[McpTool(
    name: self::TOOL_NAME,
    description: 'Invoke an operation that read_api_resource listed for a resource. Pass the resource URI, the operation method, and a payload matching the operation inputSchema (query parameters for GET, request body otherwise). Only operations listed for that URI are accepted.',
    processor: InvokeApiOperationProcessor::class,
    structuredContent: false,
)]
class InvokeApiOperation
{
    public const string TOOL_NAME = 'invoke_api_operation';

    #[ApiProperty(description: 'URI of the resource the operation applies to: the resource itself, or a member @id for memberOperations')]
    public string $uri;

    #[ApiProperty(description: 'HTTP method of the operation', schema: ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']])]
    public string $method;

    /** @var array<string, mixed> */
    #[ApiProperty(description: 'Payload matching the operation inputSchema', schema: ['type' => 'object'])]
    public array $payload = [];
}
