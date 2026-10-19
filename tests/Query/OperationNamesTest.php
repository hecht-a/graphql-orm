<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\Php84\Comment;
use GraphqlOrm\Tests\Fixtures\Php84\Note;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * With operation_names, each query is named after its entity and the method sending it, for the logs of the server.
 */
final class OperationNamesTest extends TestCase
{
    private const TASKS = ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'Task 1']]]]];

    #[Test]
    public function itNamesTheQueriesOfTheRepositoryAfterTheirMethod(): void
    {
        $client = new FakeGraphqlClient(self::TASKS, self::TASKS, self::TASKS, self::TASKS);
        $tasks = $this->manager($client)->getRepository(FakeTask::class);

        $tasks->findBy(['title' => 'Task 1']);
        $tasks->findOneBy(['title' => 'Task 1']);
        $tasks->findAll();
        $tasks->find(2);

        self::assertSame(['FakeTask_findBy', 'FakeTask_findOneBy', 'FakeTask_findAll', 'FakeTask_find'], array_map(self::operationName(...), $client->queries));
    }

    #[Test]
    public function itNamesTheQueriesOfTheQueryBuilder(): void
    {
        $client = new FakeGraphqlClient(self::TASKS);

        $this->manager($client)->getRepository(FakeTask::class)->createQueryBuilder()->getQuery()->getResult();

        self::assertSame('FakeTask_query', self::operationName($client->lastQuery));
    }

    #[Test]
    public function itNamesACountAfterItsMethod(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['groupBy' => [['aggregations' => ['count' => 3]]]]]]);

        $this->manager($client)->getRepository(FakeTask::class)->count();

        self::assertSame('FakeTask_count', self::operationName($client->lastQuery));
    }

    #[Test]
    public function itNamesTheLazyLoads(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['comments' => ['items' => [['id' => 5, 'note' => ['id' => 1]]]]]],
            ['data' => ['notes' => ['items' => [['id' => 1, 'title' => 'Note A', 'body' => 'Body A', 'code' => 'a']]]]],
        );
        $comment = $this->manager($client)->getRepository(Comment::class)->findAll()[0];

        self::assertSame('Note A', $comment->note?->title);
        self::assertSame(['Comment_findAll', 'Note_lazyLoad'], array_map(self::operationName(...), $client->queries));
    }

    #[Test]
    public function itNamesTheMutationsOfFlush(): void
    {
        $client = new FakeGraphqlClient(['data' => ['m0' => ['id' => 7]]]);
        $manager = $this->manager($client);
        $note = new Note();
        $note->rename('Note A');
        $note->body = 'Body A';
        $note->code = 'a';

        $manager->persist($note);
        $manager->flush();

        self::assertStringStartsWith('mutation flush {', $client->lastQuery);
    }

    #[Test]
    public function itGivesAQueryTheNameItIsGivenEvenWithoutOperationNames(): void
    {
        $client = new FakeGraphqlClient(self::TASKS);

        $this->manager($client, operationNames: false)->getRepository(FakeTask::class)->createQueryBuilder()->name('dashboard_tasks')->getQuery()->getResult();

        self::assertSame('dashboard_tasks', self::operationName($client->lastQuery));
    }

    #[Test]
    public function itRefusesANameThatIsNoGraphqlName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"dashboard tasks" is not a valid GraphQL name');

        $this->manager(new FakeGraphqlClient())->getRepository(FakeTask::class)->createQueryBuilder()->name('dashboard tasks');
    }

    #[Test]
    public function itSendsAnonymousQueriesWithoutOperationNames(): void
    {
        $client = new FakeGraphqlClient(self::TASKS);

        $this->manager($client, operationNames: false)->getRepository(FakeTask::class)->findAll();

        self::assertStringStartsWith('query {', $client->lastQuery);
    }

    private static function operationName(string $query): ?string
    {
        return preg_match('/^(?:query|mutation) ([_A-Za-z][_0-9A-Za-z]*)/', $query, $match) === 1 ? $match[1] : null;
    }

    private function manager(FakeGraphqlClient $client, bool $operationNames = true): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect(), operationNames: $operationNames);
    }
}
