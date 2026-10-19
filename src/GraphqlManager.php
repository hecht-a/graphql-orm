<?php

declare(strict_types=1);

namespace GraphqlOrm;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Client\ConcurrentGraphqlClientInterface;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\BatchLoadingDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\MutationDialect;
use GraphqlOrm\Event\PostFlushEvent;
use GraphqlOrm\Event\PreFlushEvent;
use GraphqlOrm\Event\QueryExecutedEvent;
use GraphqlOrm\Event\QueryFailedEvent;
use GraphqlOrm\Event\QueryStartedEvent;
use GraphqlOrm\Exception\FlushException;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\DtoMetadataFactory;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Persistence\Lifecycle;
use GraphqlOrm\Persistence\UnitOfWork;
use GraphqlOrm\Query\Ast\AstExporter;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\CompiledQuery;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Registry\ManagerRouter;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Depend on GraphqlManagerInterface to simulate or decorate it.
 */
#[AutoconfigureTag('graphql.manager')]
final class GraphqlManager implements GraphqlManagerInterface, ResetInterface
{
    /**
     * Identifiers loaded by one lazy loading query.
     */
    private const LAZY_LOADING_BATCH_SIZE = 100;

    /** Frames read to find the origin of a query: deep enough for a custom repository method calling the bundle. */
    private const ORIGIN_STACK_DEPTH = 40;

    private const LAZY_ORIGIN = 'lazy';

    /** The search of an autocomplete field, rather than the __invoke() of its controller. */
    private const AUTOCOMPLETE_ORIGIN = 'autocomplete';

    /** Frames read to find the serializer behind a lazy load: a normalizer reading a property is deep in the stack. */
    private const SERIALIZER_STACK_DEPTH = 80;

    private const SERIALIZER_NAMESPACE = 'Symfony\\Component\\Serializer\\';

    private const FIND_ORIGIN = 'find';

    /** The bytes of a response kept for the profiler. */
    private const RESPONSE_PREVIEW_BYTES = 2048;

    /** The name of the manager without graphql_orm.managers. */
    private const DEFAULT_NAME = 'default';

    private bool $loadingLazily = false;

    /** @var array<class-string, true> the entities checked to belong to this manager */
    private array $managedClasses = [];

    private ?GraphqlExecutionContext $referenceContext = null;

    /** The relation whose lazy load is running, e.g. Task::$assignee. */
    private ?string $lazyRelation = null;

    /** Whether the lazy load running is of a relation of an entity read from the second-level cache. */
    private bool $loadingFromCachedEntity = false;

    /** @var array<class-string, GraphqlEntityRepository<object>> */
    private array $genericRepositories = [];

    private ?UnitOfWork $unitOfWork = null;

    private ?DtoMetadataFactory $dtoMetadataFactory = null;

