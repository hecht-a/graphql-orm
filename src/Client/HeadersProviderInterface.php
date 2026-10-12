<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

/**
 * Provides HTTP headers computed at request time, e.g. an OAuth access token that expires.
 */
interface HeadersProviderInterface
{
    /**
     * Called before every request; the returned headers override the static `headers` option.
     *
     * @return array<string, string|string[]>
     */
    public function getHeaders(): array;
}
