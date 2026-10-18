<?php

declare(strict_types=1);

namespace GraphqlOrm\Repository;

use GraphqlOrm\Dialect\AggregatingDialect;
use GraphqlOrm\Dialect\CountingDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Query\Aggregate;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryStringBuilder;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Query\RelationTree;
use GraphqlOrm\Query\UnmappedFieldHints;
use GraphqlOrm\Query\Walker\DefaultGraphqlWalker;

/**
 * @template T of object
 */
class GraphqlEntityRepository
{
    /** @var array<string, mixed> the relations the find methods load, as a tree of GraphQL names */
    private array $with = [];

    /** @var list<string> the same relations, as written: told to the profiler */
    private array $withPaths = [];

    /**
     * @param class-string<T> $entityClass
     */
    public function __construct(
        protected readonly GraphqlManager $manager,
        protected string $entityClass,
    ) {
        $manager->assertManages($entityClass);
    }

    /**
     * @return class-string<T> the class of the entities of this repository, as Doctrine EntityRepository::getClassName()
     */
    public function getClassName(): string
    {
        return $this->entityClass;
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
     * A copy of this repository whose find methods load the relations named, nested ones by their path
     * (assignee.company), in place of max_depth, as with() of the query builder:
     * `$tasks->with('assignee')->findBy([])`. This repository is left as it is. An entity find() serves from the
     * identity map or the second-level cache is given as it was loaded.
     *
     * @param string ...$relations paths of GraphQL field names or PHP properties
     *
     * @throws InvalidArgumentException when a name is not a relation of its entity
     */
    public function with(string ...$relations): static
    {
        $repository = clone $this;
        $repository->with = (new RelationTree($this->manager->getMetadataFactory()))->add($this->with, $this->entityClass, array_values($relations));
        $repository->withPaths = [...$this->withPaths, ...array_values($relations)];

        return $repository;
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
            throw new LogicException(\sprintf('"%s" has no identifier: mark its identifier field with #[GraphqlOrm\Identifier].', $this->entityClass));
        }

        $known = \is_int($id) || \is_string($id) ? $this->manager->findKnown($this->entityClass, $id) : null;

        return $known ?? $this->findOneBy([$identifier->mappedFrom => $id]);
    }

    /**
     * The entity of this identifier without a query, as Doctrine getReference(): only its identifier is set, the rest
     * is loaded on first access (an EntityNotFoundException then if it does not exist). The entity the shared identity
     * map knows is given as is.
     *
     * @return T
     *
     * @throws LogicException when the entity has no identifier
     */
    public function getReference(int|string $id): object
    {
        return $this->manager->reference($this->entityClass, $id);
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
     * @param string|null                   $indexBy  GraphQL field name or PHP property keying the result, as Doctrine
     *                                                indexBy (pass it by name: Doctrine has an offset there)
     *
     * @return T[]
     *
     * @throws InvalidArgumentException when an ordering is not a Direction, or when the entity maps no indexBy field
     */
    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null, ?string $indexBy = null): array
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
        // An empty ordering, accepted by Doctrine, would be sent as an empty argument that servers reject.
        $options->orderBy = $orderBy !== null && $orderBy !== [] ? $metadata->withGraphqlNames($orderBy) : null;
        $options->limit = $limit;
        $options->indexBy = $indexBy !== null ? $metadata->property($indexBy) : null;
        $options->with = $this->withPaths;

        $query = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->arguments($this->manager->getDialect()->applyCriteria($this->criteria($metadata, $criteria)))
            ->options($options)
            ->with($this->with !== [] ? $this->with : null)
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
     * @throws LogicException          when the dialect cannot count
     * @throws GraphqlRequestException when the query fails
     */
    public function count(array $criteria = []): int
    {
        $dialect = $this->manager->getDialect();

        if (!$dialect instanceof CountingDialect) {
            throw new LogicException(\sprintf('"%s" cannot count entities: count() needs a dialect implementing %s.', $dialect::class, CountingDialect::class));
        }

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);
        $field = $dialect->countField($metadata->name, $dialect->applyCriteria($this->criteria($metadata, $criteria)), $this->numericIdentifier($metadata));

