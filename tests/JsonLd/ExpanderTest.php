<?php

declare(strict_types=1);

namespace App\Tests\JsonLd;

use App\JsonLd\Expander;
use App\JsonLd\HttpClientDocumentLoader;
use App\JsonLd\JsonLdDocument;
use App\JsonLd\JsonLdNode;
use App\JsonLd\Vocab;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

class ExpanderTest extends TestCase
{
    private Expander $expander;

    protected function setUp(): void
    {
        $mockClient = new MockHttpClient();
        $loader = new HttpClientDocumentLoader($mockClient);
        $this->expander = new Expander($loader, 'https://localhost/');
    }

    public function testExpandDocsJsonLd(): void
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
            'title' => 'Test API',
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
                                        'title' => 'Creates a Book resource.',
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
                            'required' => false,
                        ],
                    ],
                ],
            ],
        ]));

        $doc = $this->expander->expand($docsJsonLd);

        $this->assertInstanceOf(JsonLdDocument::class, $doc);
        $this->assertCount(1, $doc->all());

        $node = $doc->first();
        $this->assertInstanceOf(JsonLdNode::class, $node);

        // @type is expanded to full IRI
        $this->assertContains(Vocab::API_DOCUMENTATION, $node->types());

        // supportedClass is accessible via full IRI
        $classes = $node->all(Vocab::SUPPORTED_CLASS);
        $this->assertCount(2, $classes);

        // Entrypoint class
        $entrypoint = $classes[0];
        $this->assertSame('Entrypoint', $entrypoint->value(Vocab::TITLE));

        // Book class
        $book = $classes[1];
        $this->assertSame('Book', $book->value(Vocab::TITLE));

        // Book has writeable title property
        $bookProps = $book->all(Vocab::SUPPORTED_PROPERTY_PROP);
        $this->assertCount(2, $bookProps);
        $this->assertSame('title', $bookProps[0]->value(Vocab::TITLE));
        $this->assertTrue($bookProps[0]->value(Vocab::WRITEABLE));
        $this->assertTrue($bookProps[0]->value(Vocab::REQUIRED));
    }

    public function testJsonLdNodeHelpers(): void
    {
        $node = new JsonLdNode((object) [
            '@id' => 'https://localhost/#Book',
            '@type' => [Vocab::CLASS_],
            Vocab::TITLE => [(object) ['@value' => 'Book']],
            Vocab::EXPECTS => [(object) ['@id' => 'https://localhost/#Author']],
        ]);

        $this->assertSame('Book', $node->value(Vocab::TITLE));
        $this->assertSame('https://localhost/#Author', $node->id(Vocab::EXPECTS));
        $this->assertSame('https://localhost/#Book', $node->nodeId());
        $this->assertSame([Vocab::CLASS_], $node->types());
        $this->assertNull($node->value(Vocab::DESCRIPTION));
    }

    public function testExpandCollectionWithSearch(): void
    {
        $collection = json_decode(json_encode([
            '@context' => [
                '@vocab' => 'http://www.w3.org/ns/hydra/core#',
            ],
            '@id' => '/books',
            '@type' => 'Collection',
            'search' => [
                '@type' => 'IriTemplate',
                'template' => '/books{?title}',
                'mapping' => [
                    [
                        '@type' => 'IriTemplateMapping',
                        'variable' => 'title',
                        'property' => 'title',
                    ],
                ],
            ],
        ]));

        $doc = $this->expander->expand($collection);
        $node = $doc->first();

        // hydra:search is accessible
        $search = $node->first(Vocab::SEARCH);
        $this->assertNotNull($search);

        // Template value
        $this->assertSame('/books{?title}', $search->value(Vocab::TEMPLATE));

        // Mapping
        $mappings = $search->all(Vocab::MAPPING);
        $this->assertCount(1, $mappings);
        $this->assertSame('title', $mappings[0]->value(Vocab::VARIABLE));
    }
}
