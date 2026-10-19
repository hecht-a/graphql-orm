<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Converter\TypeConverterRegistry;
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
use GraphqlOrm\Hydrator\DtoHydrator;
use GraphqlOrm\Hydrator\EmbeddedRows;
use GraphqlOrm\Metadata\DtoMetadata;
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
     * @param class-string<T>  $entityClass        the entity queried; with a DTO, T is the DTO
     * @param list<string>     $unmappedFieldHints appended to the message of a failure
     * @param DtoMetadata|null $dto                the DTO the rows are hydrated as, rather than entities
     */
    public function __construct(
        private QueryNode|string $graphql,
        private string $entityClass,
        private GraphqlManager $manager,
        private QueryOptions $options,
        private array $unmappedFieldHints = [],
        private ?DtoMetadata $dto = null,
    ) {
    }

    /**
     * @return class-string the class of the entity queried
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
     * Keeps the response in the result cache (graphql_orm.result_cache.pool) and answers from it while it is kept, as
     * Doctrine Query::enableResultCache(). The response is hydrated again from the cache, through the identity map of
     * the request: an entity it knows already is given as it is, the others are new instances.
     *
     * @param int|null    $lifetime seconds the response is kept, null for the default lifetime of the pool
     * @param string|null $key      the key to evict it with GraphqlCache::evictQueryResult(); derived from the query and
     *                              its variables when null
     *
     * @return self<T>
     */
    public function enableResultCache(?int $lifetime = null, ?string $key = null): self
    {
        $options = clone $this->options;
        $options->resultCache = true;
        $options->resultCacheLifetime = $lifetime;
        $options->resultCacheKey = $key;

        return $this->with($options);
    }

    /**
     * @return self<T>
     */
    public function disableResultCache(): self
    {
        $options = clone $this->options;
        $options->resultCache = false;

        return $this->with($options);
    }

    /**
     * This query with other options, or another query sent for the same entities.
     *
     * @return self<T>
     */
    private function with(QueryOptions $options, QueryNode|string|null $graphql = null): self
    {
        return new self($graphql ?? $this->graphql, $this->entityClass, $this->manager, $options, $this->unmappedFieldHints, $this->dto);
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

            if ($this->dto !== null) {
                $context->trace->hydratedAs = $this->dto->class;
                $items = $this->dtos($metadata, $rows, $context);
            } else {
                /** @var T[] $items the rows are hydrated as instances of the queried entity */
                $items = array_map(
                    fn (array $row) => $this->manager
                        ->getHydrator()
                        ->hydrate($metadata, $row, $context),
                    $rows
                );
            }

            if ($this->options->indexBy !== null) {
                $context->trace->indexBy = $this->options->indexBy;
                // DTOs are keyed by their row, as they may not hold the field.
                $items = $this->dto === null ? self::indexed($items, $this->options->indexBy, $metadata, $context->typeConverters) : $items;
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
        $page = $this->with($options)->getResult();

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
     * The rows hydrated as DTOs, keyed by the field of indexBy() when set.
     *
     * @param list<array<mixed>> $rows
     *
     * @return T[]
     *
     * @throws InvalidArgumentException when a row has no value usable as a key
     */
    private function dtos(GraphqlEntityMetadata $metadata, array $rows, GraphqlExecutionContext $context): array
    {
        \assert($this->dto !== null);
        $key = $this->options->indexBy !== null ? $metadata->graphqlName($this->options->indexBy) : null;
        $dtos = [];

        foreach ($rows as $row) {
            /** @var T $dto the rows are hydrated as instances of the DTO */
            $dto = DtoHydrator::hydrate($this->dto, $this->withoutEnvelopes($metadata, $row, nestEmbedded: true), $context->typeConverters);

            if ($key === null) {
                $dtos[] = $dto;

                continue;
            }

            $value = $row[$key] ?? null;

            if (!\is_int($value) && !\is_string($value)) {
                throw new InvalidArgumentException(\sprintf('"%s" holds no value usable as a key of the result.', $key));
            }

            $dtos[$value] = $dto;
        }

        return $dtos;
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
    private function withoutEnvelopes(GraphqlEntityMetadata $metadata, array $row, bool $nestEmbedded = false): array
    {
        // The prefixed fields of the value objects nested under their key, as the rows of the entities.
        $row = $nestEmbedded ? EmbeddedRows::nest($metadata, $row) : $row;

        foreach ($metadata->fields as $field) {
            $value = $row[$field->mappedFrom] ?? null;

            if ($field->relation === null || !\is_array($value)) {
                continue;
            }

            $relationMetadata = $this->manager->getMetadataFactory()->getMetadata($field->relation);

            $row[$field->mappedFrom] = $field->isCollection
                ? array_map(
                    fn (array $item) => $this->withoutEnvelopes($relationMetadata, $item, $nestEmbedded),
                    $this->rows($value)
                )
                : $this->withoutEnvelopes($relationMetadata, $value, $nestEmbedded);
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

        $page = $this->with($options)->getResult();

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
    private static function indexed(array $entities, string $property, GraphqlEntityMetadata $metadata, ?TypeConverterRegistry $converters): array
    {
        $indexed = [];
        $field = $metadata->field($property);
        $reflection = null;

        foreach ($entities as $entity) {
            $value = ($reflection ??= new \ReflectionProperty($entity, $property))->getValue($entity);
            // A value object is keyed by the value of the API its type converter gives.
            $value = $converters !== null ? $converters->toGraphql($field?->converter, $field?->valueClass, $value) : $value;
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

        return $this->with($options, $query);
    }
}
