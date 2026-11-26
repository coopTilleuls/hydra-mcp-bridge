<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Mcp\DiscoveryMode;
use App\Mcp\DiscoveryModeResolver;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Functional tests for the MCP JSON-RPC endpoint.
 *
 * Requires a real upstream Hydra API running at HYDRA_API_BASE_URL (default: https://localhost).
 * Tests exercise the full 3-phase lazy discovery model and dynamic tool execution.
 */
class McpEndpointTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = static::createClient();
    }

    /**
     * Send a JSON-RPC request to /mcp and return the decoded result.
     *
     * Handles both plain JSON and SSE (text/event-stream) responses.
     * For SSE, returns the last JSON-RPC response message found.
     *
     * @return array{result?: array, error?: array, id: int, jsonrpc: string}
     */
    private function jsonRpc(?string $sessionId, int $id, string $method, array $params = []): array
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $sessionId) {
            $headers['HTTP_MCP_SESSION_ID'] = $sessionId;
        }

        $this->browser->request('POST', '/mcp', [], [], $headers, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]));

        $response = $this->browser->getResponse();
        $contentType = $response->headers->get('Content-Type', '');

        // StreamedResponse::getContent() returns false — capture via output buffering
        $body = $response->getContent();
        if (false === $body) {
            ob_start();
            $response->sendContent();
            $body = ob_get_clean();
        }

        if (str_contains($contentType, 'text/event-stream')) {
            return $this->parseLastSseResponse($body, $id);
        }

        return json_decode($body, true);
    }

    /**
     * Parse SSE event stream and return the last JSON-RPC response (matching the given id).
     * Also captures all messages for assertion purposes in $this->lastSseMessages.
     *
     * @var array<int, array>
     */
    private array $lastSseMessages = [];

    private function parseLastSseResponse(string $body, int $expectedId): array
    {
        $this->lastSseMessages = $this->parseSseEvents($body);

        // Find the response matching our request ID
        foreach (array_reverse($this->lastSseMessages) as $msg) {
            if (isset($msg['id']) && $msg['id'] === $expectedId) {
                return $msg;
            }
        }

        // Fallback: return the last message
        return end($this->lastSseMessages) ?: ['error' => 'No SSE messages found'];
    }

    /**
     * Parse SSE body into array of decoded JSON-RPC messages.
     *
     * Format: "event: message\ndata: {json}\n\n"
     *
     * @return array<int, array>
     */
    private function parseSseEvents(string $body): array
    {
        $messages = [];
        $blocks = preg_split('/\n\n+/', trim($body));

        foreach ($blocks as $block) {
            $block = trim($block);
            if ('' === $block) {
                continue;
            }

            // Extract data lines
            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'data: ')) {
                    $json = substr($line, 6);
                    $decoded = json_decode($json, true);
                    if (null !== $decoded) {
                        $messages[] = $decoded;
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Initialize an MCP session and return the session ID.
     */
    private function initializeSession(): string
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];

        $this->browser->request('POST', '/mcp', [], [], $headers, json_encode([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit-test', 'version' => '1.0'],
            ],
        ]));

        $response = $this->browser->getResponse();
        $sessionId = $response->headers->get('Mcp-Session-Id');

        $this->assertNotNull($sessionId, 'Session ID must be returned on initialize');
        $this->assertNotEmpty($sessionId);

        $body = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('result', $body, 'Initialize must return a result');
        $this->assertArrayHasKey('serverInfo', $body['result'], 'Initialize result must contain serverInfo');

        return $sessionId;
    }

    /**
     * Navigate entrypoint + collection to trigger Phase 1 + Phase 2 discovery.
     */
    private function discoverTools(string $sessionId, string $collectionUri): void
    {
        // Phase 1: entrypoint
        $this->jsonRpc($sessionId, 100, 'tools/call', [
            'name' => 'read_api_resource',
            'arguments' => ['uri' => '/'],
        ]);

        // Phase 2: collection visit (triggers tool registration)
        $this->jsonRpc($sessionId, 101, 'tools/call', [
            'name' => 'read_api_resource',
            'arguments' => ['uri' => $collectionUri],
        ]);
    }

    /**
     * Get the list of tools and return as an array keyed by tool name.
     *
     * @return array<string, array>
     */
    private function getToolsByName(string $sessionId, int $id = 200): array
    {
        $response = $this->jsonRpc($sessionId, $id, 'tools/list');
        $tools = $response['result']['tools'] ?? [];

        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool['name']] = $tool;
        }

        return $byName;
    }

    /**
     * Extract text content from a tools/call response.
     */
    private function extractText(array $response): string
    {
        $content = $response['result']['content'] ?? [];

        $texts = [];
        foreach ($content as $item) {
            if ('text' === ($item['type'] ?? '')) {
                $texts[] = $item['text'];
            }
        }

        return implode("\n", $texts);
    }

    private function statelessRpc(int $id, string $method, array $params = []): array
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'phpunit-test', 'version' => '1.0'],
        ];

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28',
            'HTTP_MCP_METHOD' => $method,
        ];
        if (isset($params['name'])) {
            $headers['HTTP_MCP_NAME'] = $params['name'];
        }

        $this->browser->request('POST', '/mcp', [], [], $headers, json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]));

        $response = $this->browser->getResponse();
        $this->assertNull($response->headers->get('Mcp-Session-Id'), 'A 2026-07-28 answer must not mint a session');

        $body = $response->getContent();
        if (false === $body) {
            ob_start();
            $response->sendContent();
            $body = ob_get_clean();
        }

        if (str_contains($response->headers->get('Content-Type', ''), 'text/event-stream')) {
            return $this->parseLastSseResponse($body, $id);
        }

        return json_decode($body, true);
    }

    private function statelessToolNames(int $id): array
    {
        $names = array_column($this->statelessRpc($id, 'tools/list')['result']['tools'] ?? [], 'name');
        sort($names);

        return $names;
    }

    private function operationsByMethod(array $operations): array
    {
        return array_column($operations, null, 'method');
    }

    public function testStatelessToolListNeverChanges(): void
    {
        $expected = ['invoke_api_operation', 'read_api_resource'];

        $this->assertSame($expected, $this->statelessToolNames(1));

        $this->statelessRpc(2, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/']]);
        $this->assertSame($expected, $this->statelessToolNames(3));

        $this->statelessRpc(4, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/books']]);
        $this->assertSame($expected, $this->statelessToolNames(5));
    }

    public function testStatelessCollectionReadReturnsOperationsWithSchemas(): void
    {
        $response = $this->statelessRpc(1, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/books']]);

        $this->assertArrayHasKey('result', $response, json_encode($response));
        $this->assertFalse($response['result']['isError'] ?? false, json_encode($response['result']));
        $structured = $response['result']['structuredContent'] ?? null;
        $this->assertIsArray($structured);
        $this->assertSame('/books', $structured['resource']['@id']);
        $this->assertSame($structured, json_decode($this->extractText($response), true), 'Text content mirrors structuredContent for clients that only read text');

        $operations = $this->operationsByMethod($structured['operations']);
        $this->assertSame('/books', $operations['GET']['uri']);
        $this->assertArrayHasKey('title', $operations['GET']['inputSchema']['properties'], 'Search mapping exposed as GET input');
        $this->assertSame('/books', $operations['POST']['uri']);
        $this->assertContains('book', $operations['POST']['inputSchema']['required']);
        $this->assertArrayNotHasKey('uri', $operations['POST']['inputSchema']['properties']);

        $memberOperations = $this->operationsByMethod($structured['memberOperations']);
        $this->assertArrayHasKey('PATCH', $memberOperations);
        $this->assertArrayHasKey('DELETE', $memberOperations);
        $this->assertArrayHasKey('condition', $memberOperations['PATCH']['inputSchema']['properties']);
        $this->assertArrayNotHasKey('required', $memberOperations['PATCH']['inputSchema']);
    }

    public function testStatelessInlineOperationsOverrideClassOperations(): void
    {
        $upstream = new MockHttpClient(static function (string $method, string $url): MockResponse {
            $docsLink = ['link' => ['<https://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"']];

            return match ($url) {
                'https://localhost/orders/1' => new MockResponse(json_encode([
                    '@context' => ['@vocab' => 'https://localhost/docs.jsonld#', 'hydra' => 'http://www.w3.org/ns/hydra/core#'],
                    '@id' => '/orders/1',
                    '@type' => 'Order',
                    'status' => 'shipped',
                    'hydra:operation' => [
                        ['@type' => 'hydra:Operation', 'hydra:method' => 'GET', 'hydra:title' => 'getOrder'],
                        ['@type' => 'hydra:Operation', 'hydra:method' => 'POST', 'hydra:title' => 'returnOrder', 'hydra:description' => 'Return a shipped order'],
                    ],
                ]), ['response_headers' => $docsLink]),
                'https://localhost/docs.jsonld' => new MockResponse(json_encode([
                    '@context' => ['@vocab' => 'http://www.w3.org/ns/hydra/core#'],
                    '@id' => '/docs.jsonld',
                    '@type' => 'ApiDocumentation',
                    'supportedClass' => [[
                        '@id' => '#Order',
                        '@type' => 'Class',
                        'title' => 'Order',
                        'supportedOperation' => [
                            ['@type' => 'Operation', 'method' => 'GET', 'title' => 'getOrder'],
                            ['@type' => 'Operation', 'method' => 'PATCH', 'title' => 'patchOrder'],
                            ['@type' => 'Operation', 'method' => 'DELETE', 'title' => 'deleteOrder'],
                        ],
                    ]],
                ])),
                default => new MockResponse('', ['http_code' => 404]),
            };
        }, 'https://localhost');
        static::getContainer()->set('hydra.client', $upstream);

        $response = $this->statelessRpc(1, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/orders/1']]);

        $this->assertArrayHasKey('result', $response, json_encode($response));
        $operations = $this->operationsByMethod($response['result']['structuredContent']['operations']);
        $this->assertEqualsCanonicalizing(['GET', 'POST'], array_keys($operations), 'Inline operations replace the class-level set of this resource');
        $this->assertSame('returnOrder', $operations['POST']['title']);
        $this->assertSame('/orders/1', $operations['POST']['uri']);
    }

    public function testStatelessInvocationRejectsInvalidPayloadWithoutUpstreamCall(): void
    {
        $before = $this->statelessRpc(1, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/books']]);
        $totalBefore = $before['result']['structuredContent']['resource']['hydra:totalItems'];

        $response = $this->statelessRpc(2, 'tools/call', [
            'name' => 'invoke_api_operation',
            'arguments' => [
                'uri' => '/books',
                'method' => 'POST',
                'payload' => ['condition' => 'https://schema.org/NewCondition'],
            ],
        ]);

        $this->assertArrayHasKey('result', $response, json_encode($response));
        $this->assertTrue($response['result']['isError'] ?? false, 'Invalid payload must be a tool error');
        $this->assertStringContainsString('book', $this->extractText($response), 'Violation names the missing required property');

        $after = $this->statelessRpc(3, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/books']]);
        $this->assertSame($totalBefore, $after['result']['structuredContent']['resource']['hydra:totalItems'], 'No book was created upstream');
    }

    public function testStatelessInvocationUpdatesMember(): void
    {
        $collection = $this->statelessRpc(1, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => '/books?title=Hyperion']]);
        $member = $collection['result']['structuredContent']['resource']['hydra:member'][0];
        $originalCondition = $member['condition'];
        $target = 'https://schema.org/NewCondition' === $originalCondition ? 'https://schema.org/DamagedCondition' : 'https://schema.org/NewCondition';

        $patch = $this->statelessRpc(2, 'tools/call', [
            'name' => 'invoke_api_operation',
            'arguments' => ['uri' => $member['@id'], 'method' => 'PATCH', 'payload' => ['condition' => $target]],
        ]);
        $this->assertFalse($patch['result']['isError'] ?? false, $this->extractText($patch));

        $read = $this->statelessRpc(3, 'tools/call', ['name' => 'read_api_resource', 'arguments' => ['uri' => $member['@id']]]);
        $this->assertSame($target, $read['result']['structuredContent']['resource']['condition']);
        $this->assertArrayHasKey('PATCH', $this->operationsByMethod($read['result']['structuredContent']['operations']), 'A member read lists its class-level operations');

        $this->statelessRpc(4, 'tools/call', [
            'name' => 'invoke_api_operation',
            'arguments' => ['uri' => $member['@id'], 'method' => 'PATCH', 'payload' => ['condition' => $originalCondition]],
        ]);
    }

    // =========================================================================
    // Test: Phase 1 — Entrypoint registers no tools
    // =========================================================================

    public function testPhase1EntrypointNoToolsRegistered(): void
    {
        $sid = $this->initializeSession();

        // Before any navigation: exactly 1 static tool
        $tools = $this->getToolsByName($sid, 1);
        $this->assertCount(1, $tools, 'Only read_api_resource before navigation');
        $this->assertArrayHasKey('read_api_resource', $tools);

        // Phase 1: navigate entrypoint
        $this->jsonRpc($sid, 2, 'tools/call', [
            'name' => 'read_api_resource',
            'arguments' => ['uri' => '/'],
        ]);

        // After entrypoint: still exactly 1 tool (no eager discovery)
        $tools = $this->getToolsByName($sid, 3);
        $this->assertCount(1, $tools, 'Still only read_api_resource after entrypoint (Phase 1)');
        $this->assertArrayHasKey('read_api_resource', $tools);
    }

    // =========================================================================
    // Test: Phase 2 — Collection visit discovers dynamic tools
    // =========================================================================

    public function testPhase2CollectionDiscoversDynamicTools(): void
    {
        $sid = $this->initializeSession();

        // Phase 1: entrypoint
        $this->jsonRpc($sid, 1, 'tools/call', [
            'name' => 'read_api_resource',
            'arguments' => ['uri' => '/'],
        ]);

        // Phase 2: collection visit — should return SSE with tools/list_changed notification
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_MCP_SESSION_ID' => $sid];
        $this->browser->request('POST', '/mcp', [], [], $headers, json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => [
                'name' => 'read_api_resource',
                'arguments' => ['uri' => '/books'],
            ],
        ]));

        $body = $this->browser->getInternalResponse()->getContent();
        $this->assertStringContainsString('"method":"notifications\\/tools\\/list_changed"', $body, 'Clients only refetch tools/list on this notification');

        // tools/list should now have CRUD tools for books
        $tools = $this->getToolsByName($sid, 3);
        $this->assertArrayHasKey('read_api_resource', $tools, 'Static tool still present');
        $this->assertArrayHasKey('search_books', $tools, 'search_books discovered');

        // Check for at least some CRUD tools
        $bookTools = array_filter(array_keys($tools), fn ($name) => str_contains($name, 'book'));
        $this->assertGreaterThanOrEqual(3, \count($bookTools), 'At least 3 book-related tools discovered');
    }

    // A FrankenPHP worker keeps the kernel between requests and only resets services; disableReboot() does the same.
    public function testDynamicToolsDoNotLeakAcrossSessionsInWorkerMode(): void
    {
        $this->browser->disableReboot();

        $first = $this->initializeSession();
        $this->discoverTools($first, '/books');
        $this->assertArrayHasKey('search_books', $this->getToolsByName($first, 1));

        $second = $this->initializeSession();
        $this->assertSame(['read_api_resource'], array_keys($this->getToolsByName($second, 2)));

        $call = $this->jsonRpc($second, 3, 'tools/call', ['name' => 'search_books', 'arguments' => ['title' => 'Hyperion']]);
        $this->assertTrue(isset($call['error']) || ($call['result']['isError'] ?? false), 'A tool discovered by another session must not run');

        $this->assertArrayHasKey('search_books', $this->getToolsByName($first, 4), 'The discovering session keeps its tools');
        $own = $this->jsonRpc($first, 5, 'tools/call', ['name' => 'search_books', 'arguments' => ['title' => 'Hyperion']]);
        $this->assertFalse($own['result']['isError'] ?? true);
        $this->assertStringContainsString('Hyperion', $this->extractText($own));
    }

    public function testDynamicModeInitializeDescribesTypedTools(): void
    {
        $instructions = $this->initializeInstructions();

        $this->assertStringContainsString('New tools (create, read, update, delete, search) appear automatically', $instructions);
        $this->assertStringNotContainsString('invoke_api_operation', $instructions);
    }

    public function testStaticLegacyModeInitializeDescribesInvokeApiOperation(): void
    {
        static::getContainer()->set(DiscoveryModeResolver::class, new DiscoveryModeResolver(DiscoveryMode::Static));

        $instructions = $this->initializeInstructions();

        $this->assertStringContainsString('invoke_api_operation', $instructions);
        $this->assertStringNotContainsString('appear automatically', $instructions);
    }

    public function testDiscoverDescribesInvokeApiOperation(): void
    {
        $instructions = $this->statelessRpc(1, 'server/discover')['result']['instructions'] ?? '';

        $this->assertStringContainsString('invoke_api_operation', $instructions);
        $this->assertStringNotContainsString('appear automatically', $instructions);
    }

    private function initializeInstructions(): string
    {
        $this->browser->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => new \stdClass(),
                'clientInfo' => ['name' => 'phpunit-test', 'version' => '1.0'],
            ],
        ]));

        return json_decode($this->browser->getResponse()->getContent(), true)['result']['instructions'] ?? '';
    }

    // =========================================================================
    // Test: T4.1 — Collection discovery via entrypoint
    // =========================================================================

    public function testT4_1_CollectionDiscovery(): void
    {
        $sid = $this->initializeSession();

        // Read entrypoint
        $response = $this->jsonRpc($sid, 1, 'tools/call', [
            'name' => 'read_api_resource',
            'arguments' => ['uri' => '/'],
        ]);

        $text = $this->extractText($response);
        $this->assertStringContainsString('book', $text, 'Entrypoint mentions books collection');
        $this->assertStringContainsString('parchment', $text, 'Entrypoint mentions parchments collection');
        $this->assertStringContainsString('bookmark', $text, 'Entrypoint mentions bookmarks collection');
    }

    // =========================================================================
    // Test: T2.2 — Delete mutation
    // =========================================================================

    public function testT2_2_DeleteMutation(): void
    {
        $sid = $this->initializeSession();
        $this->discoverTools($sid, '/books');

        // Verify delete_book tool exists
        $tools = $this->getToolsByName($sid, 1);
        $this->assertArrayHasKey('delete_book', $tools, 'delete_book tool must exist');

        // Search for the target book
        $searchResult = $this->jsonRpc($sid, 2, 'tools/call', [
            'name' => 'search_books',
            'arguments' => ['title' => 'Dragons of Deltora'],
        ]);
        $searchText = $this->extractText($searchResult);
        $searchData = json_decode($searchText, true);

        $this->assertNotNull($searchData, 'Search result must be valid JSON');
        $this->assertGreaterThanOrEqual(1, $searchData['hydra:totalItems'] ?? 0, 'Book "Dragons of Deltora" must exist before delete');

        // Extract the book URI from the first member
        $bookUri = $searchData['hydra:member'][0]['@id'] ?? null;
        $this->assertNotNull($bookUri, 'Book URI must be present in search results');

        // Delete the book
        $deleteResult = $this->jsonRpc($sid, 3, 'tools/call', [
            'name' => 'delete_book',
            'arguments' => ['uri' => $bookUri],
        ]);
        // DELETE should not return an error
        $this->assertArrayNotHasKey('error', $deleteResult, 'delete_book must not return an error');

        // Verify deletion
        $verifyResult = $this->jsonRpc($sid, 4, 'tools/call', [
            'name' => 'search_books',
            'arguments' => ['title' => 'Dragons of Deltora'],
        ]);
        $verifyText = $this->extractText($verifyResult);
        $verifyData = json_decode($verifyText, true);
        $this->assertSame(0, $verifyData['hydra:totalItems'] ?? -1, 'Book must be deleted (totalItems = 0)');

        // Teardown: re-create the book to keep fixtures clean
        $this->jsonRpc($sid, 5, 'tools/call', [
            'name' => 'post_book',
            'arguments' => [
                'book' => '/books/OL25910297M',
                'condition' => 'https://schema.org/UsedCondition',
            ],
        ]);
    }

    // =========================================================================
    // Test: T2.3 — Update mutation
    // =========================================================================

    public function testT2_3_UpdateMutation(): void
    {
        $sid = $this->initializeSession();
        $this->discoverTools($sid, '/books');

        // Verify patch_book tool exists
        $tools = $this->getToolsByName($sid, 1);
        $this->assertArrayHasKey('patch_book', $tools, 'patch_book tool must exist');

        // Search for Hyperion
        $searchResult = $this->jsonRpc($sid, 2, 'tools/call', [
            'name' => 'search_books',
            'arguments' => ['title' => 'Hyperion'],
        ]);
        $searchText = $this->extractText($searchResult);
        $searchData = json_decode($searchText, true);

        $this->assertNotNull($searchData, 'Search result must be valid JSON');
        $this->assertGreaterThanOrEqual(1, $searchData['hydra:totalItems'] ?? 0, 'Book "Hyperion" must exist');

        $bookUri = $searchData['hydra:member'][0]['@id'] ?? null;
        $this->assertNotNull($bookUri, 'Book URI must be present');

        // Save original condition for teardown
        $originalCondition = $searchData['hydra:member'][0]['condition'] ?? null;

        // Update condition
        $patchResult = $this->jsonRpc($sid, 3, 'tools/call', [
            'name' => 'patch_book',
            'arguments' => [
                'uri' => $bookUri,
                'condition' => 'https://schema.org/NewCondition',
            ],
        ]);
        $this->assertArrayNotHasKey('error', $patchResult, 'patch_book must not return an error: '.json_encode($patchResult['error'] ?? null));

        // Verify update
        $verifyResult = $this->jsonRpc($sid, 4, 'tools/call', [
            'name' => 'search_books',
            'arguments' => ['title' => 'Hyperion'],
        ]);
        $verifyText = $this->extractText($verifyResult);
        $verifyData = json_decode($verifyText, true);

        $memberCondition = $verifyData['hydra:member'][0]['condition'] ?? '';
        $this->assertStringContainsString('NewCondition', $memberCondition, 'Condition must be updated to NewCondition');

        // Teardown: restore original condition
        if (null !== $originalCondition) {
            $this->jsonRpc($sid, 5, 'tools/call', [
                'name' => 'patch_book',
                'arguments' => [
                    'uri' => $bookUri,
                    'condition' => $originalCondition,
                ],
            ]);
        }
    }
}
