<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\PartialEntityException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\With\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * with() names the relations a query loads, in place of max_depth: the others are selected by their identifier only.
 */
final class WithRelationsTest extends TestCase
{
    #[Test]
    public function itOnlyLoadsTheRelationsNamedByWith(): void
    {
        $graphql = $this->repository()->createQueryBuilder()
            ->with('assignee')
            ->getQuery()
            ->getGraphQL();

        self::assertSame(<<<'GRAPHQL'
            query {
              tasks {
                id
                title
                assignee {
                  id
                  name
                  company {
                    id
                  }
                }
                tag {
                  id
                }
                reviewer {
                  id
                }
              }
            }
            GRAPHQL, $graphql);
    }

    #[Test]
    public function itLoadsNestedRelationsBeyondTheMaxDepth(): void
    {
        $graphql = $this->repository(maxDepth: 1)->createQueryBuilder()
            ->with('assignee.company', 'label')
            ->getQuery()
            ->getGraphQL();

        self::assertSame(<<<'GRAPHQL'
            query {
              tasks {
                id
                title
                assignee {
                  id
                  name
                  company {
                    id
                    name
                  }
                }
                tag {
                  id
                  name
                }
                reviewer {
                  id
                }
              }
            }
            GRAPHQL, $graphql);
    }

    #[Test]
    public function itAddsTheRelationsOfEveryCall(): void
    {
        $graphql = $this->repository()->createQueryBuilder()
            ->with('assignee')
            ->with('label')
            ->getQuery()
            ->getGraphQL();

        self::assertStringContainsString('tag { id name }', self::flatten($graphql));
        self::assertStringContainsString('assignee { id name company { id } }', self::flatten($graphql));
    }

    #[Test]
    public function itLoadsALazyRelationNamedByWith(): void
    {
        $graphql = $this->repository()->createQueryBuilder()
            ->with('reviewer')
            ->getQuery()
            ->getGraphQL();

        self::assertStringContainsString('reviewer { id name company { id } }', self::flatten($graphql));
        self::assertStringContainsString('assignee { id }', self::flatten($graphql));
    }

    #[Test]
    public function itNamesARelationByItsGraphqlNameOrItsProperty(): void
    {
        $graphql = $this->repository()->createQueryBuilder()
            ->with('tag')
            ->getQuery()
            ->getGraphQL();

        self::assertStringContainsString('tag { id name }', self::flatten($graphql));
    }

    #[Test]
    public function itAddsTheRelationsOfWithToASelection(): void
    {
        $graphql = $this->repository()->createQueryBuilder()
            ->select('title')
            ->with('label')
            ->getQuery()
            ->getGraphQL();

        self::assertSame('query { tasks { id title tag { id name } } }', self::flatten($graphql));
    }

    #[Test]
    public function itMergesASelectedRelationWithTheSameRelationOfWith(): void
    {
        $graphql = $this->repository()->createQueryBuilder()
            ->select('assignee.company.name')
            ->with('assignee')
            ->getQuery()
            ->getGraphQL();

        self::assertSame('query { tasks { id assignee { company { name id } id name } } }', self::flatten($graphql));
    }

    #[Test]
    public function itReducesTheRelationsOfASelectedRelationNotNamedByWith(): void
    {
        $graphql = $this->repository(maxDepth: 2)->createQueryBuilder()
            ->select('assignee')
            ->with('label')
            ->getQuery()
            ->getGraphQL();

        self::assertSame('query { tasks { id assignee { id name company { id } } tag { id name } } }', self::flatten($graphql));
    }

    #[Test]
    public function itHydratesTheRelationsNamedByWithAndLeavesTheOthersPartial(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [[
            'id' => 1,
            'title' => 'Task A',
            'assignee' => ['id' => 7, 'name' => 'User A', 'company' => ['id' => 3, 'name' => 'Company A']],
            'tag' => ['id' => 5],
            'reviewer' => ['id' => 8, 'name' => 'User B', 'company' => null],
        ]]]]);

        $task = $this->repository(client: $client)->createQueryBuilder()
            ->with('assignee.company', 'reviewer')
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(Task::class, $task);

        self::assertSame('Company A', $task->assignee?->company?->name);
        self::assertSame('User B', $task->reviewer?->name);
        self::assertSame(5, $task->label?->id);

        $this->expectException(PartialEntityException::class);

        self::assertSame('', $task->label->name);
    }

    #[Test]
    public function itRejectsAFieldThatIsNotARelation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"title" is not a relation of "GraphqlOrm\Tests\Fixtures\With\Task".');

        $this->repository()->createQueryBuilder()->with('title');
    }

    #[Test]
    public function itRejectsAnUnknownRelationOfANestedEntity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"compagny" is not a field of "GraphqlOrm\Tests\Fixtures\With\User": did you mean "company"?');

        $this->repository()->createQueryBuilder()->with('assignee.compagny');
    }

    #[Test]
    public function itLoadsTheRelationsNamedByWithInFindBy(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => []]]);

        $this->repository(client: $client)->with('assignee.company')->findBy(['title' => 'Task A']);

        $query = self::flatten($client->lastQuery);
        self::assertStringContainsString('assignee { id name company { id name } }', $query);
        self::assertStringContainsString('tag { id }', $query);
        self::assertStringContainsString('reviewer { id }', $query);
    }

    #[Test]
    public function itLoadsTheRelationsNamedByWithInFindOneBy(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => []]]);

        $this->repository(client: $client)->with('reviewer')->findOneBy(['title' => 'Task A']);

        self::assertStringContainsString('reviewer { id name company { id } }', self::flatten($client->lastQuery));
    }

    #[Test]
    public function itLoadsTheRelationsNamedByWithInFind(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => [[
            'id' => 1, 'title' => 'Task A', 'assignee' => null, 'tag' => ['id' => 5, 'name' => 'Label A'], 'reviewer' => null,
        ]]]]);

        $task = $this->repository(client: $client)->with('label')->find(1);

        self::assertStringContainsString('tag { id name }', self::flatten($client->lastQuery));
        self::assertSame('Label A', $task?->label?->name);
    }

    #[Test]
    public function itRejectsAFieldThatIsNotARelationInFindBy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"title" is not a relation of "GraphqlOrm\Tests\Fixtures\With\Task".');

        $this->repository()->with('title');
    }

    #[Test]
    public function itLeavesTheRepositoryAsItIs(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => []]], ['data' => ['tasks' => []]]);
        $repository = $this->repository(client: $client);

        $repository->with('label', 'assignee.company')->findAll();
        $repository->findAll();

        self::assertStringContainsString('assignee { id name company { id name } }', self::flatten($client->queries[0]));
        self::assertStringContainsString('assignee { id name company { id } }', self::flatten($client->queries[1]));
    }

    private static function flatten(string $graphql): string
    {
        return (string) preg_replace('/\s+/', ' ', $graphql);
    }

    /**
     * @return GraphqlEntityRepository<Task>
     */
    private function repository(int $maxDepth = 1, FakeGraphqlClient $client = new FakeGraphqlClient()): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            new GraphqlOrmDataCollector(),
            $maxDepth,
        );

        return new GraphqlEntityRepository($manager, Task::class);
    }
}
