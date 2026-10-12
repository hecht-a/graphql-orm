<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class GraphqlRequestException extends \RuntimeException implements GraphqlOrmException
{
    public static function transportFailed(string $endpoint, \Throwable $previous): self
    {
        return new self(\sprintf('GraphQL request to "%s" failed: %s', $endpoint, $previous->getMessage()), 0, $previous);
    }

    public static function httpError(string $endpoint, int $statusCode): self
    {
        return new self(\sprintf('GraphQL endpoint "%s" answered with HTTP status %d.', $endpoint, $statusCode));
    }

    public static function invalidJson(string $endpoint, \JsonException $previous): self
    {
        return new self(\sprintf('GraphQL endpoint "%s" returned a body that is not valid JSON.', $endpoint), 0, $previous);
    }

    /**
     * @param array<string|int, mixed> $errors
     */
    public static function graphqlErrors(array $errors): self
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = \is_array($error) ? $error['message'] ?? null : null;
            $messages[] = \is_string($message) ? $message : 'Unknown error';
        }

        return new self('GraphQL query returned errors without data: ' . implode(' | ', $messages));
    }
}
