<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Expr\ExpressionBuilder;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;
use GraphqlOrm\Query\Expr\GraphqlNamedFilter;

/**
 * @template T of object
 */
final class GraphqlQueryBuilder
{
    /** @var string[]|null */
    private ?array $selectedFields = null;
    private ?string $graphql = null;
    private ?FilterExpressionInterface $filter = null;

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly GraphqlManager $manager,
        private readonly QueryOptions $options = new QueryOptions(),
    ) {
    }

    /**
     * @param string ...$fields
     *
     * @return GraphqlQueryBuilder<T>
     */
    public function select(mixed ...$fields): self
    {
        $this->selectedFields = $fields;

        return $this;
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function addSelect(string $field): self
    {
        $this->selectedFields ??= [];
        $this->selectedFields[] = $field;

        return $this;
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function where(FilterExpressionInterface $expr): self
    {
        $this->filter = $expr;

        return $this;
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function limit(int $limit): self
    {
        $this->options->limit = $limit;

        return $this;
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function orderBy(string $orderBy, Direction $direction): self
    {
        $this->options->orderBy ??= [];
        $this->options->orderBy[$orderBy] = $direction;

        return $this;
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function paginate(?string $after = null): self
    {
        $this->options->paginate = true;

        if ($after !== null) {
            $this->options->cursor = $after;
            $this->options->cursorStack[] = $after;
        }

        return $this;
    }

    public function expr(): ExpressionBuilder
    {
        return new ExpressionBuilder();
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function setGraphQL(string $graphql): self
    {
        $this->graphql = $graphql;

        return $this;
    }

    /**
     * @return GraphqlQuery<T>
     */
    public function getQuery(): GraphqlQuery
    {
        if ($this->graphql !== null) {
            return new GraphqlQuery(
                $this->graphql,
                $this->entityClass,
                $this->manager,
                $this->options
            );
        }

        $metadata = $this->manager
            ->getMetadataFactory()
            ->getMetadata($this->entityClass);

        $options = clone $this->options;
        $options->orderBy = $options->orderBy !== null ? $metadata->withGraphqlNames($options->orderBy) : null;

        $fields = $this->selectedFields
            ?? array_map(
                fn ($f) => $f->mappedFrom,
                $metadata->fields
            );

        $manualSelect = $this->selectedFields !== null;

        $ast = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->options($options)
            ->filter($this->filter !== null ? new GraphqlNamedFilter($this->filter, $metadata, $this->manager->getMetadataFactory()) : null)
            ->fields($fields, $manualSelect)
            ->build();

        $paths = [
            ...array_filter($this->selectedFields ?? [], \is_string(...)),
            ...array_keys($options->orderBy ?? []),
            ...($this->filter !== null ? GraphqlNamedFilter::fieldNames($this->filter->toArray()) : []),
        ];

        return new GraphqlQuery(
            $ast,
            $this->entityClass,
            $this->manager,
            $options,
            (new UnmappedFieldHints($this->manager->getMetadataFactory()))->for($this->entityClass, $paths),
        );
    }
}
