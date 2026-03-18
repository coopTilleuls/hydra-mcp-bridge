<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\JsonLd\Expander;
use App\JsonLd\HttpClientDocumentLoader;
use App\Mcp\HydraResponseParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class HydraResponseParserTest extends TestCase
{
    private Expander $expander;
    private HydraResponseParser $parser;

    protected function setUp(): void
    {
        $mockClient = new MockHttpClient();
        $loader = new HttpClientDocumentLoader($mockClient);
        $this->expander = new Expander($loader, 'https://localhost/');
        $this->parser = new HydraResponseParser();
    }

    public function testExtractClassOperations(): void
    {
        $docsJsonLd = json_decode(json_encode([
            '@context' => [
                '@vocab' => 'http://www.w3.org/ns/hydra/core#',
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'owl' => 'http://www.w3.org/2002/07/owl#',
                'label' => 'rdfs:label',
                'domain' => ['@id' => 'rdfs:domain', '@type' => '@id'],
                'range' => ['@id' => 'rdfs:range', '@type' => '@id'],
                'expects' => ['@id' => 'expects', '@type' => '@id'],
            ],
            '@id' => '/docs.jsonld',
            '@type' => 'ApiDocumentation',
            'supportedClass' => [
                [
                    '@id' => '#Entrypoint',
                    '@type' => 'Class',
                    'title' => 'Entrypoint',
                    'supportedProperty' => [
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Entrypoint/book',
                                '@type' => 'Link',
                                'label' => 'book',
                                'range' => [
                                    ['@id' => 'Collection'],
                                    ['owl:equivalentClass' => ['owl:allValuesFrom' => ['@id' => '#Book']]],
                                ],
                                'supportedOperation' => [
                                    [
                                        '@type' => ['Operation'],
                                        'method' => 'POST',
                                        'title' => 'postBook',
                                        'description' => 'Creates a Book resource.',
                                        'expects' => '#Book',
                                    ],
                                ],
                            ],
                            'title' => 'book',
                            'readable' => true,
                            'writeable' => false,
                        ],
                    ],
                ],
                [
                    '@id' => '#Book',
                    '@type' => 'Class',
                    'title' => 'Book',
                    'supportedProperty' => [
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Book/title',
                                'range' => 'http://www.w3.org/2001/XMLSchema#string',
                                'label' => 'title',
                            ],
                            'title' => 'title',
                            'readable' => true,
                            'writeable' => true,
                            'required' => true,
                        ],
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Book/author',
                                'range' => 'http://www.w3.org/2001/XMLSchema#string',
                                'label' => 'author',
                            ],
                            'title' => 'author',
                            'readable' => true,
                            'writeable' => true,
                        ],
                    ],
                ],
            ],
        ]));

        $expandedDocs = $this->expander->expand($docsJsonLd);

        // Extract operations for the Book class from expanded docs
        $managedClassId = 'https://localhost/#Book';
        $collectionUri = '/admin/books';
        $affordances = $this->parser->extractClassOperations($expandedDocs, $managedClassId, $collectionUri);

        // Should find the POST operation from Entrypoint's supportedProperty
        // Tool name comes from hydra:title ("postBook" → "post_book"), not from HTTP method
        $postAffordances = array_filter($affordances, fn ($a) => 'post_book' === $a->name);
        $this->assertNotEmpty($postAffordances);

        $postBook = reset($postAffordances);
        $this->assertSame('POST', $postBook->method);
        $this->assertSame('/admin/books', $postBook->uri);
        $this->assertSame('Creates a Book resource.', $postBook->description);

        // Input schema should have typed properties
        $schema = $postBook->inputSchema;
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('author', $schema['properties']);
        $this->assertSame('string', $schema['properties']['title']['type']);
        $this->assertContains('title', $schema['required']);
    }

    public function testExtractSearchAffordance(): void
    {
        $compactJsonLd = json_decode(json_encode([
            '@context' => ['@vocab' => 'http://www.w3.org/ns/hydra/core#'],
            '@id' => '/books',
            '@type' => 'Collection',
            'search' => [
                '@type' => 'IriTemplate',
                'template' => '/books{?title,author}',
                'mapping' => [
                    ['@type' => 'IriTemplateMapping', 'variable' => 'title', 'property' => 'title'],
                    ['@type' => 'IriTemplateMapping', 'variable' => 'author', 'property' => 'author'],
                ],
            ],
        ]));

        $expanded = $this->expander->expand($compactJsonLd);
        $affordances = $this->parser->extractAffordances($expanded, $compactJsonLd);

        $this->assertCount(1, $affordances);

        $search = $affordances[0];
        $this->assertSame('search_books', $search->name);
        $this->assertSame('search', $search->type);
        $this->assertSame('/books', $search->uri);
        $this->assertSame(['title', 'author'], $search->paramNames);

        $schema = $search->inputSchema;
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('author', $schema['properties']);
    }

    public function testEmptyDocsReturnsNoAffordances(): void
    {
        $docs = json_decode(json_encode([
            '@context' => ['@vocab' => 'http://www.w3.org/ns/hydra/core#'],
            '@id' => '/docs.jsonld',
            '@type' => 'ApiDocumentation',
        ]));

        $expanded = $this->expander->expand($docs);
        $affordances = $this->parser->extractClassOperations($expanded, 'https://localhost/#Book', '/books');

        $this->assertSame([], $affordances);
    }

    public function testGetOperationsRegistered(): void
    {
        $docsJsonLd = json_decode(json_encode([
            '@context' => [
                '@vocab' => 'http://www.w3.org/ns/hydra/core#',
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'owl' => 'http://www.w3.org/2002/07/owl#',
                'label' => 'rdfs:label',
                'range' => ['@id' => 'rdfs:range', '@type' => '@id'],
                'expects' => ['@id' => 'expects', '@type' => '@id'],
            ],
            '@id' => '/docs.jsonld',
            '@type' => 'ApiDocumentation',
            'supportedClass' => [
                [
                    '@id' => '#Entrypoint',
                    '@type' => 'Class',
                    'title' => 'Entrypoint',
                    'supportedProperty' => [
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Entrypoint/book',
                                'label' => 'book',
                                'range' => [['@id' => 'Collection']],
                                'supportedOperation' => [
                                    ['@type' => 'Operation', 'method' => 'GET', 'title' => 'Retrieves books'],
                                ],
                            ],
                            'title' => 'book',
                        ],
                    ],
                ],
            ],
        ]));

        $expanded = $this->expander->expand($docsJsonLd);
        // GET operations should now be registered as read_ tools
        $affordances = $this->parser->extractClassOperations($expanded, 'https://localhost/#Book', '/books');

        // The Entrypoint property targets Collection (no owl:allValuesFrom → #Book match),
        // so no affordances are extracted for #Book from this minimal docs
        $this->assertSame([], $affordances);
    }

    /**
     * Full CRUD discovery: validates that all operation schemas are correct.
     *
     * - POST: writable properties as schema fields, required fields enforced
     * - GET (item): only uri, no body properties
     * - PATCH: writable properties but none required (partial update)
     * - DELETE: only uri, no body properties
     * - GET (collection): no properties at all
     */
    public function testFullCrudOperationSchemas(): void
    {
        $docsJsonLd = json_decode(json_encode([
            '@context' => [
                '@vocab' => 'http://www.w3.org/ns/hydra/core#',
                'rdfs' => 'http://www.w3.org/2000/01/rdf-schema#',
                'owl' => 'http://www.w3.org/2002/07/owl#',
                'label' => 'rdfs:label',
                'domain' => ['@id' => 'rdfs:domain', '@type' => '@id'],
                'range' => ['@id' => 'rdfs:range', '@type' => '@id'],
                'expects' => ['@id' => 'expects', '@type' => '@id'],
            ],
            '@id' => '/docs.jsonld',
            '@type' => 'ApiDocumentation',
            'supportedClass' => [
                [
                    '@id' => '#Entrypoint',
                    '@type' => 'Class',
                    'title' => 'Entrypoint',
                    'supportedProperty' => [
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Entrypoint/book',
                                '@type' => 'Link',
                                'label' => 'book',
                                'range' => [
                                    ['@id' => 'Collection'],
                                    ['owl:equivalentClass' => ['owl:allValuesFrom' => ['@id' => '#Book']]],
                                ],
                                'supportedOperation' => [
                                    [
                                        '@type' => ['Operation'],
                                        'method' => 'POST',
                                        'title' => 'postBook',
                                        'description' => 'Creates a Book resource.',
                                        'expects' => '#Book',
                                    ],
                                    [
                                        '@type' => ['Operation'],
                                        'method' => 'GET',
                                        'title' => 'getBookCollection',
                                        'description' => 'Retrieves the collection of Book resources.',
                                    ],
                                ],
                            ],
                            'title' => 'book',
                            'readable' => true,
                            'writeable' => false,
                        ],
                    ],
                ],
                [
                    '@id' => '#Book',
                    '@type' => 'Class',
                    'title' => 'Book',
                    'supportedOperation' => [
                        [
                            '@type' => ['Operation'],
                            'method' => 'GET',
                            'title' => 'getBook',
                            'description' => 'Retrieves a Book resource.',
                        ],
                        [
                            '@type' => ['Operation'],
                            'method' => 'PATCH',
                            'title' => 'patchBook',
                            'description' => 'Updates the Book resource.',
                            'expects' => '#Book',
                        ],
                        [
                            '@type' => ['Operation'],
                            'method' => 'DELETE',
                            'title' => 'deleteBook',
                            'description' => 'Deletes the Book resource.',
                        ],
                    ],
                    'supportedProperty' => [
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Book/book',
                                'range' => 'http://www.w3.org/2001/XMLSchema#string',
                                'label' => 'book',
                            ],
                            'title' => 'book',
                            'readable' => true,
                            'writeable' => true,
                            'required' => true,
                        ],
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Book/condition',
                                'range' => 'http://www.w3.org/2001/XMLSchema#string',
                                'label' => 'condition',
                            ],
                            'title' => 'condition',
                            'readable' => true,
                            'writeable' => true,
                        ],
                        [
                            '@type' => 'SupportedProperty',
                            'property' => [
                                '@id' => '#Book/author',
                                'range' => 'http://www.w3.org/2001/XMLSchema#string',
                                'label' => 'author',
                            ],
                            'title' => 'author',
                            'readable' => true,
                            'writeable' => false, // read-only
                        ],
                    ],
                ],
            ],
        ]));

        $expandedDocs = $this->expander->expand($docsJsonLd);
        $affordances = $this->parser->extractClassOperations($expandedDocs, 'https://localhost/#Book', '/books');

        $byName = [];
        foreach ($affordances as $a) {
            $byName[$a->name] = $a;
        }

        // --- POST: writable properties, required fields enforced ---
        $this->assertArrayHasKey('post_book', $byName);
        $post = $byName['post_book'];
        $this->assertSame('POST', $post->method);
        $this->assertSame('/books', $post->uri);
        $this->assertArrayHasKey('book', $post->inputSchema['properties']);
        $this->assertArrayHasKey('condition', $post->inputSchema['properties']);
        $this->assertArrayNotHasKey('author', $post->inputSchema['properties'], 'Read-only property excluded');
        $this->assertArrayNotHasKey('uri', $post->inputSchema['properties'], 'POST does not need uri');
        $this->assertContains('book', $post->inputSchema['required']);
        $this->assertNotContains('condition', $post->inputSchema['required'], 'Optional property not required');

        // --- GET item: only uri, no body properties ---
        $this->assertArrayHasKey('get_book', $byName);
        $get = $byName['get_book'];
        $this->assertSame('GET', $get->method);
        $this->assertSame('/books', $get->uri);
        $this->assertArrayHasKey('uri', $get->inputSchema['properties']);
        $this->assertArrayNotHasKey('book', $get->inputSchema['properties'], 'GET must not include body properties');
        $this->assertArrayNotHasKey('condition', $get->inputSchema['properties'], 'GET must not include body properties');
        $this->assertContains('uri', $get->inputSchema['required']);
        $this->assertCount(1, $get->inputSchema['required'], 'GET only requires uri');

        // --- PATCH: writable properties present but none required (partial update) ---
        $this->assertArrayHasKey('patch_book', $byName);
        $patch = $byName['patch_book'];
        $this->assertSame('PATCH', $patch->method);
        $this->assertSame('/books', $patch->uri);
        $this->assertArrayHasKey('uri', $patch->inputSchema['properties']);
        $this->assertArrayHasKey('book', $patch->inputSchema['properties']);
        $this->assertArrayHasKey('condition', $patch->inputSchema['properties']);
        $this->assertArrayNotHasKey('author', $patch->inputSchema['properties'], 'Read-only property excluded');
        // Only uri is required for PATCH — body properties are optional (partial update)
        $this->assertContains('uri', $patch->inputSchema['required']);
        $this->assertNotContains('book', $patch->inputSchema['required'], 'PATCH properties must not be required');
        $this->assertNotContains('condition', $patch->inputSchema['required'], 'PATCH properties must not be required');

        // --- DELETE: only uri, no body properties ---
        $this->assertArrayHasKey('delete_book', $byName);
        $delete = $byName['delete_book'];
        $this->assertSame('DELETE', $delete->method);
        $this->assertSame('/books', $delete->uri);
        $this->assertArrayHasKey('uri', $delete->inputSchema['properties']);
        $this->assertArrayNotHasKey('book', $delete->inputSchema['properties'], 'DELETE must not include body properties');
        $this->assertArrayNotHasKey('condition', $delete->inputSchema['properties'], 'DELETE must not include body properties');
        $this->assertContains('uri', $delete->inputSchema['required']);
        $this->assertCount(1, $delete->inputSchema['required'], 'DELETE only requires uri');

        // --- GET collection: no properties at all ---
        $this->assertArrayHasKey('get_book_collection', $byName);
        $getColl = $byName['get_book_collection'];
        $this->assertSame('GET', $getColl->method);
        $this->assertSame('/books', $getColl->uri);
        $props = (array) $getColl->inputSchema['properties'];
        $this->assertEmpty($props, 'GET collection has no properties');
        $this->assertArrayNotHasKey('required', $getColl->inputSchema, 'GET collection has no required fields');
    }
}
