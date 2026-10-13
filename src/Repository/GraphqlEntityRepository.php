<?php

declare(strict_types=1);

namespace GraphqlOrm\Repository;

use GraphqlOrm\Dialect\CountingDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryStringBuilder;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\UnmappedFieldHints;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;

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
     * @param array<string, mixed>          $criteria GraphQL field name or PHP property => expected value
     * @param array<string, Direction>|null $orderBy  GraphQL field name or PHP property => direction
     *
     * @return T|null
     */
    public function findOneBy(array $criteria, ?array $orderBy = null): ?object
    {
        return $this->findBy($criteria, $orderBy, 1)[0] ?? null;
    }

    /**
     * @param array<string, mixed>          $criteria GraphQL field name or PHP property => expected value
     * @param array<string, Direction>|null $orderBy  GraphQL field name or PHP property => direction
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

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);

        $options = new QueryOptions();
        $options->orderBy = $orderBy !== null ? $metadata->withGraphqlNames($orderBy) : null;
        $options->limit = $limit;

        $query = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->arguments($this->manager->getDialect()->applyCriteria($metadata->withGraphqlNames($criteria)))
            ->options($options)
            ->build();

        $hints = (new UnmappedFieldHints($this->manager->getMetadataFactory()))->for($this->entityClass, [...array_keys($criteria), ...array_keys($orderBy ?? [])]);

        /** @var T[] $result */
        $result = (new GraphqlQuery($query, $this->entityClass, $this->manager, $options, $hints))->getResult();

        return $result;
    }

    /**
     * Counts the entities matching the criteria, with a query of its own; values are sent inline, not as variables.
     *
     * @param array<string, mixed> $criteria GraphQL field name or PHP property => expected value
     *
     * @throws LogicException when the dialect cannot count
     */
    public function count(array $criteria = []): int
    {
        $dialect = $this->manager->getDialect();

        if (!$dialect instanceof CountingDialect) {
            throw new LogicException(\sprintf('"%s" cannot count entities: count() needs a dialect implementing %s.', $dialect::class, CountingDialect::class));
        }

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);
        $field = $dialect->countField($metadata->name, $dialect->applyCriteria($metadata->withGraphqlNames($criteria)), $metadata->identifier?->mappedFrom);

        $query = new QueryNode();
        $query->fields[] = $field;
        // The count field has no collection envelope: it is printed as is, whatever the dialect.
        $graphql = (new GraphqlQueryCompiler(new DefaultGraphqlWalker()))->compile($query, new QueryOptions());

        try {
            [$count] = $this->manager->execute(
                $graphql,
                fn (array $result): array => [$dialect->readCount(\is_array($result['data'] ?? null) ? $result['data'] : [], $field->name)],
            );
        } catch (GraphqlRequestException $exception) {
            throw $exception->withHints((new UnmappedFieldHints($this->manager->getMetadataFactory()))->for($this->entityClass, array_keys($criteria)));
        }

        return $count;
    }

    /**
     * @return T[]
     */
    public function findAll(): array
    {
        return $this->findBy([]);
    }
}
