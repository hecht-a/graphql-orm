<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

final class GraphqlQueryTrace
{
    public readonly string $id;
    public string $graphql;
    /** @var array<string|int, mixed> */
    public ?array $ast = null;
    /** @var array<string, mixed> */
    public array $variables = [];
    /** @var array{
     *   file: string,
     *   line: int|null,
     *   class: string|null,
     *   function: string|null
     * }|null */
    public ?array $caller = null;
    public ?string $endpoint = null;
    public int $responseSize = 0;
    /** @var array<string|int, mixed>|null */
    public ?array $errors = null;
    public int $hydratedCount = 0;
    public int $hydratedEntities = 0;
    public int $hydratedRelations = 0;
    public int $hydratedCollections = 0;
    public int $hydrationMaxDepth = 0;
    /** The milliseconds spent sending the query and receiving its response. */
    public float|int $duration = 0;
    /** The milliseconds spent hydrating the response into entities, apart from $duration. */
    public float $hydrationDuration = 0.0;
    /** The Unix time the query was sent at, in seconds with microseconds. */
    public ?float $startedAt = null;
    /** The method of the bundle the application called (e.g. findBy, getResult), "lazy" for a lazy load; profiler only. */
    public ?string $origin = null;
    /** @var class-string|null the entities read, when known; profiler only */
    public ?string $entityClass = null;
    /** The identifier property of the entity a find() looks for, e.g. "id"; profiler only. */
    public ?string $identifierProperty = null;
    /** The relation whose first access sent this lazy load, e.g. App\Entity\Task::$assignee. */
    public ?string $lazyRelation = null;
    /** "hit" for a response read from the result cache, "miss" for one sent and kept; null without result cache. */
    public ?string $resultCache = null;
    /** The key given to enableResultCache(), null when derived from the query and its variables. */
    public ?string $resultCacheKey = null;
    /** The seconds given to enableResultCache(), null for the default lifetime of the pool. */
    public ?int $resultCacheLifetime = null;
    /** The property indexing the result (indexBy), e.g. "code"; profiler only. */
    public ?string $indexBy = null;
    /** @var array<string, array{type: string, collections: int, items: int}> the collections hydrated, by relation (e.g. App\Entity\User::$tasks) */
    public array $collections = [];
    /** A lazy load of a relation of an entity read from the second-level cache: a query the cache adds; profiler only. */
    public bool $fromCachedEntity = false;
    /** A lazy load sent while the serializer was writing an entity: an N+1 hidden in a response; profiler only. */
    public bool $duringSerialization = false;
    /** The beginning of the response, its values masked unless redact_query_values is false; profiler only. */
    public ?string $responsePreview = null;

    public function __construct()
    {
        $this->id = uniqid('gql_', true);
    }
}
