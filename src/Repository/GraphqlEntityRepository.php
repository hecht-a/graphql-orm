<?php

declare(strict_types=1);

namespace GraphqlOrm\Repository;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\GraphqlQueryStringBuilder;
use GraphqlOrm\Query\QueryOptions;

/**
 * @template T of object
 */
class GraphqlEntityRepository
{
    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        protected readonly GraphqlManager $manager,
        protected string $entityClass,
    ) {
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function createQueryBuilder(): GraphqlQueryBuilder
    {
        return new GraphqlQueryBuilder(
            $this->entityClass,
            $this->manager
        );
    }

    /**
     * @return T|null
     *
     * @throws LogicException when the entity has no identifier
     */
    public function find(mixed $id): ?object
    {
        $identifier = $this->manager->getMetadataFactory()->getMetadata($this->entityClass)->identifier;

        if ($identifier === null) {
            throw new LogicException(\sprintf('"%s" has no identifier: mark one of its fields with #[GraphqlField(identifier: true)].', $this->entityClass));
        }

        return $this->findOneBy([$identifier->mappedFrom => $id]);
    }

    /**
     * @param array<string, mixed>          $criteria GraphQL field name => expected value
     * @param array<string, Direction>|null $orderBy  GraphQL field name => direction
     *
     * @return T|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed>          $criteria GraphQL field name => expected value
     * @param array<string, Direction>|null $orderBy  GraphQL field name => direction
     *
     * @return T[]
     *
     * @throws InvalidArgumentException when an ordering is not a Direction
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        foreach ($orderBy ?? [] as $field => $direction) {
            // Callers without static analysis may still pass 'ASC' or 'DESC' strings.
            // @phpstan-ignore instanceof.alwaysTrue
            if (!$direction instanceof Direction) {
                throw new InvalidArgumentException(\sprintf('The ordering of "%s" must be a %s.', $field, Direction::class));
            }
        }

        $options = new QueryOptions();
        $options->orderBy = $orderBy;
        $options->limit = $limit;

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);

        $query = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->arguments($this->manager->getDialect()->applyCriteria($criteria))
            ->options($options)
            ->build();

        /** @var T[] $result */
        $result = (new GraphqlQuery($query, $this->entityClass, $this->manager, $options))->getResult();

        return $result;
    }

    /**
     * @return T[]
     */
    public function findAll(): array
    {
        return $this->findBy([]);
    }
}
