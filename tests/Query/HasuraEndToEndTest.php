<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\HasuraDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HasuraEndToEndTest extends TestCase
{
    #[Test]
    public function itSendsAHasuraQueryAndHydratesThePlainList(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']]]]);

        $tasks = $this->repository($client, FakeTask::class)->findBy(['title' => 'A'], ['id' => Direction::DESC], 2);

        self::assertStringContainsString('tasks(where: { title: { _eq: "A" } }, order_by: { id: desc }, limit: 2) {', $client->lastQuery);
        self::assertSame(['A', 'B'], array_map(fn (FakeTask $task) => $task->title, $tasks));
    }

    #[Test]
    public function itSelectsNestedCollectionsWithoutEnvelope(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => [['id' => 1, 'tasks' => [['id' => 10, 'title' => 'A']]]]]]);

        $users = $this->repository($client, FakeUserWithCollection::class)->findAll();

        self::assertSame(10, $users[0]->tasks[0]->id);
        self::assertStringContainsString("tasks {\n      id\n      title\n    }", $client->lastQuery);
        self::assertStringNotContainsString('items', $client->lastQuery);
    }

    #[Test]
    public function itFiltersWithTheQueryBuilder(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => []]]);
        $qb = $this->repository($client, FakeTask::class)->createQueryBuilder();

        $qb->where($qb->expr()->orX($qb->expr()->contains('title', 'docs'), $qb->expr()->eq('id', 3)))->getQuery()->getResult();

        self::assertStringContainsString('tasks(where: { _or: [{ title: { _like: "%docs%" } }, { id: { _eq: 3 } }] }) {', $client->lastQuery);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, string $entityClass): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new HasuraDialect());

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
