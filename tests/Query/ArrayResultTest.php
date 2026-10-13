<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ArrayResultTest extends TestCase
{
    #[Test]
    public function itReturnsTheRowsAsTheApiSentThem(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => '1', 'title' => 'A'], ['id' => '2', 'title' => 'B']]]]);
        $query = $this->repository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $rows = $query->getArrayResult();

        self::assertSame([['id' => '1', 'title' => 'A'], ['id' => '2', 'title' => 'B']], $rows);
    }

    #[Test]
    public function itRemovesTheCollectionEnvelopesOfTheDialect(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => ['items' => [
            ['id' => 1, 'tasks' => ['items' => [['id' => 10, 'title' => 'A']]]],
        ]]]]);
        $query = $this->repository($client, new DataApiBuilderDialect(), FakeUserWithCollection::class)->createQueryBuilder()->getQuery();

        $rows = $query->getArrayResult();

        self::assertSame([['id' => 1, 'tasks' => [['id' => 10, 'title' => 'A']]]], $rows);
    }

    #[Test]
    public function itWrapsASingleObjectRootInAList(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['id' => '1', 'title' => 'A']]]);
        $query = $this->repository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $rows = $query->getArrayResult();

        self::assertSame([['id' => '1', 'title' => 'A']], $rows);
    }

    #[Test]
    public function itReturnsNoRowWhenTheRootIsNull(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => null]]);
        $query = $this->repository($client, new DefaultDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $rows = $query->getArrayResult();

        self::assertSame([], $rows);
    }

    #[Test]
    public function itRejectsARowThatIsNotAnObject(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => ['not an object']]]]);
        $query = $this->repository($client, new DataApiBuilderDialect(), FakeTask::class)->createQueryBuilder()->getQuery();

        $this->expectException(InvalidGraphqlResponseException::class);
        $query->getArrayResult();
    }

    #[Test]
    public function itRecordsTheQueryInTheProfiler(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => '1', 'title' => 'A']]]]);
        $query = $this->repository($client, new DefaultDialect(), FakeTask::class, $collector)->createQueryBuilder()->getQuery();

        $query->getArrayResult();

        self::assertSame(1, $collector->getQueryCount());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, GraphqlQueryDialect $dialect, string $entityClass, ?GraphqlOrmDataCollector $collector = null): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), $collector, 2, $dialect);

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
