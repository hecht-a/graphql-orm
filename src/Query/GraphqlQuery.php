<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Dialect\CursorPaginationDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Pagination\PaginatedResult;

/**
 * @template T of object
 */
final readonly class GraphqlQuery
{
    /**
     * @param class-string<T> $entityClass
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
            ->getMetadataFactory()
            ->getMetadata($this->entityClass);

        return $this->manager->execute(
            $this->graphql,
            function (array $result, GraphqlExecutionContext $context) use ($metadata) {
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

                /** @var T[] $items the rows are hydrated as instances of the queried entity */
                $items = array_map(
                    fn (array $row) => $this->manager
                        ->getHydrator()
                        ->hydrate($metadata, $row, $context),
                    $this->rows($root)
                );

                if (!$this->options->paginate) {
                    return $items;
                }

                $dialect = $this->manager->getDialect();

                if ($dialect instanceof CursorPaginationDialect) {
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
            },
            $this->options
        );
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

        return $this->manager->execute(
            $this->graphql,
            function (array $result) use ($metadata): array {
                $data = $result['data'] ?? null;
                $root = \is_array($data) ? ($data[$metadata->name] ?? null) : null;

                if ($root === null) {
                    return [];
                }

                if (!\is_array($root)) {
                    throw InvalidGraphqlResponseException::expectedArray($root);
                }

                return array_map(
                    fn (array $row) => $this->withoutEnvelopes($metadata, $row),
                    $this->rows($root)
                );
            },
            $this->options
        );
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

        $page = (new self($this->graphql, $this->entityClass, $this->manager, $options))->getResult();

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
