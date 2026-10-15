<?php

declare(strict_types=1);

namespace GraphqlOrm;

use GraphqlOrm\Client\ConcurrentGraphqlClientInterface;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\BatchLoadingDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Event\QueryExecutedEvent;
use GraphqlOrm\Event\QueryFailedEvent;
use GraphqlOrm\Event\QueryStartedEvent;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Ast\AstExporter;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\CompiledQuery;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Depend on GraphqlManagerInterface to simulate or decorate it.
 */
#[AutoconfigureTag('graphql.manager')]
final class GraphqlManager implements GraphqlManagerInterface
{
    /**
     * Identifiers loaded by one lazy loading query.
     */
    private const LAZY_LOADING_BATCH_SIZE = 100;

    private bool $loadingLazily = false;

    /** @var array<class-string, GraphqlEntityRepository<object>> */
    private array $genericRepositories = [];

    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
        private GraphqlClientInterface $client,
        private EntityHydrator $hydrator,
        private ?GraphqlOrmDataCollector $collector,
        private int $maxDepth,
        private GraphqlQueryDialect $dialect = new DefaultDialect(),
        private ?GraphqlQueryCompiler $compiler = null,
        private ?LoggerInterface $logger = null,
        private ?QueryRedactor $redactor = null,
        private ?RootArgumentTypeResolver $argumentTypeResolver = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private bool $lazyLoading = false,
        private ?float $slowQueryThreshold = null,
        private ?IdentityMap $identityMap = null,
        private ?ContainerInterface $repositories = null,
    ) {
    }

    /**
     * The repository of an entity: the service of the repositoryClass of its #[Entity] when the application
     * declares one, a generic repository otherwise, as Doctrine EntityManager::getRepository().
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return GraphqlEntityRepository<T>
     */
    public function getRepository(string $class): GraphqlEntityRepository
    {
        $repositoryClass = $this->metadataFactory->getMetadata($class)->repositoryClass;

        if ($repositoryClass !== null && $this->repositories?->has($repositoryClass) === true) {
            $repository = $this->repositories->get($repositoryClass);

            if ($repository instanceof GraphqlEntityRepository) {
                /** @var GraphqlEntityRepository<T> $repository the repositoryClass of the entity */
                return $repository;
            }
        }

        /** @var GraphqlEntityRepository<T> */
        return $this->genericRepositories[$class] ??= new GraphqlEntityRepository($this, $class);
    }

    /**
     * The entity known in full with this identifier in the shared identity map, if any: find() then needs no query.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public function findKnown(string $class, int|string $identifier): ?object
    {
        return $this->identityMap?->find($class, $identifier);
    }

    /**
     * Forgets the entities of the shared identity map, as between two requests: for a long-running script.
     */
    public function clear(): void
    {
        $this->identityMap?->reset();
    }

    /**
     * @template R of array<mixed>|PaginatedResult<object>
     *
     * @param callable(array<string, mixed>, GraphqlExecutionContext): R $hydration turns the response into the result
     * @param array<string, mixed>                                       $variables
     *
     * @return R
     */
    public function execute(QueryNode|string $graphql, callable $hydration, QueryOptions $options = new QueryOptions(), array $variables = []): array|PaginatedResult
    {
        return $this->send($graphql, $hydration, $options, $variables)();
    }

    /**
     * Sends the query and returns a function completing it: waiting for the response, hydrating it, recording it. With
     * a client unable to send concurrently, the query is only sent when completed. GraphqlQuery::concurrently() sends
     * several queries this way before completing them.
     *
     * @template R of array<mixed>|PaginatedResult<object>
     *
     * @param callable(array<string, mixed>, GraphqlExecutionContext): R $hydration turns the response into the result
     * @param array<string, mixed>                                       $variables
     *
     * @return \Closure(): R
     */
    public function send(QueryNode|string $graphql, callable $hydration, QueryOptions $options = new QueryOptions(), array $variables = []): \Closure
    {
        $context = new GraphqlExecutionContext(dialect: $this->dialect);
        $shared = $this->identityMap !== null && !$options->detached && !$this->loadingLazily;

        if ($shared) {
            $context->identityMap = $this->identityMap->entities();
        }

        // Always set: a relation mapped with Fetch::Lazy is a lazy ghost even without lazy_loading.
        $context->lazyLoader = $this->loadLazily(...);
        $context->lazyLoading = $this->lazyLoading;

        if ($graphql instanceof QueryNode) {
            $compiledQuery = $this->compile($graphql, $options);
            $compiled = $compiledQuery->graphql;
            $variables = [...$variables, ...$compiledQuery->variables];
            $context->trace->ast = AstExporter::export($graphql);
        } else {
            $compiled = $graphql;
        }

        $context->trace->graphql = $compiled;
        $context->trace->variables = $variables;

        $this->eventDispatcher?->dispatch(new QueryStartedEvent($this->redact(clone $context->trace)));

        try {
            $response = $this->client instanceof ConcurrentGraphqlClientInterface
                ? $this->client->send($compiled, $context, $variables)
                : fn (): array => $this->client->query($compiled, $context, $variables);
        } catch (\Throwable $exception) {
            $this->record($context, $exception);

            throw $exception;
        }

        return function () use ($response, $hydration, $context, $shared): array|PaginatedResult {
            $failure = null;

            try {
                $entities = $hydration($response(), $context);
                $context->trace->hydratedCount = \count($entities instanceof PaginatedResult ? $entities->items : $entities);

                if ($shared) {
                    $this->identityMap?->merge($context->identityMap, $context->completeEntities);
                }
            } catch (\Throwable $exception) {
                $failure = $exception;

                throw $exception;
            } finally {
                $this->record($context, $failure);
            }

            return $entities;
        };
    }

    /**
     * Records a completed query in the profiler and the logs, and dispatches its outcome.
     */
    private function record(GraphqlExecutionContext $context, ?\Throwable $failure): void
    {
        $trace = $this->redact(clone $context->trace);
        $this->collector?->addQuery($trace);
        $this->log($trace);
        $this->eventDispatcher?->dispatch($failure !== null ? new QueryFailedEvent($trace, $failure) : new QueryExecutedEvent($trace));
    }

    /**
     * Loads the entities behind lazy ghosts, with queries of their own (recorded in the profiler and the logs): one per
     * batch of identifiers when the dialect filters on a list, one per entity otherwise.
     *
     * @param class-string     $class
     * @param list<int|string> $identifiers
     *
     * @return list<object> the entities found
     */
    private function loadLazily(string $class, array $identifiers): array
    {
        // A ghost is loaded while it initializes: its query must not meet it again through the shared identity map.
        $loadingLazily = $this->loadingLazily;
        $this->loadingLazily = true;

        try {
            $repository = new GraphqlEntityRepository($this, $class);
            $identifier = $this->metadataFactory->getMetadata($class)->identifier;

            if ($identifier === null || \count($identifiers) === 1 || !$this->dialect instanceof BatchLoadingDialect) {
                return array_values(array_filter(array_map($repository->find(...), $identifiers)));
            }

            $entities = [];

            foreach (array_chunk($identifiers, self::LAZY_LOADING_BATCH_SIZE) as $batch) {
                $qb = $repository->createQueryBuilder();
                $result = $qb->where($qb->expr()->in($identifier->mappedFrom, $batch))->limit(\count($batch))->getQuery()->getResult();
                $entities = [...$entities, ...array_values($result instanceof PaginatedResult ? $result->items : $result)];
            }

            return $entities;
        } finally {
            $this->loadingLazily = $loadingLazily;
        }
    }

    public function getDialect(): GraphqlQueryDialect
    {
        return $this->dialect;
    }

    public function getMetadataFactory(): GraphqlEntityMetadataFactory
    {
        return $this->metadataFactory;
    }

    public function getHydrator(): EntityHydrator
    {
        return $this->hydrator;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getQueryCompiler(): GraphqlQueryCompiler
    {
        if ($this->compiler !== null) {
            return $this->compiler;
        }

        $walker = $this->dialect->createWalker();

        $this->compiler = new GraphqlQueryCompiler($walker);

        return $this->compiler;
    }

    private function compile(QueryNode $query, QueryOptions $options): CompiledQuery
    {
        $rootField = \count($query->fields) === 1 ? $query->fields[0]->name : null;
        $argumentTypes = $rootField !== null ? $this->argumentTypeResolver?->argumentTypes($rootField) : null;

        if ($argumentTypes === null) {
            return new CompiledQuery($this->getQueryCompiler()->compile($query, $options));
        }

        $compiled = $this->getQueryCompiler()->compileWithVariables($query, $options, $argumentTypes);

        if ($compiled->untypedArguments !== []) {
            $this->logger?->warning('GraphQL ORM: arguments missing from the schema, values are sent inline', [
                'root_field' => $rootField,
                'arguments' => $compiled->untypedArguments,
            ]);
        }

        return $compiled;
    }

    /**
     * The query sent to the API keeps its values: only what is logged or shown in the profiler is redacted.
     */
    private function redact(GraphqlQueryTrace $trace): GraphqlQueryTrace
    {
        if ($this->redactor === null) {
            return $trace;
        }

        $redacted = clone $trace;
        $redacted->graphql = $this->redactor->redactQuery($trace->graphql);
        $redacted->variables = $this->redactor->redactValues($trace->variables);
        $redacted->ast = $trace->ast !== null ? $this->redactor->redactAst($trace->ast) : null;

        return $redacted;
    }

    private function log(GraphqlQueryTrace $trace): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'endpoint' => $trace->endpoint,
            'duration_ms' => round($trace->duration, 2),
            'response_size' => $trace->responseSize,
            'hydrated_entities' => $trace->hydratedEntities,
            'hydrated_relations' => $trace->hydratedRelations,
            'caller' => $trace->caller,
            'query' => $trace->graphql,
        ];

        if (!empty($trace->errors)) {
            $this->logger->error('GraphQL query returned errors', [
                ...$context,
                'errors' => $trace->errors,
            ]);

            return;
        }

        if ($this->slowQueryThreshold !== null && $trace->duration >= $this->slowQueryThreshold) {
            $this->logger->warning('Slow GraphQL query', [
                ...$context,
                'slow_query_threshold_ms' => $this->slowQueryThreshold,
            ]);

            return;
        }

        $this->logger->debug('GraphQL query executed', $context);
    }
}
