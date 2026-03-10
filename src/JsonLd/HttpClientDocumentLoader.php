<?php

declare(strict_types=1);

namespace App\JsonLd;

use ML\JsonLD\DocumentLoaderInterface;
use ML\JsonLD\RemoteDocument;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Loads remote JSON-LD documents using Symfony HttpClient.
 *
 * Replaces sweetrdf/json-ld's built-in FileGetContentsLoader which uses
 * file_get_contents() and triggers PHP 8.4 $http_response_header deprecations.
 */
final class HttpClientDocumentLoader implements DocumentLoaderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function loadDocument($url): RemoteDocument
    {
        $response = $this->httpClient->request('GET', $url, [
            'headers' => [
                'Accept' => 'application/ld+json, application/json;q=0.9',
            ],
        ]);

        $content = $response->getContent();
        $document = json_decode($content);

        $contextUrl = null;
        $linkHeaders = $response->getHeaders(false)['link'] ?? [];
        foreach ($linkHeaders as $linkHeader) {
            if (str_contains($linkHeader, 'http://www.w3.org/ns/json-ld#context')) {
                if (preg_match('/<([^>]+)>/', $linkHeader, $matches)) {
                    $contextUrl = $matches[1];
                }
            }
        }

        return new RemoteDocument($url, $document, 'application/ld+json', $contextUrl);
    }
}
