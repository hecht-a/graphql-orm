<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Command\FindCommand;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Test\MockGraphqlServer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FindCommandTest extends TestCase
{
    private const TASKS = ['tasks' => ['items' => [
        ['id' => 1, 'title' => 'Write the docs', 'done' => false, 'due_date' => '2026-01-15T10:00:00+00:00', 'user' => ['id' => 7, 'name' => 'User A', 'tasks' => ['items' => [['id' => 1], ['id' => 2]]]]],
        ['id' => 3, 'title' => 'Release', 'done' => false, 'due_date' => null, 'user' => null],
    ]]];

    #[Test]
    public function itListsTheEntitiesMatchingTheCriteria(): void
    {
        $server = (new MockGraphqlServer())->respond(self::TASKS);
        $tester = $this->tester($server);

        $status = $tester->execute(['entity' => 'Task', '--criteria' => ['done=false', 'id=[1,3]', 'title=Write the docs'], '--order-by' => ['dueDate=desc'], '--limit' => '5']);

        self::assertSame(Command::SUCCESS, $status);
        $query = (string) preg_replace('/\s+/', ' ', $server->requests()[0]->query);
        self::assertStringContainsString('done: { eq: false }', $query);
        self::assertStringContainsString('id: { in: [1, 3] }', $query);
        self::assertStringContainsString('title: { eq: "Write the docs" }', $query);
        self::assertStringContainsString('orderBy: { due_date: DESC }', $query);
        self::assertStringContainsString('first: 5', $query);

        $display = $tester->getDisplay();
        foreach (['id', 'title', 'done', 'dueDate', 'user', 'Write the docs', 'false', '2026-01-15T10:00:00+00:00', '#7', 'null', '2 tasks'] as $expected) {
            self::assertStringContainsString($expected, $display);
        }
    }

    #[Test]
    public function itPrintsTheEntitiesAsJson(): void
    {
        $tester = $this->tester((new MockGraphqlServer())->respond(self::TASKS));

        $tester->execute(['entity' => 'Task', '--format' => 'json']);

        $rows = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        self::assertSame(
            ['id' => 1, 'title' => 'Write the docs', 'done' => false, 'dueDate' => '2026-01-15T10:00:00+00:00', 'user' => 7],
            $rows[0],
        );
    }

    #[Test]
    public function itFailsForAnUnknownEntity(): void
    {
        $tester = $this->tester(new MockGraphqlServer());

        self::assertSame(Command::FAILURE, $tester->execute(['entity' => 'Taks']));
        self::assertStringContainsString('Taks', $tester->getDisplay());
    }

    #[Test]
    public function itFailsForACriterionWithoutValue(): void
    {
        $tester = $this->tester(new MockGraphqlServer());

        self::assertSame(Command::FAILURE, $tester->execute(['entity' => 'Task', '--criteria' => ['done']]));
        self::assertStringContainsString('field=value', $tester->getDisplay());
    }

    #[Test]
    public function itFailsWithTheErrorsOfTheServer(): void
    {
        $tester = $this->tester((new MockGraphqlServer())->respondWithErrors([['message' => 'The specified input object field `titel` does not exist.']]));

        self::assertSame(Command::FAILURE, $tester->execute(['entity' => 'Task', '--criteria' => ['titel=A']]));
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('does not exist', $display);
        self::assertStringContainsString('did you mean "title"?', $display);
    }

    #[Test]
    public function itShowsTheQueryWithoutSendingIt(): void
    {
        $server = new MockGraphqlServer();
        $tester = $this->tester($server);

        $status = $tester->execute(['entity' => 'Task', '--criteria' => ['done=false'], '--limit' => '5', '--dry-run' => true]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $status, $display);
        self::assertSame([], $server->requests());
        self::assertMatchesRegularExpression('/Manager\s+default/', $display);
        self::assertMatchesRegularExpression('/Dialect\s+GraphqlOrm\\\\Dialect\\\\DataApiBuilderDialect/', $display);
        self::assertStringContainsString('done: { eq: false }', (string) preg_replace('/\s+/', ' ', $display));
        self::assertStringContainsString('first: 5', $display);
    }

    #[Test]
    public function itShowsTheQueryAsJsonWithoutSendingIt(): void
    {
        $server = new MockGraphqlServer();
        $tester = $this->tester($server);

        $tester->execute(['entity' => 'Task', '--criteria' => ['id=3'], '--dry-run' => true, '--format' => 'json']);

        $shown = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($shown);
        self::assertSame(['manager', 'dialect', 'graphql', 'variables'], array_keys($shown));
        self::assertSame('default', $shown['manager']);
        self::assertSame(DataApiBuilderDialect::class, $shown['dialect']);
        self::assertIsString($shown['graphql']);
        self::assertStringContainsString('id: { eq: 3 }', (string) preg_replace('/\s+/', ' ', $shown['graphql']));
        self::assertSame([], $shown['variables']);
        self::assertSame([], $server->requests());
    }

    private function tester(MockGraphqlServer $server): CommandTester
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            new GraphqlClient($server, 'https://graphql.example.com/graphql'),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
        );
        $command = new FindCommand(GraphqlManagerRegistry::forManager($manager), new EntityClassLocator(\dirname(__DIR__) . '/Integration/Entity', 'GraphqlOrm\\Tests\\Integration\\Entity'));

        return new CommandTester((new Command('graphqlorm:find'))->setCode($command));
    }
}
