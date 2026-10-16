<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration;

use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\NamingStrategy;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Runs the bundle against a real GraphQL server of tests/Integration/compose.yaml, whose endpoint is given by an
 * environment variable: the tests are skipped when it is not set. Concrete classes belong to the `integration` group,
 * excluded from the default run (PHPUnit does not inherit the group attribute).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected GraphqlClient $client;

    abstract protected static function endpointVariable(): string;

    abstract protected function dialect(): GraphqlQueryDialect;

    protected function setUp(): void
    {
        $endpoint = getenv(static::endpointVariable());

        if (!\is_string($endpoint) || $endpoint === '') {
            self::markTestSkipped(\sprintf('Set %s to run these integration tests.', static::endpointVariable()));
        }

        $this->client = new GraphqlClient(HttpClient::create(), $endpoint);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    protected function repository(
        string $entityClass,
        ?RootArgumentTypeResolver $argumentTypeResolver = null,
        int $maxDepth = 2,
        bool $lazyLoading = false,
        ?GraphqlOrmDataCollector $collector = null,
        NamingStrategy $namingStrategy = new DefaultNamingStrategy(),
        ?IdentityMap $identityMap = null,
        GraphqlCache $cache = new GraphqlCache(),
    ): GraphqlEntityRepository {
        $metadataFactory = new GraphqlEntityMetadataFactory(namingStrategy: $namingStrategy);
        $manager = new GraphqlManager(
            $metadataFactory,
            $this->client,
            new EntityHydrator($metadataFactory),
            $collector,
            $maxDepth,
            $this->dialect(),
            argumentTypeResolver: $argumentTypeResolver,
            lazyLoading: $lazyLoading,
            identityMap: $identityMap,
            cache: $cache,
        );

        return new GraphqlEntityRepository($manager, $entityClass);
    }

    /**
     * A manager of the API under test, to share between repositories as the bundle does.
     */
    protected function manager(?GraphqlOrmDataCollector $collector = null): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $this->client, new EntityHydrator($metadataFactory), $collector, 2, $this->dialect());
    }

    protected function schema(): GraphqlSchema
    {
        $introspection = (new SchemaIntrospector($this->client))->introspectSchema();

        return new GraphqlSchema($introspection['types'], $introspection['queryType']);
    }

    /**
     * @param iterable<object> $entities
     *
     * @return list<mixed>
     */
    protected static function ids(iterable $entities): array
    {
        $ids = [];

        foreach ($entities as $entity) {
            $ids[] = (new \ReflectionProperty($entity, 'id'))->getValue($entity);
        }

        return $ids;
    }
}
