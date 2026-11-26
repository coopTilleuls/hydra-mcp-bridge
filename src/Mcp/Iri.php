<?php

declare(strict_types=1);

namespace App\Mcp;

final class Iri
{
    /**
     * Compares IRIs of the upstream API whether the client gives them absolute or relative.
     */
    public static function path(string $iri): string
    {
        $path = parse_url($iri, \PHP_URL_PATH) ?: '/';
        $query = parse_url($iri, \PHP_URL_QUERY);

        return null === $query ? $path : $path.'?'.$query;
    }
}
