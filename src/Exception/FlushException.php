<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

/**
 * Mutations of a flush the API did not apply. Without transaction, those before were applied: they are not sent again,
 * and flush() sends again the ones that failed.
 */
final class FlushException extends \RuntimeException implements GraphqlOrmException
{
    /**
     * @param list<string> $failures the mutations that failed, with their errors
     */
    public static function failed(int $applied, int $total, array $failures, ?\Throwable $previous = null): self
    {
        return new self(\sprintf("%d of the %d mutations were applied. Failed:\n- %s", $applied, $total, implode("\n- ", $failures)), 0, $previous);
    }
}