        return $this->executeInline($field, fn (array $data): int => $dialect->readCount($data, $field->name), array_keys($criteria));
    }

    /**
     * The criteria keyed by GraphQL names, their value objects converted by their type converter.
     *
     * @param array<string, mixed> $criteria GraphQL field name or PHP property => expected value
     *
     * @return array<string, mixed>
     */
    private function criteria(GraphqlEntityMetadata $metadata, array $criteria): array
    {
        $converted = [];

        foreach ($criteria as $name => $value) {
            $field = $metadata->valueField($name);
            $converted[$name] = $this->manager->getTypeConverters()->toGraphql($field?->converter, $field?->valueClass, $value);
        }

        return $metadata->withGraphqlNames($converted);
    }

    /**
     * The GraphQL name of the identifier, unless its property is typed with no number: Data API Builder counts the
     * values of a numeric field, and finds another one in the schema otherwise.
     */
    private function numericIdentifier(GraphqlEntityMetadata $metadata): ?string
    {
        if ($metadata->identifier === null) {
            return null;
        }

        $type = (new \ReflectionProperty($this->entityClass, $metadata->identifier->property))->getType();
        $names = match (true) {
            $type instanceof \ReflectionNamedType => [$type->getName()],
            $type instanceof \ReflectionUnionType => array_map(fn (\ReflectionType $type) => $type instanceof \ReflectionNamedType ? $type->getName() : '', $type->getTypes()),
            default => ['int'],
        };

        return array_intersect($names, ['int', 'float']) !== [] ? $metadata->identifier->mappedFrom : null;
    }

    /**
     * Sums a field over the entities matching the criteria, with a query of its own; null when none matches.
     *
     * @param string               $field    GraphQL field name or PHP property
     * @param array<string, mixed> $criteria GraphQL field name or PHP property => expected value
     *
     * @throws LogicException          when the dialect cannot aggregate
     * @throws GraphqlRequestException when the query fails
     */
    public function sum(string $field, array $criteria = []): int|float|null
    {
        return self::number($this->aggregate(Aggregate::Sum, $field, $criteria));
    }

    /**
     * Averages a field over the entities matching the criteria, with a query of its own; null when none matches.
     *
     * @param string               $field    GraphQL field name or PHP property
     * @param array<string, mixed> $criteria GraphQL field name or PHP property => expected value
     *
     * @throws LogicException          when the dialect cannot aggregate
     * @throws GraphqlRequestException when the query fails
     */
    public function avg(string $field, array $criteria = []): int|float|null
    {
        return self::number($this->aggregate(Aggregate::Avg, $field, $criteria));
    }

    /**
     * The smallest value of a field over the entities matching the criteria, as sent by the API (a date is a string);
     * null when none matches.
     *
     * @param string               $field    GraphQL field name or PHP property
     * @param array<string, mixed> $criteria GraphQL field name or PHP property => expected value
     *
     * @throws LogicException          when the dialect cannot aggregate
     * @throws GraphqlRequestException when the query fails
     */
    public function min(string $field, array $criteria = []): int|float|string|null
    {
        return $this->aggregate(Aggregate::Min, $field, $criteria);
    }

    /**
     * The largest value of a field over the entities matching the criteria, as sent by the API (a date is a string);
     * null when none matches.
     *
     * @param string               $field    GraphQL field name or PHP property
     * @param array<string, mixed> $criteria GraphQL field name or PHP property => expected value
     *
     * @throws LogicException          when the dialect cannot aggregate
     * @throws GraphqlRequestException when the query fails
     */
    public function max(string $field, array $criteria = []): int|float|string|null
    {
        return $this->aggregate(Aggregate::Max, $field, $criteria);
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @throws LogicException when the dialect cannot aggregate
     */
    private function aggregate(Aggregate $aggregate, string $field, array $criteria): int|float|string|null
    {
        $dialect = $this->manager->getDialect();

        if (!$dialect instanceof AggregatingDialect) {
            throw new LogicException(\sprintf('"%s" cannot aggregate entities: %s() needs a dialect implementing %s.', $dialect::class, $aggregate->value, AggregatingDialect::class));
        }

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);
        $graphqlField = $metadata->graphqlName($field);
        $root = $dialect->aggregateField($metadata->name, $dialect->applyCriteria($this->criteria($metadata, $criteria)), $aggregate, $graphqlField);

        return $this->executeInline(
            $root,
            fn (array $data): int|float|string|null => $dialect->readAggregate($data, $root->name, $aggregate, $graphqlField),
            [$field, ...array_keys($criteria)],
        );
    }

    /**
     * A number sent as a string, as some servers do for large or decimal sums, is converted.
     */
    private static function number(int|float|string|null $value): int|float|null
    {
        return \is_string($value) ? (is_numeric($value) ? $value + 0 : null) : $value;
    }

    /**
     * Sends a count or aggregate field, printed as is whatever the dialect (it has no collection envelope), with its
     * values inline rather than as variables.
     *
     * @template R
     *
     * @param \Closure(array<mixed>): R $read  reads the result from the `data` of the response
     * @param list<string>              $paths the field names sent, pointed out when the query fails
     *
     * @return R
     */
    private function executeInline(FieldNode $field, \Closure $read, array $paths): mixed
    {
        $query = new QueryNode();
        $query->fields[] = $field;
        $graphql = (new GraphqlQueryCompiler(new DefaultGraphqlWalker()))->compile($query, new QueryOptions());

        try {
            [$result] = $this->manager->execute(
                $graphql,
                fn (array $response): array => [$read(\is_array($response['data'] ?? null) ? $response['data'] : [])],
            );
        } catch (GraphqlRequestException $exception) {
            throw $exception->withHints((new UnmappedFieldHints($this->manager->getMetadataFactory()))->for($this->entityClass, $paths));
        }

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
