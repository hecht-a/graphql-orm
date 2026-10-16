<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Integration;

use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\PostGraphileDialect;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\PostGraphile\Entity\RelayTask;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Two real GraphQL APIs in one container, a manager each: Data API Builder and PostGraphile, their entities in
 * namespaces of their own.
 */
#[Group('integration')]
final class MultipleApisTest extends TestCase
{
    private GraphqlManagerRegistry $registry;

    protected function setUp(): void
    {
        $dab = getenv('GRAPHQL_ORM_DAB_ENDPOINT');
        $postGraphile = getenv('GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT');

        if (!\is_string($dab) || $dab === '' || !\is_string($postGraphile) || $postGraphile === '') {
            self::markTestSkipped('Set GRAPHQL_ORM_DAB_ENDPOINT and GRAPHQL_ORM_POSTGRAPHILE_ENDPOINT to run these integration tests.');
        }

        $container = new ContainerBuilder();
        $container->setParameter('kernel.debug', false);
        $container->setParameter('kernel.project_dir', \dirname(__DIR__, 2));
        $container->setParameter('kernel.environment', 'test');
        $container->register(HttpClientInterface::class)->setFactory([HttpClient::class, 'create']);
        $container->register('cache.app', ArrayAdapter::class);
        $container->register('cache.system', ArrayAdapter::class);

        (new GraphqlOrmExtension())->load([[
            'max_depth' => 2,
            'managers' => [
                'dab' => [
                    'endpoint' => $dab,
                    'dialect' => DataApiBuilderDialect::class,
                    'mapping' => ['entity' => ['dir' => __DIR__ . '/Entity', 'namespace' => 'GraphqlOrm\\Tests\\Integration\\Entity']],
                ],
                'pg' => [
                    'endpoint' => $postGraphile,
                    'dialect' => PostGraphileDialect::class,
                    'mapping' => ['entity' => ['dir' => __DIR__ . '/PostGraphile/Entity', 'namespace' => 'GraphqlOrm\\Tests\\Integration\\PostGraphile\\Entity']],
                ],
            ],
        ]], $container);
        $container->getDefinition(GraphqlManagerRegistry::class)->setPublic(true);
        $container->compile();

        $registry = $container->get(GraphqlManagerRegistry::class);
        self::assertInstanceOf(GraphqlManagerRegistry::class, $registry);
        $this->registry = $registry;
    }

    #[Test]
    public function itReadsEachEntityFromItsApi(): void
    {
        $task = $this->registry->getRepository(Task::class)->find(1);
        $relayTask = $this->registry->getRepository(RelayTask::class)->find(3);

        self::assertSame(['Write the docs', 'User A'], [$task?->title, $task?->user?->name]);
        self::assertSame(['Release the bundle', 'User B'], [$relayTask?->title, $relayTask?->user?->name]);
        self::assertSame(['dab', 'pg'], [$this->registry->getManagerForClass(Task::class)->getName(), $this->registry->getManagerForClass(RelayTask::class)->getName()]);
    }

    #[Test]
    public function itCountsWithTheDialectOfEachApi(): void
    {
        self::assertSame(4, $this->registry->getRepository(Task::class)->count());
        self::assertSame(1, $this->registry->getRepository(RelayTask::class)->count(['done' => true]));
    }
}
