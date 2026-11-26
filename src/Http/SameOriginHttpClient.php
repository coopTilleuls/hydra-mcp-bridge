<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class SameOriginHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    private const array DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /** @var array{scheme: string, host: string, port: int} */
    private readonly array $origin;

    public function __construct(HttpClientInterface $client, string $baseUri)
    {
        $this->client = $client;
        $origin = self::originOf($baseUri);
        if (null === $origin) {
            throw new InvalidArgumentException(\sprintf('The base URI "%s" is not an absolute http(s) URL.', $baseUri));
        }
        $this->origin = $origin;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (!$this->isAllowed($url)) {
            throw new TransportException(\sprintf('Refusing to request "%s": it is not on the upstream origin "%s://%s:%d".', $url, $this->origin['scheme'], $this->origin['host'], $this->origin['port']));
        }

        return $this->client->request($method, $url, $options);
    }

    private function isAllowed(string $url): bool
    {
        if (1 === preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return false;
        }

        if (1 !== preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url)) {
            return true;
        }

        return $this->origin === self::originOf($url);
    }

    /**
     * @return array{scheme: string, host: string, port: int}|null
     */
    private static function originOf(string $url): ?array
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }

        return ['scheme' => $scheme, 'host' => strtolower($parts['host']), 'port' => $parts['port'] ?? self::DEFAULT_PORTS[$scheme]];
    }
}
