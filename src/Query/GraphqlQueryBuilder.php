<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\GraphqlManager;

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

    /**
     * @param class-string<T>   $entityClass
     * @param GraphqlManager<T> $manager
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly GraphqlManager $manager,
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
    public function where(string $field, mixed $value): self
    {
        $this->criteria[$field] = $value;

        return $this;
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

        $graphql = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->arguments($this->criteria)
            ->fields($fields, $manualSelect)
            ->build();

        return new GraphqlQuery(
            $graphql,
            $this->entityClass,
            $this->manager
        );
    }
}