    /** @var array<class-string, array<string, list<\ReflectionMethod>>> the lifecycle callbacks of the entities, by step */
    private array $lifecycleCallbacks = [];

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
        private GraphqlCache $cache = new GraphqlCache(),
        private string $name = self::DEFAULT_NAME,
        private ?ManagerRouter $router = null,
        private TypeConverterRegistry $typeConverters = new TypeConverterRegistry(),
        // Neither writes nor tracks the entities read: no snapshot is taken for flush()
        private bool $readOnly = false,
    ) {
    }

    /**
     * Checks that an entity, and the entities of its relations, belong to the API of this manager: an API cannot select
     * the fields of another.
     *
     * @internal called by GraphqlEntityRepository
     *
     * @param class-string $class
     *
     * @throws LogicException when the entity, or the target of one of its relations, belongs to another manager
     */
    public function assertManages(string $class): void
    {
        $router = $this->router;

        if ($router === null || isset($this->managedClasses[$class])) {
            return;
        }

        $owner = $router->managerFor($class);

        if ($owner !== $this->name) {
            throw new LogicException(\sprintf('"%s" belongs to the GraphQL ORM manager "%s", not "%s": get its repository from GraphqlManagerRegistry::getRepository(), or autowire GraphqlManagerInterface $%3$sManager.', $class, $owner, $this->name));
        }

        // Marked before its relations, which may lead back to it; unmarked when one is refused, to refuse it every time.
        $this->managedClasses[$class] = true;

        try {
            foreach ($this->metadataFactory->getMetadata($class)->fields as $field) {
                if ($field->relation === null) {
                    continue;
                }

                $targetOwner = $router->managerFor($field->relation);

                if ($targetOwner !== $this->name) {
                    throw new LogicException(\sprintf('%s::$%s targets "%s" of the manager "%s": the API of the manager "%s" cannot select its fields. Map its identifier instead, and load it from its own manager.', $class, $field->property, $field->relation, $targetOwner, $this->name));
                }

                $this->assertManages($field->relation);
            }
        } catch (LogicException $exception) {
            unset($this->managedClasses[$class]);

            throw $exception;
        }
    }

    /**
     * The name of this manager, e.g. crm for graphql_orm.managers.crm.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * The repository of an entity: the service of the repositoryClass of its #[GraphqlOrm\Entity] when the application
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
        $entity = $this->identityMap?->find($class, $identifier);

        if ($entity !== null) {
            $this->collector?->addIdentityMapHit($class, $identifier, self::callSite());

            return $entity;
        }

        $rows = $this->cache->findEntityRows($class, [$identifier], $this->cacheTrigger());

        return $rows !== [] ? $this->hydrateCached($class, $rows)[0] : null;
    }

    /**
     * Loads the relations of an entity read from the second-level cache, its lazy loads marked as such in the profiler.
     *
     * @param class-string     $class
     * @param list<int|string> $identifiers
     *
     * @return list<object>
     */
    private function loadCachedEntityRelationLazily(string $class, array $identifiers, ?string $relation = null): array
    {
        $loadingFromCachedEntity = $this->loadingFromCachedEntity;
        $this->loadingFromCachedEntity = true;

        try {
            return $this->loadLazily($class, $identifiers, $relation);
        } finally {
            $this->loadingFromCachedEntity = $loadingFromCachedEntity;
        }
    }

    /**
     * What looks an entity up in the second-level cache, told to the profiler: find(), or the lazy load of a relation.
     */
    private function cacheTrigger(): string
    {
        if (!$this->loadingLazily) {
            return self::FIND_ORIGIN;
        }

        return $this->lazyRelation !== null ? 'lazy load of ' . $this->lazyRelation : 'lazy load';
    }

    /**
     * Hydrates rows of the second-level cache: their relations, reduced to their identifier, are lazy ghosts.
     *
     * @template T of object
     *
     * @param class-string<T>                         $class
     * @param array<int|string, array<string, mixed>> $rows
     *
     * @return list<T>
     */
    private function hydrateCached(string $class, array $rows): array
    {
        $context = new GraphqlExecutionContext(dialect: new DefaultDialect());
        $shared = $this->identityMap !== null && !$this->loadingLazily;

        if ($shared) {
            $context->sharedIdentityMap = $this->identityMap;
        }

        $context->lazyLoader = $this->loadCachedEntityRelationLazily(...);
        $context->observer = $this->collector;
        $context->unitOfWork = $this->readOnly ? null : $this->unitOfWork();
        $context->lazyLoading = true;
        $context->typeConverters = $this->typeConverters;
        $metadata = $this->metadataFactory->getMetadata($class);
        $entities = [];

        foreach ($rows as $row) {
            $entity = $this->hydrator->hydrate($metadata, $row, $context);
            \assert($entity instanceof $class);
            $entities[] = $entity;
        }

        if ($shared) {
            $this->identityMap->merge($context->identityMap, $context->completeEntities);
        }

        return $entities;
    }

    /**
     * The entity of this identifier, loaded on first access: see GraphqlEntityRepository::getReference().
     *
     * @internal
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @param string|null     $relation the property holding the reference, e.g. App\Entity\Invoice::$task, named in
     *                                  the profiler as the relation of its lazy load
     *
     * @return T
     */
    public function reference(string $class, int|string $identifier, ?string $relation = null): object
    {
        $known = $this->identityMap?->get($class, $identifier);

        if ($known instanceof $class) {
            return $known;
        }

        $context = $this->referenceContext();
        $entity = $this->hydrator->reference($this->metadataFactory->getMetadata($class), $identifier, $context, $relation);

        if ($this->identityMap !== null) {
            // The context is kept for the next references: they find this one in the shared identity map.
            $this->identityMap->merge($context->identityMap, []);
            $context->identityMap = [];
        }
        \assert($entity instanceof $class);

        return $entity;
    }

    /**
     * Forgets the entities of the shared identity map, as between two requests: for a long-running script.
     */
    public function clear(): void
    {
        $this->identityMap?->reset();
        $this->unitOfWork?->clear();
        $this->referenceContext = null;
    }

    /**
     * Forgets the references waiting to be loaded and the entities to write, between two requests or messages
     * (kernel.reset).
     */
    public function reset(): void
    {
        $this->unitOfWork?->clear();
        $this->referenceContext = null;
    }

    /**
     * Makes a new entity written by the next flush(), as Doctrine: created with the mutation of the dialect. An entity
     * read by a query needs no persist(), its changes being written by flush().
     *
     * @throws InvalidArgumentException when the object is no entity
     * @throws LogicException           when the manager is read-only
     */
    public function persist(object $entity): void
    {
        $this->assertWritable();
        $this->assertManages($entity::class);
        $this->unitOfWork()->persist($entity);
    }

    /**
     * Makes an entity deleted by the next flush(); a new entity is only forgotten.
     *
     * @throws InvalidArgumentException when the object is no entity, or one no query read
     * @throws LogicException           when the manager is read-only
     */
    public function remove(object $entity): void
    {
        $this->assertWritable();
        $this->assertManages($entity::class);
        $this->unitOfWork()->remove($entity);
    }

    /**
     * Writes the entities persisted, changed since a query read them, and removed: one mutation document, and one more
     * for an entity created whose identifier another needs. Only the fields changed are sent.
     *
     * @throws LogicException when the manager is read-only, the dialect cannot write, or an entity cannot be written
     * @throws FlushException when a mutation fails: those before were applied
     */
    public function flush(): void
    {
        $this->assertWritable();
        $dialect = $this->dialect;
        $this->eventDispatcher?->dispatch(new PreFlushEvent($this));

        if (!$dialect instanceof MutationDialect) {
            if ($this->unitOfWork?->hasWork() === true) {
                throw new LogicException(\sprintf('"%s" cannot write the entities: flush() needs a dialect implementing %s.', $dialect::class, MutationDialect::class));
            }
        } else {
            $this->unitOfWork()->flush($dialect, fn (QueryNode $mutation): array => $this->execute($mutation, static fn (array $response): array => $response));
        }

        $this->eventDispatcher?->dispatch(new PostFlushEvent($this));
    }

    /**
     * @throws LogicException when the manager is read-only
     */
    private function assertWritable(): void
    {
        if ($this->readOnly) {
            throw new LogicException(\sprintf('The GraphQL ORM manager "%s" is read-only (read_only: true): it does not write the entities.', $this->name));
        }
    }

    private function unitOfWork(): UnitOfWork
    {
        return $this->unitOfWork ??= new UnitOfWork($this->metadataFactory, $this->typeConverters, $this->lifecycle(...));
    }

    /**
     * A step of the lifecycle of an entity written: the identity map and the second-level cache follow it, then the
     * callbacks of the entity and the listeners are called.
     *
     * @param list<string> $changedProperties
     */
    private function lifecycle(Lifecycle $step, object $entity, int|string|null $identifier, array $changedProperties): void
    {
        if ($identifier !== null) {
            match ($step) {
                Lifecycle::PostPersist => $this->identityMap?->add($entity, $identifier),
                Lifecycle::PostRemove => $this->identityMap?->remove($entity, $identifier),
                default => null,
            };

            // The entity kept is no longer the one of the API.
            if (\in_array($step, [Lifecycle::PostUpdate, Lifecycle::PostRemove], true) && $this->cache->caches($entity::class)) {
                $this->cache->evictEntity($entity::class, $identifier);
            }
        }

        foreach ($this->lifecycleCallbacks[$entity::class][$step->name] ??= self::callbacks($entity::class, $step->attribute()) as $callback) {
            $callback->invoke($entity);
        }

        $this->eventDispatcher?->dispatch($step->event($entity, $this, $changedProperties));
    }

    /**
     * @param class-string $class
     * @param class-string $attribute
     *
     * @return list<\ReflectionMethod>
     */
    private static function callbacks(string $class, string $attribute): array
    {
        return array_values(array_filter(
            (new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $method): bool => $method->getAttributes($attribute) !== [],
        ));
    }

    /**
     * The context of the references: shared while the identity map is, so that the first reference accessed loads the
     * others of its class with the same query.
     */
    private function referenceContext(): GraphqlExecutionContext
    {
        if ($this->identityMap !== null && $this->referenceContext !== null) {
            return $this->referenceContext;
        }

        $context = new GraphqlExecutionContext(dialect: $this->dialect);
        $context->lazyLoader = $this->loadLazily(...);
        $context->sharedIdentityMap = $this->identityMap;
        $context->observer = $this->collector;
        $context->unitOfWork = $this->readOnly ? null : $this->unitOfWork();
        $context->typeConverters = $this->typeConverters;

        return $this->identityMap !== null ? $this->referenceContext = $context : $context;
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
            $context->sharedIdentityMap = $this->identityMap;
        }

        // Always set: a relation mapped with GraphqlOrm\Fetch::Lazy is a lazy ghost even without lazy_loading.
        $context->lazyLoader = $this->loadLazily(...);
        $context->observer = $this->collector;
        $context->unitOfWork = $this->readOnly ? null : $this->unitOfWork();
        $context->lazyLoading = $this->lazyLoading;
        $context->entityCache = $this->cache;
        $context->typeConverters = $this->typeConverters;

        if ($graphql instanceof QueryNode) {
            $compiledQuery = $this->compile($graphql, $options);
            $compiled = $compiledQuery->graphql;
            $variables = [...$variables, ...$compiledQuery->variables];
            // Read by the profiler and the listeners of the query events only.
            if ($this->collector !== null || $this->hasListeners(QueryStartedEvent::class, QueryExecutedEvent::class, QueryFailedEvent::class)) {
                $context->trace->ast = AstExporter::export($graphql);
            }
        } else {
            $compiled = $graphql;
        }

        $context->trace->graphql = $compiled;
        $context->trace->variables = $variables;
        $context->trace->with = $options->with;
        $context->trace->startedAt = microtime(true);

        if ($this->collector !== null) {
            $context->trace->manager = $this->name;
            $context->trace->slowQueryThreshold = $this->slowQueryThreshold;
            [$context->trace->origin, $context->trace->entityClass] = $this->origin();

            // Lets the profiler suggest one findBy() for the entities found one by one in a loop.
            if ($context->trace->origin === self::FIND_ORIGIN && $context->trace->entityClass !== null) {
                $context->trace->identifierProperty = $this->metadataFactory->getMetadata($context->trace->entityClass)->identifier?->property;
            }
        }

        if ($this->loadingLazily) {
            $context->trace->lazyRelation = $this->lazyRelation;
            $context->trace->fromCachedEntity = $this->loadingFromCachedEntity;
            $context->trace->duringSerialization = $this->collector !== null && self::whileSerializing();
        }

        if ($this->hasListeners(QueryStartedEvent::class)) {
            $this->eventDispatcher?->dispatch(new QueryStartedEvent($this->redact(clone $context->trace)));
        }

        try {
            $response = $options->resultCache
                ? $this->sendThroughResultCache($compiled, $context, $variables, $options)
                : $this->sendToClient($compiled, $context, $variables);
        } catch (\Throwable $exception) {
            $this->record($context, $exception);

            throw $exception;
        }

        return function () use ($response, $hydration, $context, $shared): array|PaginatedResult {
            $failure = null;

            try {
                $result = $response();

                if ($this->collector !== null) {
                    $context->trace->responsePreview = $this->preview($result);
                }

                $hydrationStartedAt = hrtime(true);
                $entities = $hydration($result, $context);
                $context->trace->hydrationDuration = (hrtime(true) - $hydrationStartedAt) / 1e6;
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
     * @param array<string, mixed> $variables
     *
     * @return \Closure(): array<string, mixed>
     */
    private function sendToClient(string $compiled, GraphqlExecutionContext $context, array $variables): \Closure
    {
        return $this->client instanceof ConcurrentGraphqlClientInterface
            ? $this->client->send($compiled, $context, $variables)
            : fn (): array => $this->client->query($compiled, $context, $variables);
    }

    /**
     * Answers from the result cache, or sends the query and keeps its response.
     *
     * @param array<string, mixed> $variables
     *
     * @return \Closure(): array<string, mixed>
     */
    private function sendThroughResultCache(string $compiled, GraphqlExecutionContext $context, array $variables, QueryOptions $options): \Closure
    {
        $item = $this->cache->resultItem($compiled, $variables, $options->resultCacheKey, $options->cursor);
        $context->trace->resultCacheKey = $options->resultCacheKey;
        $context->trace->resultCacheLifetime = $options->resultCacheLifetime;
        $cached = $item->isHit() ? $item->get() : null;

        if (\is_array($cached)) {
            $context->trace->resultCache = 'hit';
            // As old as the result: keeping its entities again would prolong them.
            $context->entityCache = null;

            /** @var array<string, mixed> $cached */
            return static fn (): array => $cached;
        }

        $context->trace->resultCache = 'miss';
        $response = $this->sendToClient($compiled, $context, $variables);

        return function () use ($response, $item, $options): array {
            $result = $response();
            $this->cache->saveResult($item, $result, $options->resultCacheLifetime);

            return $result;
        };
    }

    /**
     * The method of the bundle the application called to send the query, the outermost one in the call stack (e.g.
     * findBy, getResult, or a method of a custom repository), and the class of the entities it reads.
     *
     * @return array{string|null, class-string|null}
     */
    private function origin(): array
    {
        $origin = null;
        $entityClass = null;

        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, self::ORIGIN_STACK_DEPTH) as $frame) {
            $object = $frame['object'] ?? null;
            $class = $frame['class'] ?? null;

            $entityClass ??= match (true) {
                $object instanceof GraphqlEntityRepository => $object->getClassName(),
                $object instanceof GraphqlQuery => $object->getEntityClass(),
                default => null,
            };

            if ($class === null) {
                // A function, such as array_map(), between two methods of the bundle.
                continue;
            }

            if (!$object instanceof GraphqlEntityRepository && !self::isBundleClass($class)) {
                break;
            }

            $origin = $object instanceof EntityAutocompleteController ? self::AUTOCOMPLETE_ORIGIN : $frame['function'];
        }

        return [$this->loadingLazily ? self::LAZY_ORIGIN : $origin, $entityClass];
    }

    /**
     * Whether the serializer is on the way to the query, e.g. the ObjectNormalizer reading a relation not loaded yet.
     */
    private static function whileSerializing(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::SERIALIZER_STACK_DEPTH) as $frame) {
            if (str_starts_with($frame['class'] ?? '', self::SERIALIZER_NAMESPACE)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The file and line of the application calling the bundle, e.g. src/Controller/TaskController.php:51.
     */
    private static function callSite(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::ORIGIN_STACK_DEPTH) as $frame) {
            $file = $frame['file'] ?? null;

            if (\is_string($file) && !str_starts_with($file, __DIR__ . \DIRECTORY_SEPARATOR) && !str_contains($file, \DIRECTORY_SEPARATOR . 'vendor' . \DIRECTORY_SEPARATOR)) {
                return $file . (isset($frame['line']) ? ':' . $frame['line'] : '');
            }
        }

        return null;
    }

    /**
     * The beginning of a response as indented JSON, its values masked as those of the queries.
     *
     * @param array<string, mixed> $response
     */
    private function preview(array $response): string
    {
        $json = json_encode(
            $this->redactor?->redactValues($response) ?? $response,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        $json = \is_string($json) ? $json : '';

        return \strlen($json) > self::RESPONSE_PREVIEW_BYTES ? mb_strcut($json, 0, self::RESPONSE_PREVIEW_BYTES) . "\n…" : $json;
    }

    /**
     * Whether the class is declared by the bundle, rather than by the application or another package.
     */
    private static function isBundleClass(string $class): bool
    {
        if (!class_exists($class) && !interface_exists($class) && !trait_exists($class)) {
            return false;
        }

        $file = (new \ReflectionClass($class))->getFileName();

        return \is_string($file) && str_starts_with($file, __DIR__ . \DIRECTORY_SEPARATOR);
    }

    /**
     * Whether an event of these classes would reach a listener: a dispatcher other than Symfony's cannot tell, it is
     * then assumed to have some.
     */
    private function hasListeners(string ...$events): bool
    {
        if ($this->eventDispatcher === null) {
            return false;
        }

        if (!$this->eventDispatcher instanceof SymfonyEventDispatcherInterface) {
            return true;
        }

        foreach ($events as $event) {
            if ($this->eventDispatcher->hasListeners($event)) {
                return true;
            }
        }

        return false;
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
     * @param string|null      $relation    the relation accessed, e.g. Task::$assignee, recorded with the queries
     *
     * @return list<object> the entities found
     */
    private function loadLazily(string $class, array $identifiers, ?string $relation = null): array
    {
        // A ghost is loaded while it initializes: its query must not meet it again through the shared identity map.
        $loadingLazily = $this->loadingLazily;
        $lazyRelation = $this->lazyRelation;
        $this->loadingLazily = true;
        $this->lazyRelation = $relation;

        try {
            $repository = new GraphqlEntityRepository($this, $class);
            $identifier = $this->metadataFactory->getMetadata($class)->identifier;
            $cached = $this->cache->findEntityRows($class, $identifiers, $this->cacheTrigger());
            $entities = $cached !== [] ? $this->hydrateCached($class, $cached) : [];
            $identifiers = array_values(array_filter($identifiers, static fn (int|string $id) => !\array_key_exists($id, $cached)));

            if ($identifier === null || \count($identifiers) <= 1 || !$this->dialect instanceof BatchLoadingDialect) {
                return [...$entities, ...array_values(array_filter(array_map($repository->find(...), $identifiers)))];
            }

            foreach (array_chunk($identifiers, self::LAZY_LOADING_BATCH_SIZE) as $batch) {
                $qb = $repository->createQueryBuilder();
                $result = $qb->where($qb->expr()->in($identifier->mappedFrom, $batch))->limit(\count($batch))->getQuery()->getResult();
                $entities = [...$entities, ...array_values($result instanceof PaginatedResult ? $result->items : $result)];
            }

            return $entities;
        } finally {
            $this->loadingLazily = $loadingLazily;
            $this->lazyRelation = $lazyRelation;
        }
    }

    /**
     * The client of the API of this manager, as Doctrine EntityManager::getConnection().
     */
    public function getClient(): GraphqlClientInterface
    {
        return $this->client;
    }

    public function getDialect(): GraphqlQueryDialect
    {
        return $this->dialect;
    }

    /**
     * The caches holding data of the API between requests, to evict what changed, as Doctrine
     * EntityManager::getCache().
     */
    public function getCache(): GraphqlCache
    {
        return $this->cache;
    }

    public function getMetadataFactory(): GraphqlEntityMetadataFactory
    {
        return $this->metadataFactory;
    }

    /**
     * The mapping of the DTOs of hydrateAs(), read once for each DTO.
     *
     * @internal
     */
    public function getDtoMetadataFactory(): DtoMetadataFactory
    {
        return $this->dtoMetadataFactory ??= new DtoMetadataFactory($this->metadataFactory);
    }

    public function getHydrator(): EntityHydrator
    {
        return $this->hydrator;
    }

    public function getTypeConverters(): TypeConverterRegistry
    {
        return $this->typeConverters;
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
        // The types of the arguments of the mutations are not introspected: their values are inline.
        $rootField = \count($query->fields) === 1 && $query->operation === 'query' ? $query->fields[0]->name : null;
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
        $redacted->redacted = true;

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
