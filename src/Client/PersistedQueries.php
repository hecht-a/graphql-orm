<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

/**
 * Automatic persisted queries, as Apollo defines them: a query is sent as the SHA-256 hash of its text, the server
 * asking for the text once (PersistedQueryNotFound) and keeping it. A server refusing the hash otherwise, telling it
 * does not support them (PersistedQueryNotSupported) or not knowing them (Hot Chocolate, Data API Builder: a request
 * without query), is sent the queries in full from then on.
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
     * Whether the answer to a hash asks for the text of the query (true), or refuses the hash otherwise (false): errors
     * without data. Null for an answer, partial or not, and for a failure telling nothing, such as an HTML error page.
     *
     * @param array<mixed>|null $body the decoded body of the response
     */
    public static function failureOf(?array $body): ?bool
    {
        $errors = $body['errors'] ?? null;

        if (!\is_array($errors) || $errors === [] || ($body['data'] ?? null) !== null) {
            return null;
        }

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

        return false;
    }
}
