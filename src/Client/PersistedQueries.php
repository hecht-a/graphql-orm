<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

/**
 * Automatic persisted queries, as Apollo defines them: a query is sent as the SHA-256 hash of its text, the server
 * asking for the text once (PersistedQueryNotFound) and keeping it. A server telling it does not support them
 * (PersistedQueryNotSupported) is sent the queries in full from then on.
 *
 * @internal shared by the requests of a client
 */
final class PersistedQueries
{
    private const VERSION = 1;

    private const NOT_FOUND = ['PersistedQueryNotFound', 'PERSISTED_QUERY_NOT_FOUND'];

    private const NOT_SUPPORTED = ['PersistedQueryNotSupported', 'PERSISTED_QUERY_NOT_SUPPORTED'];

    private bool $supported = true;

    public function isSupported(): bool
    {
        return $this->supported;
    }

    public function unsupported(): void
    {
        $this->supported = false;
    }

    /**
     * @return array{persistedQuery: array{version: int, sha256Hash: string}} the extensions of a request
     */
    public function extensions(string $query): array
    {
        return ['persistedQuery' => ['version' => self::VERSION, 'sha256Hash' => hash('sha256', $query)]];
    }

    /**
     * Whether errors ask for the text of the query (true), or tell the server does not support the persisted queries
     * (false); null for other errors.
     *
     * @param list<mixed> $errors
     */
    public static function failureOf(array $errors): ?bool
    {
        foreach ($errors as $error) {
            $code = \is_array($error) && \is_array($error['extensions'] ?? null) ? $error['extensions']['code'] ?? null : null;
            $message = \is_array($error) ? $error['message'] ?? null : null;

            foreach ([$message, $code] as $name) {
                if (\in_array($name, self::NOT_FOUND, true)) {
                    return true;
                }

                if (\in_array($name, self::NOT_SUPPORTED, true)) {
                    return false;
                }
            }
        }

        return null;
    }
}
