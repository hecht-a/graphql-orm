<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class VariablesEndToEndTest extends TestCase
{
    private const EMAIL = 'user@example.com';

    private const INTROSPECTION = ['data' => ['__schema' => ['queryType' => ['fields' => [
        ['name' => 'tasks', 'args' => [['name' => 'email', 'type' => ['kind' => 'SCALAR', 'name' => 'String', 'ofType' => null]]]],
    ]]]]];

    private const TASKS = ['data' => ['tasks' => [['id' => 1, 'title' => 'A']]]];

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function itSendsTypedArgumentsAsVariables(): void
    {
        $client = new FakeGraphqlClient(self::INTROSPECTION, self::TASKS);

        $tasks = $this->createRepository($client, withVariables: true)->findBy(['email' => self::EMAIL]);

        self::assertStringStartsWith('query($email: String) {', $client->lastQuery);
        self::assertStringContainsString('tasks(email: $email)', $client->lastQuery);
        self::assertSame(['email' => self::EMAIL], $client->lastVariables);
        self::assertCount(1, $tasks);
    }

    #[Test]
    public function itFallsBackToInlineValuesAndWarnsWhenAnArgumentIsNotInTheSchema(): void
    {
        $client = new FakeGraphqlClient(self::INTROSPECTION, self::TASKS);

        $this->createRepository($client, withVariables: true)->findBy(['owner' => self::EMAIL]);

        self::assertStringContainsString('tasks(owner: "' . self::EMAIL . '")', $client->lastQuery);
        self::assertSame([], $client->lastVariables);
        self::assertContains('warning', array_column($this->logger->records, 'level'));
    }

    #[Test]
    public function itKeepsInlineValuesWhenVariablesAreDisabled(): void
    {
        $client = new FakeGraphqlClient(self::TASKS);

        $this->createRepository($client, withVariables: false)->findBy(['email' => self::EMAIL]);

        self::assertStringContainsString('tasks(email: "' . self::EMAIL . '")', $client->lastQuery);
        self::assertSame(1, $client->callCount);
    }

    /**
     * @return GraphqlEntityRepository<FakeTask>
     */
    private function createRepository(FakeGraphqlClient $client, bool $withVariables): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            new GraphqlOrmDataCollector(),
            2,
            logger: $this->logger,
            argumentTypeResolver: $withVariables ? new RootArgumentTypeResolver($client, new ArrayAdapter(), 3600) : null,
        );

        return new GraphqlEntityRepository($manager, FakeTask::class);
    }
}
