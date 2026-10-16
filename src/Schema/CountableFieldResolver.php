<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\GraphqlOrmException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Knows the fields Data API Builder can count an entity on: its aggregations only take the numeric fields listed by
 * `<Type>NumericAggregateFields`, and only count their values that are not null, so that a field never null in the
 * schema counts every entity. The introspection result is cached, a failed one as well.
 *
 * @internal
 */
final readonly class CountableFieldResolver
{
    private const PROFILER_NAME = 'countable_fields';

    private const CACHE_KEY = 'graphql_orm.countable_fields';

    private const CONNECTION_SUFFIX = 'Connection';

    private const AGGREGATE_FIELDS_SUFFIX = 'NumericAggregateFields';

    private const INTROSPECTION_QUERY = <<<'GQL'
        query CountableFields {
            __schema {
                queryType {
                    fields {
                        name
                        type {
                            kind
                            name
                            ofType {
                                kind
                                name
                            }
                        }
                    }
                }
                types {
                    name
                    kind
                    enumValues {
                        name
                    }
                    fields {
                        name
                        type {
                            kind
                        }
                    }
                }
            }
        }
        GQL;

    public function __construct(
        private GraphqlClientInterface $client,
        private CacheInterface $cache,
        private int $ttl,
        private ?LoggerInterface $logger = null,
        private ?GraphqlOrmDataCollector $collector = null,
    ) {
    }

    /**
     * @return list<string>|null the numeric fields never null of the entity, in the order of the schema; null when the
     *                           root field is no connection of Data API Builder or the schema is unknown
     */
    public function countableFields(string $rootField): ?array
    {
        $startedAt = hrtime(true);
        $introspected = false;
        $fields = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) use (&$introspected): ?array {
            $item->expiresAfter($this->ttl);
            $introspected = true;

            return $this->introspect();
        });
        $this->collector?->introspectionCacheLookup(self::PROFILER_NAME, $introspected, (hrtime(true) - $startedAt) / 1e6);

        $countable = \is_array($fields) ? ($fields[$rootField] ?? null) : null;

        /** @var list<string>|null $countable */
        return \is_array($countable) ? $countable : null;
    }

    /**
     * @return array<string, list<string>>|null root field => countable fields
     */
    private function introspect(): ?array
    {
        try {
            $result = IntrospectionQuery::send($this->client, self::INTROSPECTION_QUERY, $this->collector);
        } catch (GraphqlOrmException $e) {
            $this->warn($e->getMessage());

            return null;
        }

        $schema = \is_array($result['data'] ?? null) ? ($result['data']['__schema'] ?? null) : null;
        $rootFields = \is_array($schema) && \is_array($schema['queryType'] ?? null) ? ($schema['queryType']['fields'] ?? null) : null;

        if (!\is_array($rootFields) || !\is_array($schema['types'] ?? null)) {
            $this->warn('the response does not describe the schema');

            return null;
        }

        $types = [];

        foreach ($schema['types'] as $type) {
            if (\is_array($type) && \is_string($type['name'] ?? null)) {
                $types[$type['name']] = $type;
            }
        }

        $countable = [];

        foreach ($rootFields as $rootField) {
            $entityType = \is_array($rootField) ? $this->entityType($rootField['type'] ?? null) : null;

            if ($entityType !== null && \is_string($rootField['name'] ?? null) && isset($types[$entityType])) {
                $countable[$rootField['name']] = $this->countableFieldsOf($types[$entityType], $types[$entityType . self::AGGREGATE_FIELDS_SUFFIX] ?? []);
            }
        }

        return $countable;
    }

    /**
     * The entity type of a root field returning a connection of Data API Builder, e.g. Task for TaskConnection!.
     */
    private function entityType(mixed $type): ?string
    {
        if (\is_array($type) && ($type['kind'] ?? null) === 'NON_NULL') {
            $type = $type['ofType'] ?? null;
        }

        $name = \is_array($type) ? ($type['name'] ?? null) : null;

        return \is_string($name) && str_ends_with($name, self::CONNECTION_SUFFIX)
            ? substr($name, 0, -\strlen(self::CONNECTION_SUFFIX))
            : null;
    }

    /**
     * @param array<mixed> $entityType
     * @param array<mixed> $aggregateFields the enumeration of the fields an aggregation accepts
     *
     * @return list<string>
     */
    private function countableFieldsOf(array $entityType, array $aggregateFields): array
    {
        $neverNull = [];

        foreach (\is_array($entityType['fields'] ?? null) ? $entityType['fields'] : [] as $field) {
            if (\is_array($field) && \is_string($field['name'] ?? null) && \is_array($field['type'] ?? null) && ($field['type']['kind'] ?? null) === 'NON_NULL') {
                $neverNull[$field['name']] = true;
            }
        }

        $countable = [];

        foreach (\is_array($aggregateFields['enumValues'] ?? null) ? $aggregateFields['enumValues'] : [] as $value) {
            if (\is_array($value) && \is_string($value['name'] ?? null) && isset($neverNull[$value['name']])) {
                $countable[] = $value['name'];
            }
        }

        return $countable;
    }

    private function warn(string $reason): void
    {
        $this->logger?->warning('GraphQL ORM: introspection of the countable fields failed, count() needs a numeric identifier: ' . $reason);
    }
}
