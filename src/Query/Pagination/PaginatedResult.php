<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Pagination;

/**
 * @template T of object
 */
final readonly class PaginatedResult
{
    /**
     * @param T[]      $items
     * @param string[] $cursorStack
     */
    public function __construct(
        public array $items,
        public bool $hasNextPage,
        public bool $hasPreviousPage,
        public ?string $endCursor,
        private array $cursorStack,
        private \Closure $fetchPage,
    ) {
    }

    /**
     * @return self<T>|null
     */
    public function next(): ?self
    {
        if (!$this->hasNextPage) {
            return null;
        }

        $newStack = [
            ...$this->cursorStack,
            $this->endCursor,
        ];

        return ($this->fetchPage)($this->endCursor, $newStack);
    }

    /**
     * @return self<T>|null
     */
    public function previous(): ?self
    {
        if (!$this->hasPreviousPage) {
            return null;
        }

        $newStack = $this->cursorStack;
        array_pop($newStack);
        $previousCursor = empty($newStack) ? null : end($newStack);

        return ($this->fetchPage)($previousCursor, $newStack);
    }
}
