<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Http\SameOriginHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class SameOriginHttpClientTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function allowedUrls(): iterable
    {
        yield 'relative path' => ['/books/1', 'https://localhost/books/1'];
        yield 'relative without slash' => ['books/1', 'https://localhost/books/1'];
        yield 'absolute same origin' => ['https://localhost/books/1', 'https://localhost/books/1'];
        yield 'absolute same origin explicit default port' => ['https://localhost:443/books/1', 'https://localhost/books/1'];
        yield 'host case insensitive' => ['https://LOCALHOST/books/1', 'https://localhost/books/1'];
    }

    #[DataProvider('allowedUrls')]
    public function testAllowsSameOrigin(string $url, string $expected): void
    {
        $requested = [];
        $client = $this->client($requested);

        $client->request('GET', $url);

        $this->assertSame([$expected], $requested);
    }

    public function testAllowsSameOriginOnCustomPort(): void
    {
        $requested = [];
        $client = $this->client($requested, 'http://localhost:8080');

        $client->request('GET', 'http://localhost:8080/books');
        $client->request('GET', '/books');

        $this->assertSame(['http://localhost:8080/books', 'http://localhost:8080/books'], $requested);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedUrls(): iterable
    {
        yield 'another host' => ['https://evil.example/x'];
        yield 'another port' => ['https://localhost:8443/x'];
        yield 'another scheme' => ['http://localhost/x'];
        yield 'protocol relative' => ['//evil.example/x'];
        yield 'userinfo trick' => ['https://localhost@evil.example/'];
        yield 'metadata endpoint' => ['http://169.254.169.254/latest/meta-data'];
        yield 'backslash trick' => ['https://evil.example\\@localhost/'];
        yield 'file scheme' => ['file:///etc/passwd'];
    }

    #[DataProvider('rejectedUrls')]
    public function testRejectsAnotherOrigin(string $url): void
    {
        $requested = [];
        $client = $this->client($requested);

        try {
            $client->request('GET', $url);
            $this->fail('The request must be rejected.');
        } catch (TransportExceptionInterface $e) {
            $this->assertStringContainsString($url, $e->getMessage());
        }

        $this->assertSame([], $requested, 'No request may reach the inner client.');
    }

    public function testRejectsAnotherOriginWhenBaseUsesDefaultPortImplicitly(): void
    {
        $requested = [];
        $client = $this->client($requested, 'https://api.example');

        $this->expectException(TransportExceptionInterface::class);

        try {
            $client->request('GET', 'https://api.example:444/x');
        } finally {
            $this->assertSame([], $requested);
        }
    }

    /**
     * @param list<string> $requested
     */
    private function client(array &$requested, string $base = 'https://localhost'): SameOriginHttpClient
    {
        return new SameOriginHttpClient(
            new MockHttpClient(static function (string $method, string $url) use (&$requested): MockResponse {
                $requested[] = $url;

                return new MockResponse('{}');
            }, $base),
            $base,
        );
    }
}
