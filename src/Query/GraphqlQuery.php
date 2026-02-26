<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Pagination\PaginatedResult;

/**
 * @template T of object
 */
final readonly class GraphqlQuery
{
    /**
     * @param class-string<T>   $entityClass
     * @param GraphqlManager<T> $manager
     */
    public function __construct(
        private QueryNode|string $graphql,
        private string $entityClass,
        private GraphqlManager $manager,
        private QueryOptions $options,
    ) {
    }

    public function getGraphQL(): string
    {
        if ($this->graphql instanceof QueryNode) {
            return $this->manager->getQueryCompiler()->compile($this->graphql, $this->options);
        }

        return $this->graphql;
    }

    /**
     * @return T[]|PaginatedResult<T>
     */
    public function getResult(): array|PaginatedResult
    {
        $metadata = $this->manager
            ->metadataFactory
            ->getMetadata($this->entityClass);

        return $this->manager->execute(
            $this->graphql,
            function ($result, $context) use ($metadata) {
                $dialect = $this->manager->getDialect();
                $data = $result['data'] ?? [];

                if (!\array_key_exists($metadata->name, $data)) {
                    return $this->options->paginate
                        ? new PaginatedResult([], false, false, null, [], fn () => null)
                        : [];
                }

                $root = $data[$metadata->name];

                if ($root === null) {
                    return [];
                }

                if (!\is_array($root)) {
                    throw InvalidGraphqlResponseException::expectedArray($root);
                }

                $collection = $dialect->extractCollection($root);

                if (array_is_list($collection)) {
                    $rows = $collection;
                } else {
                    $rows = [$collection];
                }

                if (array_is_list($rows) === false) {
                    $rows = [$rows];
                }

                $items = array_map(
                    fn ($row) => $this->manager
                        ->hydrator
                        ->hydrate($metadata, $row, $context),
                    $rows
                );

                if (!$this->options->paginate) {
                    return $items;
                }

                $fetchPage = function (?string $cursor, array $newStack) {
                    $qb = clone $this;
                    $qb->options->cursor = $cursor;
                    $qb->options->cursorStack = $newStack;

                    return $qb->getResult();
                };

                return new PaginatedResult(
                    $items,
                    $root['hasNextPage'] ?? false,
                    \count($this->options->cursorStack) > 0,
                    $root['endCursor'] ?? null,
                    $this->options->cursorStack,
                    $fetchPage
                );
            },
            $this->options
        );
    }

    /**
     * @return T|null
     */
    public function getOneOrNullResult(): ?object
    {
        $result = $this->getResult();

        if ($result instanceof PaginatedResult) {
            return $result->items[0] ?? null;
        }

        return $result[0] ?? null;
    }
}
