<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Expr\ExpressionBuilder;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;

/**
 * @template T of object
 */
final class GraphqlQueryBuilder
{
    /** @var array<string, mixed> */
    private array $criteria = [];
    /** @var string[]|null */
    private ?array $selectedFields = null;
    private ?string $graphql = null;
    private ?FilterExpressionInterface $filter = null;

    /**
     * @param class-string<T>   $entityClass
     * @param GraphqlManager<T> $manager
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
                $this->manager
            );
        }

        $metadata = $this->manager
            ->metadataFactory
            ->getMetadata($this->entityClass);

        $fields = $this->selectedFields
            ?? array_map(
                fn ($f) => $f->mappedFrom,
                $metadata->fields
            );

        $manualSelect = $this->selectedFields !== null;

        $ast = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->arguments($this->criteria)
            ->options($this->options)
            ->filter($this->filter)
            ->fields($fields, $manualSelect)
            ->build();

        return new GraphqlQuery(
            $ast,
            $this->entityClass,
            $this->manager
        );
    }
}
