<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Command\QueryCommand;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Registry\ManagerRouter;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class QueryCommandTest extends TestCase
{
    private const QUERY = 'query ($id: Int) { tasks(filter: { id: { eq: $id } }) { items { id title } } }';
    private const TASKS = ['tasks' => ['items' => [['id' => 1, 'title' => 'Task A']]]];

    #[Test]
    public function itSendsTheQueryAndPrintsTheResponseAsJson(): void
    {
        $server = (new MockGraphqlServer())->respond(self::TASKS);
        $tester = $this->tester($this->registry($server));

        $status = $tester->execute(['query' => self::QUERY]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(self::QUERY, $server->requests()[0]->query);
        self::assertSame(['data' => self::TASKS], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itSendsTheVariables(): void
    {
        $server = (new MockGraphqlServer())->respond(self::TASKS);

        $this->tester($this->registry($server))->execute(['query' => self::QUERY, '--variables' => '{"id": 1}']);

        self::assertSame(['id' => 1], $server->requests()[0]->variables);
    }

    #[Test]
    public function itFailsForVariablesThatAreNotAJsonObject(): void
    {
        $server = new MockGraphqlServer();
        $tester = $this->tester($this->registry($server));

        self::assertSame(Command::FAILURE, $tester->execute(['query' => self::QUERY, '--variables' => '[1]']));
        self::assertStringContainsString('JSON object', $tester->getDisplay());
        self::assertSame([], $server->requests());
    }

    #[Test]
    public function itReadsTheQueryFromTheStandardInput(): void
    {
        $server = (new MockGraphqlServer())->respond(self::TASKS);
        $tester = $this->tester($this->registry($server));
        $tester->setInputs([self::QUERY]);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame(self::QUERY, trim($server->requests()[0]->query));
    }

    #[Test]
    public function itFailsWithoutQuery(): void
    {
        $tester = $this->tester($this->registry(new MockGraphqlServer()));
        $tester->setInputs(['']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No GraphQL query', $tester->getDisplay());
    }

    #[Test]
    public function itQueriesTheApiOfTheManagerNamed(): void
    {
        $main = new MockGraphqlServer();
        $crm = (new MockGraphqlServer())->respond(['contacts' => ['items' => []]]);
        $router = new ManagerRouter(['main' => '', 'crm' => ''], 'main');
        $registry = new GraphqlManagerRegistry(
            new ServiceLocator(['main' => fn () => $this->manager($main, 'main', $router), 'crm' => fn () => $this->manager($crm, 'crm', $router)]),
            $router,
        );

        $status = $this->tester($registry)->execute(['query' => '{ contacts { items { id } } }', '--manager' => 'crm']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertCount(1, $crm->requests());
        self::assertSame([], $main->requests());
    }

    #[Test]
    public function itFailsForAnUnknownManager(): void
    {
        $tester = $this->tester($this->registry(new MockGraphqlServer()));

        self::assertSame(Command::FAILURE, $tester->execute(['query' => self::QUERY, '--manager' => 'crm']));
        self::assertStringContainsString('"crm" does not exist', $tester->getDisplay());
    }

    #[Test]
    public function itPrintsThePartialDataWithTheErrorsAndFails(): void
    {
        $server = (new MockGraphqlServer())->respondWithErrors([['message' => 'Forbidden field']], self::TASKS);
        $tester = $this->tester($this->registry($server));

        self::assertSame(Command::FAILURE, $tester->execute(['query' => self::QUERY]));
        self::assertSame(
            ['data' => self::TASKS, 'errors' => [['message' => 'Forbidden field']]],
            json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function itFailsWithTheErrorsOfTheServer(): void
    {
        $server = (new MockGraphqlServer())->respondWithErrors([['message' => 'The field `titel` does not exist.']]);
        $tester = $this->tester($this->registry($server));

        self::assertSame(Command::FAILURE, $tester->execute(['query' => self::QUERY]));
        self::assertStringContainsString('does not exist', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[Test]
    public function itMasksTheValuesInTheLogsButNotInItsOutput(): void
    {
        $logger = new FakeLogger();
        $server = (new MockGraphqlServer())->respond(self::TASKS);
        $tester = $this->tester($this->registry($server, $logger));

        $tester->execute(['query' => '{ tasks(filter: { title: { eq: "Task A" } }) { items { id title } } }']);

        $logged = $logger->records[0]['context']['query'];
        self::assertIsString($logged);
        self::assertStringContainsString('Task A', $tester->getDisplay());
        self::assertStringContainsString('"***"', $logged);
        self::assertStringNotContainsString('Task A', $logged);
    }

    private function tester(GraphqlManagerRegistry $registry): CommandTester
    {
        return new CommandTester((new Command('graphqlorm:query'))->setCode(new QueryCommand($registry)));
    }

    private function registry(MockGraphqlServer $server, ?FakeLogger $logger = null): GraphqlManagerRegistry
    {
        return GraphqlManagerRegistry::forManager($this->manager($server, logger: $logger));
    }

    private function manager(MockGraphqlServer $server, string $name = 'default', ?ManagerRouter $router = null, ?FakeLogger $logger = null): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager(
            $metadataFactory,
            new GraphqlClient($server, 'https://graphql.example.com/graphql'),
            new EntityHydrator($metadataFactory),
            null,
            1,
            new DataApiBuilderDialect(),
            logger: $logger,
            redactor: new QueryRedactor(),
            name: $name,
            router: $router,
        );
    }
}
