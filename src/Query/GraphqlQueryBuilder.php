<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidArgumentException;
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
    /** @var array<string, mixed>|null the relations to load, as a tree of GraphQL names */
    private ?array $with = null;

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
     * Loads the relations named, nested ones by their path (assignee.company), in place of max_depth: the relations not
     * named are selected by their identifier only. A relation mapped with Fetch::Lazy is loaded when named. Adds to
     * the relations of the previous calls, and to the fields of select().
     *
     * @param string ...$relations paths of GraphQL field names or PHP properties
     *
     * @return GraphqlQueryBuilder<T>
     *
     * @throws InvalidArgumentException when a name is not a relation of its entity
     */
    public function with(string ...$relations): self
    {
        $this->with ??= [];

        foreach ($relations as $path) {
            $this->with = $this->addRelationPath($this->with, $this->entityClass, explode('.', $path));
        }

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
     * Adds a condition to those of where(), as Doctrine andWhere().
     *
     * @return GraphqlQueryBuilder<T>
     */
    public function andWhere(FilterExpressionInterface $expr): self
    {
        $this->filter = $this->filter !== null ? $this->expr()->andX($this->filter, $expr) : $expr;

        return $this;
    }

    /**
     * Keys the result by a field of its entities, as Doctrine indexBy: an identifier, a code...
     *
     * @param string $field GraphQL field name or PHP property
     *
     * @return GraphqlQueryBuilder<T>
     *
     * @throws InvalidArgumentException when the entity maps no such field
     */
    public function indexBy(string $field): self
    {
        $this->options->indexBy = $this->manager->getMetadataFactory()->getMetadata($this->entityClass)->property($field);

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
            ->with($this->with)
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

    /**
     * @param array<string, mixed> $tree
     * @param class-string         $entityClass
     * @param string[]             $names
     *
     * @return array<string, mixed>
     */
    private function addRelationPath(array $tree, string $entityClass, array $names): array
    {
        $name = array_shift($names);

        if ($name === null) {
            return $tree;
        }

        $metadata = $this->manager->getMetadataFactory()->getMetadata($entityClass);
        $field = $metadata->field($metadata->property($name));

        if ($field?->relation === null) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a relation of "%s".', $name, $entityClass));
        }

        /** @var array<string, mixed> $relations */
        $relations = $tree[$field->mappedFrom] ?? [];
        $tree[$field->mappedFrom] = $this->addRelationPath($relations, $field->relation, $names);

        return $tree;
    }
}
