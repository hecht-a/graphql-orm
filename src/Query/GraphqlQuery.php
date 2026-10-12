<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Ast\FieldNode;
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

                $root = $data[$metadata->name] ?? null;

                if ($root === null) {
                    return $this->options->paginate
                        ? new PaginatedResult([], false, false, null, [], fn () => null)
                        : [];
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
                    // Each page works on its own options so that navigating never alters this query.
                    $options = clone $this->options;
                    $options->cursor = $cursor;
                    $options->cursorStack = $newStack;

                    return (new self($this->graphql, $this->entityClass, $this->manager, $options))->getResult();
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
        $result = $this->limitedToOne()->getResult();

        if ($result instanceof PaginatedResult) {
            return $result->items[0] ?? null;
        }

        return $result[0] ?? null;
    }

    /**
     * @return GraphqlQuery<T>
     */
    private function limitedToOne(): self
    {
        if ($this->options->limit !== null || !$this->graphql instanceof QueryNode) {
            return $this;
        }

        $options = clone $this->options;
        $options->limit = 1;

        // Root arguments are computed when the query is built, so they have to be recomputed with the new limit.
        $query = new QueryNode();
        $query->operation = $this->graphql->operation;

        foreach ($this->graphql->fields as $field) {
            $query->fields[] = new FieldNode(
                $field->name,
                $this->manager->getDialect()->applyQueryOptions($field->arguments, $options),
                $field->selectionSet,
                $field->isCollection,
            );
        }

        return new self($query, $this->entityClass, $this->manager, $options);
    }
}
