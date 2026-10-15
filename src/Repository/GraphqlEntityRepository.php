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
     * @return T|null
     *
     * @throws LogicException when the entity has no identifier
     */
    public function find(mixed $id): ?object
    {
        $identifier = $this->manager->getMetadataFactory()->getMetadata($this->entityClass)->identifier;

        if ($identifier === null) {
            throw new LogicException(\sprintf('"%s" has no identifier: mark its identifier field with #[Identifier].', $this->entityClass));
        }

        $known = \is_int($id) || \is_string($id) ? $this->manager->findKnown($this->entityClass, $id) : null;

        return $known ?? $this->findOneBy([$identifier->mappedFrom => $id]);
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
        // An empty ordering, accepted by Doctrine, would be sent as an empty argument that servers reject.
        $options->orderBy = $orderBy !== null && $orderBy !== [] ? $metadata->withGraphqlNames($orderBy) : null;
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
        $field = $dialect->countField($metadata->name, $dialect->applyCriteria($metadata->withGraphqlNames($criteria)), $this->numericIdentifier($metadata));

        return $this->executeInline($field, fn (array $data): int => $dialect->readCount($data, $field->name), array_keys($criteria));
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
        $root = $dialect->aggregateField($metadata->name, $dialect->applyCriteria($metadata->withGraphqlNames($criteria)), $aggregate, $graphqlField);

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
