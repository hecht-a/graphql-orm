<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityWithSwappedNames;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The PHP property names are accepted wherever a field is named, and sent as their GraphQL name.
 */
final class PropertyNamesTest extends TestCase
{
    private const NO_TASK = ['data' => ['tasks' => ['items' => []]]];

    #[Test]
    public function itSendsTheCriteriaAndOrderingNamedAfterProperties(): void
    {
        $client = new FakeGraphqlClient(self::NO_TASK);

        $this->tasks($client)->findBy(['dueDate' => null], ['dueDate' => Direction::DESC]);

        self::assertStringContainsString('filter: { due_date: { isNull: true } }', $client->lastQuery);
        self::assertStringContainsString('orderBy: { due_date: DESC }', $client->lastQuery);
    }

    #[Test]
    public function itStillSendsTheGraphqlNames(): void
    {
        $client = new FakeGraphqlClient(self::NO_TASK);

        $this->tasks($client)->findBy(['due_date' => null], ['title' => Direction::ASC]);

        self::assertStringContainsString('filter: { due_date: { isNull: true } }', $client->lastQuery);
        self::assertStringContainsString('orderBy: { title: ASC }', $client->lastQuery);
    }

    #[Test]
    public function itCountsWithCriteriaNamedAfterProperties(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['groupBy' => [['aggregations' => ['count' => 2]]]]]]);

        $this->tasks($client)->count(['dueDate' => null]);

        self::assertStringContainsString('filter: { due_date: { isNull: true } }', $client->lastQuery);
    }

    #[Test]
    public function itSendsTheQueryBuilderFiltersAndOrderingNamedAfterProperties(): void
    {
        $client = new FakeGraphqlClient(self::NO_TASK);
        $qb = $this->tasks($client)->createQueryBuilder();

        $qb->where($qb->expr()->orX($qb->expr()->isNull('dueDate'), $qb->expr()->andX($qb->expr()->eq('done', true), $qb->expr()->lt('dueDate', '2026-01-01'))))
            ->orderBy('dueDate', Direction::ASC)
            ->getQuery()
            ->getResult();

        self::assertStringContainsString(
            'filter: { or: [{ due_date: { isNull: true } }, { and: [{ done: { eq: true } }, { due_date: { lt: "2026-01-01" } }] }] }',
            $client->lastQuery,
        );
        self::assertStringContainsString('orderBy: { due_date: ASC }', $client->lastQuery);
    }

    #[Test]
    public function itSendsNoOrderingForAnEmptyOne(): void
    {
        $client = new FakeGraphqlClient(self::NO_TASK);

        $this->tasks($client)->findBy([], [], 1);

        self::assertStringNotContainsString('orderBy', $client->lastQuery);
    }

    #[Test]
    public function itPrefersTheGraphqlNameWhenAPropertyHasTheNameOfAnotherField(): void
    {
        $client = new FakeGraphqlClient(['data' => ['items' => ['items' => []]]]);
        $repository = new GraphqlEntityRepository($this->manager($client), FakeEntityWithSwappedNames::class);

        $repository->findBy(['name' => 'A']);

        self::assertStringContainsString('filter: { name: { eq: "A" } }', $client->lastQuery);
    }

    /**
     * @return GraphqlEntityRepository<Task>
     */
    private function tasks(FakeGraphqlClient $client): GraphqlEntityRepository
    {
        return new GraphqlEntityRepository($this->manager($client), Task::class);
    }

    private function manager(FakeGraphqlClient $client): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());
    }
}
