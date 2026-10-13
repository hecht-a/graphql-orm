<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class GraphqlRequestException extends \RuntimeException implements GraphqlOrmException
{
    public static function transportFailed(string $endpoint, \Throwable $previous): self
    {
        return new self(\sprintf('GraphQL request to "%s" failed: %s', $endpoint, $previous->getMessage()), 0, $previous);
    }

    /**
     * @param array<string|int, mixed> $errors the GraphQL errors of the response body, if any
     */
    public static function httpError(string $endpoint, int $statusCode, array $errors = []): self
    {
        $message = \sprintf('GraphQL endpoint "%s" answered with HTTP status %d', $endpoint, $statusCode);

        return new self($errors === [] ? $message . '.' : $message . ': ' . implode(' | ', self::messages($errors)));
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
        return new self('GraphQL query returned errors without data: ' . implode(' | ', self::messages($errors)));
    }

    /**
     * The same failure, with hints on its likely cause appended to the message, one per line.
     *
     * @param list<string> $hints
     */
    public function withHints(array $hints): self
    {
        return $hints === [] ? $this : new self($this->getMessage() . "\n" . implode("\n", $hints), $this->getCode(), $this);
    }

    /**
     * @param array<string|int, mixed> $errors
     *
     * @return list<string>
     */
    private static function messages(array $errors): array
    {
        $messages = [];

        foreach ($errors as $error) {
            $message = \is_array($error) ? $error['message'] ?? null : null;
            $messages[] = \is_string($message) ? $message : 'Unknown error';
        }

        return $messages;
    }
}
