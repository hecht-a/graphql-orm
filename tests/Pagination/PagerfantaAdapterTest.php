<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Pagination;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Pagination\Pagerfanta\GraphqlOrmAdapter;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use Pagerfanta\Pagerfanta;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pages of a query builder for Pagerfanta: a count, then the rows of a page by offset.
 */
final class PagerfantaAdapterTest extends TestCase
{
    #[Test]
    public function itCountsTheEntitiesTheQueryBuilderFilters(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks_aggregate' => ['aggregate' => ['count' => 23]]]]);
        $queryBuilder = $this->queryBuilder($client);
        $queryBuilder->where($queryBuilder->expr()->eq('title', 'Task A'));

        self::assertSame(23, (new GraphqlOrmAdapter($queryBuilder))->getNbResults());
        self::assertSame('query { tasks_aggregate(where: { title: { _eq: "Task A" } }) { aggregate { count } } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itReadsTheRowsOfAPageByItsOffset(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 21, 'title' => 'Task U'], ['id' => 22, 'title' => 'Task V']]]]);
        $queryBuilder = $this->queryBuilder($client)->orderBy('id', Direction::Asc);

        $tasks = (new GraphqlOrmAdapter($queryBuilder))->getSlice(20, 10);

        self::assertSame([21, 22], array_map(static fn (FakeTask $task): int => $task->id, [...$tasks]));
        self::assertStringContainsString('tasks(order_by: { id: asc }, limit: 11, offset: 20)', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itPaginatesWithPagerfanta(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['tasks_aggregate' => ['aggregate' => ['count' => 23]]]],
            ['data' => ['tasks' => [['id' => 21, 'title' => 'Task U'], ['id' => 22, 'title' => 'Task V'], ['id' => 23, 'title' => 'Task W']]]],
        );

        $pager = (new Pagerfanta(new GraphqlOrmAdapter($this->queryBuilder($client))))->setMaxPerPage(10)->setCurrentPage(3);

        self::assertSame(3, $pager->getNbPages());
        self::assertCount(3, [...$pager->getCurrentPageResults()]);
        self::assertStringContainsString('limit: 11, offset: 20', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itLeavesTheQueryBuilderGivenAsItIs(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => []]], ['data' => ['tasks' => []]]);
        $queryBuilder = $this->queryBuilder($client);

        (new GraphqlOrmAdapter($queryBuilder))->getSlice(20, 10);
        $queryBuilder->getQuery()->getResult();

        self::assertSame('query { tasks { id title } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itRefusesADialectPaginatingWithCursors(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Dialect\DataApiBuilderDialect" paginates with cursors: a page by its number needs a dialect paginating with an offset (GraphqlOrm\Dialect\OffsetPaginationDialect) and counting the entities (GraphqlOrm\Dialect\CountingDialect). Use paginate() and next() instead.');

        new GraphqlOrmAdapter($this->queryBuilder(new FakeGraphqlClient(), new DataApiBuilderDialect()));
    }

    private static function flatten(string $graphql): string
    {
        return (string) preg_replace('/\s+/', ' ', $graphql);
    }

    /**
     * @return GraphqlQueryBuilder<FakeTask>
     */
    private function queryBuilder(FakeGraphqlClient $client, GraphqlQueryDialect $dialect = new HasuraDialect()): GraphqlQueryBuilder
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, $dialect);

        return (new GraphqlEntityRepository($manager, FakeTask::class))->createQueryBuilder();
    }
}
