<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Dialect\CursorPaginationDialect;
use GraphqlOrm\Dialect\OffsetPaginationDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Exception\NonUniqueResultException;
use GraphqlOrm\Exception\NoResultException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Pagination\PaginatedResult;

/**
 * @template-covariant T of object
 */
final readonly class GraphqlQuery
{
    /**
     * @param class-string<T> $entityClass
     * @param list<string>    $unmappedFieldHints appended to the message of a failure
     */
    public function __construct(
        private QueryNode|string $graphql,
        private string $entityClass,
        private GraphqlManager $manager,
        private QueryOptions $options,
        private array $unmappedFieldHints = [],
    ) {
    }

    /**
     * @return class-string<T> the class of the entities this query returns
     */
    public function getEntityClass(): string
    {
        return $this->entityClass;
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
        return $this->pendingResult()();
    }

    /**
     * Runs the queries concurrently, each request being sent before any response is read, and returns their results
     * (as getResult()) in the same order. When some fail, the others still complete, then the first failure is thrown.
     *
     * @param GraphqlQuery<object> ...$queries
     *
     * @return list<array<object>|PaginatedResult<object>> the result of each query, as getResult() returns it
     *
     * @throws \Throwable the first failure, once every query completed
     */
    public static function concurrently(self ...$queries): array
    {
        $pending = [];
        $failure = null;

        foreach ($queries as $query) {
            try {
                $pending[] = $query->pendingResult();
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        $results = [];

        foreach ($pending as $complete) {
            try {
                $results[] = $complete();
            } catch (\Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $results;
    }

    /**
     * Sends the queries in one GraphQL document, one HTTP request, and returns their results (as getResult()) in the
     * same order. They must select different root fields, not be paginated, and share a manager; their values are sent
     * inline. A root field in error fails its query only, once the others are hydrated.
     *
     * @param GraphqlQuery<object> ...$queries
     *
     * @return list<array<object>|PaginatedResult<object>>
     *
     * @throws LogicException          when the queries cannot share a document
     * @throws GraphqlRequestException the first query failing
     */
    public static function batch(self ...$queries): array
    {
        $queries = array_values($queries);

        if ($queries === []) {
            return [];
        }

        $manager = $queries[0]->manager;
        $document = new QueryNode();
        $roots = [];

        foreach ($queries as $index => $query) {
            if (!$query->graphql instanceof QueryNode || $query->options->paginate || $query->manager !== $manager) {
                throw new LogicException('Only queries built with a query builder or the find methods, not paginated, of a same manager can be batched: run the others with GraphqlQuery::concurrently().');
            }

            foreach ($query->graphql->fields as $field) {
                if (\in_array($field->name, array_merge([], ...$roots), true)) {
                    throw new LogicException(\sprintf('Two batched queries select "%s": run them with GraphqlQuery::concurrently().', $field->name));
                }

                $roots[$index][] = $field->name;
                $document->fields[] = $field;
            }
        }

        $hydrated = false;

        try {
            return $manager->execute($document, function (array $result, GraphqlExecutionContext $context) use ($queries, $roots, &$hydrated): array {
                $hydrated = true;

                return self::hydrateBatch($queries, $roots, $result, $context);
            });
        } catch (GraphqlRequestException $exception) {
            // A failure of the whole document (no data at all) may come from any query.
            throw $hydrated ? $exception : $exception->withHints(array_values(array_unique(array_merge(...array_map(fn (self $query) => $query->unmappedFieldHints, $queries)))));
        }
    }

    /**
     * @param list<GraphqlQuery<object>> $queries
     * @param array<int, list<string>>   $roots   the root fields of each query
     * @param array<string, mixed>       $result
     *
     * @return list<array<object>|PaginatedResult<object>>
     *
     * @throws GraphqlRequestException the first query whose root fields are in error
     */
    private static function hydrateBatch(array $queries, array $roots, array $result, GraphqlExecutionContext $context): array
    {
        $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
        $errors = \is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $results = [];
        $failure = null;

        foreach ($queries as $index => $query) {
            $names = $roots[$index] ?? [];
            $queryErrors = array_values(array_filter(
                $errors,
                fn (mixed $error) => \is_array($error) && \is_array($error['path'] ?? null) && \in_array($error['path'][0] ?? null, $names, true),
            ));
            $queryData = array_intersect_key($data, array_flip($names));

            if ($queryErrors !== [] && array_filter($queryData, fn (mixed $value) => $value !== null) === []) {
                $failure ??= GraphqlRequestException::graphqlErrors($queryErrors)->withHints($query->unmappedFieldHints);

                continue;
            }

            $results[] = ($query->resultHydration())(['data' => $queryData], $context);
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $results;
    }

    /**
     * Sends the query and returns a function completing it.
     *
     * @return \Closure(): (T[]|PaginatedResult<T>)
     */
    private function pendingResult(): \Closure
    {
        return $this->pending($this->resultHydration());
    }

    /**
     * Turns the response into the result of getResult().
     *
     * @return \Closure(array<string, mixed>, GraphqlExecutionContext): (T[]|PaginatedResult<T>)
     */
    private function resultHydration(): \Closure
    {
        $metadata = $this->manager
            ->getMetadataFactory()
            ->getMetadata($this->entityClass);

        return function (array $result, GraphqlExecutionContext $context) use ($metadata) {
            $data = $result['data'] ?? null;
            $root = \is_array($data) ? ($data[$metadata->name] ?? null) : null;

            if ($root === null) {
                return $this->options->paginate
                    ? new PaginatedResult([], false, false, null, [], fn () => null)
                    : [];
            }

            if (!\is_array($root)) {
                throw InvalidGraphqlResponseException::expectedArray($root);
            }

            $rows = $this->rows($root);
            $hasNextPage = $this->trimExtraRow($rows);

            /** @var T[] $items the rows are hydrated as instances of the queried entity */
            $items = array_map(
                fn (array $row) => $this->manager
                    ->getHydrator()
                    ->hydrate($metadata, $row, $context),
                $rows
            );

            if ($this->options->indexBy !== null) {
                $items = self::indexed($items, $this->options->indexBy);
            }

            if (!$this->options->paginate) {
                return $items;
            }

            $dialect = $this->manager->getDialect();

            if ($dialect instanceof OffsetPaginationDialect) {
                $endCursor = $hasNextPage ? (string) ((int) $this->options->cursor + (int) $this->options->limit) : null;
            } elseif ($dialect instanceof CursorPaginationDialect) {
                $hasNextPage = $dialect->hasNextPage($root);
                $endCursor = $dialect->endCursor($root);
            } else {
                $hasNextPage = ($root['hasNextPage'] ?? false) === true;
                $endCursor = \is_string($root['endCursor'] ?? null) ? $root['endCursor'] : null;
            }

            return new PaginatedResult(
                $items,
                $hasNextPage,
                \count($this->options->cursorStack) > 0,
                $endCursor,
                $this->options->cursorStack,
                $this->fetchPage(...)
            );
        };
    }

    /**
     * Goes through every page of a paginated query, one query per page, the next page being fetched once the current
     * one is read: a large result set is processed with the memory of a single page, as long as the entities read are
     * not kept.
     *
     * @return \Generator<int, T>
     *
     * @throws LogicException when the query is not paginated
     */
    public function toIterable(): \Generator
    {
        if (!$this->options->paginate) {
            throw new LogicException('toIterable() goes through the pages of the query: call paginate(), and limit() with the page size.');
        }

        $options = clone $this->options;
        $options->detached = true;
        $page = (new self($this->graphql, $this->entityClass, $this->manager, $options, $this->unmappedFieldHints))->getResult();

        while ($page instanceof PaginatedResult) {
            // Not "yield from": each page would restart the keys at 0.
            foreach ($page->items as $item) {
                yield $item;
            }

            $page = $page->next();
        }
    }

    /**
     * Returns the rows as the API sent them, keyed by GraphQL field names and without hydration: lighter for an export
     * or a JSON response. The collection envelopes of the dialect (e.g. Data API Builder `items`) are removed, nested
     * collections included. A paginated query returns the rows of the requested page.
     *
     * @return list<array<mixed>>
     *
     * @throws InvalidGraphqlResponseException when the response does not hold rows
     */
    public function getArrayResult(): array
    {
        $metadata = $this->manager
            ->getMetadataFactory()
            ->getMetadata($this->entityClass);

        return $this->execute(
            function (array $result) use ($metadata): array {
                $data = $result['data'] ?? null;
                $root = \is_array($data) ? ($data[$metadata->name] ?? null) : null;

                if ($root === null) {
                    return [];
                }

                if (!\is_array($root)) {
                    throw InvalidGraphqlResponseException::expectedArray($root);
                }

                $rows = $this->rows($root);
                $this->trimExtraRow($rows);

                return array_map(
                    fn (array $row) => $this->withoutEnvelopes($metadata, $row),
                    $rows
                );
            },
        );
    }

    /**
     * @template R of array<mixed>|PaginatedResult<object>
     *
     * @param callable(array<string, mixed>, GraphqlExecutionContext): R $hydration
     *
     * @return R
     *
     * @throws GraphqlRequestException when the query fails, with the unmapped fields it names
     */
    private function execute(callable $hydration): array|PaginatedResult
    {
        return $this->pending($hydration)();
    }

    /**
     * @template R of array<mixed>|PaginatedResult<object>
     *
     * @param callable(array<string, mixed>, GraphqlExecutionContext): R $hydration
     *
     * @return \Closure(): R
     *
     * @throws GraphqlRequestException when the query cannot be sent, with the unmapped fields it names
     */
    private function pending(callable $hydration): \Closure
    {
        try {
            $complete = $this->manager->send($this->graphql, $hydration, $this->options);
        } catch (GraphqlRequestException $exception) {
            throw $exception->withHints($this->unmappedFieldHints);
        }

        return function () use ($complete): array|PaginatedResult {
            try {
                return $complete();
            } catch (GraphqlRequestException $exception) {
                throw $exception->withHints($this->unmappedFieldHints);
            }
        };
    }

    /**
     * With an offset pagination, one row more than the page size is requested: it tells a next page exists, and is
     * dropped.
     *
     * @param list<array<mixed>> $rows
     */
    private function trimExtraRow(array &$rows): bool
    {
        if (!$this->options->paginate || $this->options->limit === null || !$this->manager->getDialect() instanceof OffsetPaginationDialect) {
            return false;
        }

        $hasNextPage = \count($rows) > $this->options->limit;
        $rows = \array_slice($rows, 0, $this->options->limit);

        return $hasNextPage;
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<mixed>
     */
    private function withoutEnvelopes(GraphqlEntityMetadata $metadata, array $row): array
    {
        foreach ($metadata->fields as $field) {
            $value = $row[$field->mappedFrom] ?? null;

            if ($field->relation === null || !\is_array($value)) {
                continue;
            }

            $relationMetadata = $this->manager->getMetadataFactory()->getMetadata($field->relation);

            $row[$field->mappedFrom] = $field->isCollection
                ? array_map(
                    fn (array $item) => $this->withoutEnvelopes($relationMetadata, $item),
                    $this->rows($value)
                )
                : $this->withoutEnvelopes($relationMetadata, $value);
        }

        return $row;
    }

    /**
     * Each page works on its own options so that navigating never alters this query.
     *
     * @param string[] $cursorStack
     *
     * @return PaginatedResult<T>|null
     */
    private function fetchPage(?string $cursor, array $cursorStack): ?PaginatedResult
    {
        $options = clone $this->options;
        $options->cursor = $cursor;
        $options->cursorStack = $cursorStack;

        $page = (new self($this->graphql, $this->entityClass, $this->manager, $options, $this->unmappedFieldHints))->getResult();

        return $page instanceof PaginatedResult ? $page : null;
    }

    /**
     * @param array<mixed> $root
     *
     * @return list<array<mixed>>
     *
     * @throws InvalidGraphqlResponseException when a row is not an object
     */
    private function rows(array $root): array
    {
        $collection = $this->manager->getDialect()->extractCollection($root);
        $rows = array_is_list($collection) ? $collection : [$collection];

        return array_map(
            fn (mixed $row): array => \is_array($row) ? $row : throw InvalidGraphqlResponseException::expectedArray($row),
            $rows
        );
    }

    /**
     * @return T|null
     */
    public function getOneOrNullResult(): ?object
    {
        return $this->limitedTo(1)->items()[0] ?? null;
    }

    /**
     * The only entity the query returns. Two are requested, unless the query has a limit, to tell it is unique.
     *
     * @return T
     *
     * @throws NoResultException        when the query returns nothing
     * @throws NonUniqueResultException when the query returns several entities
     */
    public function getSingleResult(): object
    {
        $items = $this->limitedTo(2)->items();

        return match (\count($items)) {
            0 => throw NoResultException::forClass($this->entityClass),
            1 => $items[0],
            default => throw NonUniqueResultException::forClass($this->entityClass),
        };
    }

    /**
     * @param T[] $entities
     *
     * @return array<int|string, T>
     *
     * @throws InvalidArgumentException when an entity has no value usable as a key
     */
    private static function indexed(array $entities, string $property): array
    {
        $indexed = [];

        foreach ($entities as $entity) {
            $value = (new \ReflectionProperty($entity, $property))->getValue($entity);
            $key = match (true) {
                \is_int($value), \is_string($value) => $value,
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \Stringable => (string) $value,
                default => throw new InvalidArgumentException(\sprintf('"%s::$%s" holds no value usable as a key of the result.', $entity::class, $property)),
            };
            $indexed[$key] = $entity;
        }

        return $indexed;
    }

    /**
     * @return list<T>
     */
    private function items(): array
    {
        $result = $this->getResult();

        return array_values($result instanceof PaginatedResult ? $result->items : $result);
    }

    /**
     * @return GraphqlQuery<T>
     */
    private function limitedTo(int $limit): self
    {
        if ($this->options->limit !== null || !$this->graphql instanceof QueryNode) {
            return $this;
        }

        $options = clone $this->options;
        $options->limit = $limit;

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

        return new self($query, $this->entityClass, $this->manager, $options, $this->unmappedFieldHints);
    }
}
