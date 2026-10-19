<?php

declare(strict_types=1);

namespace GraphqlOrm\DataCollector;

use GraphqlOrm\Mapping\Cache;

/**
 * What the queries of a profile suggest to change: the queries repeated in a loop, the relations loaded one by one,
 * the lines sending the costliest queries and the entities the second-level cache would serve.
 *
 * @internal
 */
final readonly class ProfileFindings
{
    public const LAZY_ORIGIN = 'lazy';

    /** The origins reading one entity of a class by its identifier: what the second-level cache serves. */
    private const ENTITY_ORIGINS = ['find', self::LAZY_ORIGIN];

    private const FIND_ORIGIN = 'find';

    /**
     * @param array<int, array<string, mixed>> $queries
     * @param int                              $threshold the times from which a repetition is reported
     */
    public function __construct(
        private array $queries,
        private int $threshold,
    ) {
    }

    /**
     * The shapes of queries sent at least $threshold times, with the numbers of these queries (from 1). When they are
     * all a find() of the same entity, its class and identifier property tell the findBy() replacing them.
     *
     * @return list<array{shape: string, count: int, queries: list<int>, find_entity: string|null, find_identifier: string|null}>
     */
    public function repeatedQueries(): array
    {
        $queriesByShape = [];

        foreach ($this->queries as $index => $query) {
            $shape = $query['shape'] ?? null;

            // The lazy loads are grouped by relation instead: see lazyLoadsByRelation().
            if (\is_string($shape) && ($query['origin'] ?? null) !== self::LAZY_ORIGIN) {
                $queriesByShape[$shape][] = $index + 1;
            }
        }

        $repeated = [];

        foreach ($queriesByShape as $shape => $queries) {
            if (\count($queries) >= $this->threshold) {
                [$entity, $identifier] = $this->foundOneByOne($queries);
                $repeated[] = ['shape' => (string) $shape, 'count' => \count($queries), 'queries' => $queries, 'find_entity' => $entity, 'find_identifier' => $identifier];
            }
        }

        return $repeated;
    }

    /**
     * The relations loaded on first access at least $threshold times, one query each: the N+1 problem of lazy loading.
     * With the numbers of their queries (from 1), their total duration and the lines reading them.
     *
     * @return list<array{relation: string, count: int, queries: list<int>, duration: float, callers: list<string>}>
     */
    public function lazyLoadsByRelation(): array
    {
        $byRelation = [];

        foreach ($this->queries as $index => $query) {
            $relation = $query['lazy_relation'] ?? null;

            if (($query['origin'] ?? null) !== self::LAZY_ORIGIN || !\is_string($relation)) {
                continue;
            }

            $caller = ProfiledQuery::caller($query);
            $group = $byRelation[$relation] ?? ['relation' => $relation, 'count' => 0, 'queries' => [], 'duration' => 0.0, 'callers' => []];
            ++$group['count'];
            $group['queries'][] = $index + 1;
            $group['duration'] += ProfiledQuery::milliseconds($query, 'duration');

            if ($caller !== null) {
                $group['callers'] = array_values(array_unique([...$group['callers'], $caller]));
            }

            $byRelation[$relation] = $group;
        }

        return array_values(array_filter($byRelation, fn (array $group): bool => $group['count'] >= $this->threshold));
    }

    /**
     * The queries grouped by the line sending them (null for those of the bundle itself, such as introspection), the
     * costliest first: the number of queries, their duration, their origins and entities, and their numbers (from 1).
     *
     * @return list<array{caller: string|null, count: int, duration: float, origins: list<string>, entities: list<string>, queries: list<int>}>
     */
    public function queriesByCaller(): array
    {
        $byCaller = [];

        foreach ($this->queries as $index => $query) {
            $key = ProfiledQuery::caller($query);
            $group = $byCaller[$key ?? ''] ?? ['caller' => $key, 'count' => 0, 'duration' => 0.0, 'origins' => [], 'entities' => [], 'queries' => []];
            ++$group['count'];
            $group['duration'] += ProfiledQuery::milliseconds($query, 'duration');

            if (\is_string($query['origin'] ?? null) && !\in_array($query['origin'], $group['origins'], true)) {
                $group['origins'][] = $query['origin'];
            }

            if (\is_string($query['entity_class'] ?? null) && !\in_array($query['entity_class'], $group['entities'], true)) {
                $group['entities'][] = $query['entity_class'];
            }

            $group['queries'][] = $index + 1;
            $byCaller[$key ?? ''] = $group;
        }

        $groups = array_values($byCaller);
        usort($groups, static fn (array $a, array $b): int => [$b['duration'], $b['count']] <=> [$a['duration'], $a['count']]);

        return $groups;
    }

    /**
     * The entities not marked #[GraphqlOrm\Cache] found by identifier or loaded on first access at least $threshold
     * times: the second-level cache would read them without a query, if they rarely change.
     *
     * @return list<array{class: string, count: int, queries: list<int>}>
     */
    public function cacheCandidates(): array
    {
        $byClass = [];

        foreach ($this->queries as $index => $query) {
            $class = $query['entity_class'] ?? null;

            if (!\in_array($query['origin'] ?? null, self::ENTITY_ORIGINS, true) || !\is_string($class) || !class_exists($class)
                || (new \ReflectionClass($class))->getAttributes(Cache::class) !== []) {
                continue;
            }

            $byClass[$class] ??= ['class' => $class, 'count' => 0, 'queries' => []];
            ++$byClass[$class]['count'];
            $byClass[$class]['queries'][] = $index + 1;
        }

        return array_values(array_filter($byClass, fn (array $candidate): bool => $candidate['count'] >= $this->threshold));
    }

    /**
     * The entity class and identifier property when these queries are all a find() of the same entity.
     *
     * @param list<int> $numbers the numbers of the queries (from 1)
     *
     * @return array{string|null, string|null}
     */
    private function foundOneByOne(array $numbers): array
    {
        $found = [];

        foreach ($numbers as $number) {
            $query = $this->queries[$number - 1] ?? [];
            $entity = $query['entity_class'] ?? null;
            $identifier = $query['identifier_property'] ?? null;

            if (($query['origin'] ?? null) !== self::FIND_ORIGIN || !\is_string($entity) || !\is_string($identifier)) {
                return [null, null];
            }

            $found[$entity . '::$' . $identifier] = [$entity, $identifier];
        }

        return \count($found) === 1 ? array_values($found)[0] : [null, null];
    }
}
