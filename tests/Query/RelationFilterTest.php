<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A field path (`user.name`) filters through the relations, segment by segment.
 */
final class RelationFilterTest extends TestCase
{
    #[Test]
    public function itNestsTheFilterOfARelation(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => []]]]);
        $qb = $this->repository($client, Task::class)->createQueryBuilder();

        $qb->where($qb->expr()->andX($qb->expr()->eq('user.name', 'User A'), $qb->expr()->eq('done', false)))->getQuery()->getResult();

        self::assertStringContainsString('filter: { and: [{ user: { name: { eq: "User A" } } }, { done: { eq: false } }] }', $client->lastQuery);
    }

    #[Test]
    public function itNamesEachSegmentAfterItsGraphqlName(): void
    {
        $client = new FakeGraphqlClient(['data' => ['users' => ['items' => []]]]);
        $qb = $this->repository($client, User::class)->createQueryBuilder();

        $qb->where($qb->expr()->isNull('tasks.dueDate'))->getQuery()->getResult();

        self::assertStringContainsString('filter: { tasks: { due_date: { isNull: true } } }', $client->lastQuery);
    }

    #[Test]
    public function itNestsAnUnmappedPathAsWritten(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => []]]]);
        $qb = $this->repository($client, Task::class)->createQueryBuilder();

        $qb->where($qb->expr()->eq('owner.name', 'A'))->getQuery()->getResult();

        self::assertStringContainsString('filter: { owner: { name: { eq: "A" } } }', $client->lastQuery);
    }

    /**
     * @param class-string $entityClass
     *
     * @return GraphqlEntityRepository<object>
     */
    private function repository(FakeGraphqlClient $client, string $entityClass): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
