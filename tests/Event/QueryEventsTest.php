<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Event;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Event\QueryEvent;
use GraphqlOrm\Event\QueryExecutedEvent;
use GraphqlOrm\Event\QueryFailedEvent;
use GraphqlOrm\Event\QueryStartedEvent;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEventDispatcher;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class QueryEventsTest extends TestCase
{
    private const TITLE = 'Call the customer';

    private FakeEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new FakeEventDispatcher();
    }

    #[Test]
    public function itDispatchesAStartedThenAnExecutedEvent(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(['data' => ['tasks' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']]]]));

        $repository->findBy(['title' => self::TITLE]);

        self::assertCount(2, $this->dispatcher->events);
        [$started, $executed] = $this->dispatcher->events;
        self::assertInstanceOf(QueryStartedEvent::class, $started);
        self::assertInstanceOf(QueryExecutedEvent::class, $executed);
        self::assertSame(2, $executed->trace->hydratedCount);
        self::assertSame($started->trace->id, $executed->trace->id);
    }

    #[Test]
    public function itMasksQueryValuesInTheEvents(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(['data' => ['tasks' => []]]));

        $repository->findBy(['title' => self::TITLE]);

        foreach ($this->dispatcher->events as $event) {
            self::assertInstanceOf(QueryEvent::class, $event);
            self::assertStringNotContainsString(self::TITLE, $event->trace->graphql);
        }
    }

    #[Test]
    public function itDispatchesAFailedEventWhenTheRequestFails(): void
    {
        $client = new GraphqlClient(new MockHttpClient(new MockResponse('', ['http_code' => 500])), 'https://graphql.example.com/graphql');
        $repository = $this->repository($client);

        try {
            $repository->findAll();
            self::fail('An exception was expected.');
        } catch (GraphqlRequestException $exception) {
        }

        self::assertCount(2, $this->dispatcher->events);
        $failed = $this->dispatcher->events[1];
        self::assertInstanceOf(QueryFailedEvent::class, $failed);
        self::assertSame($exception, $failed->exception);
    }

    #[Test]
    public function itDispatchesAFailedEventWhenTheResponseCannotBeHydrated(): void
    {
        $repository = $this->repository(new FakeGraphqlClient(['data' => ['tasks' => 'not a list']]));

        try {
            $repository->findAll();
            self::fail('An exception was expected.');
        } catch (InvalidGraphqlResponseException) {
        }

        self::assertInstanceOf(QueryFailedEvent::class, $this->dispatcher->events[1] ?? null);
    }

    /**
     * @return GraphqlEntityRepository<FakeTask>
     */
    private function repository(GraphqlClientInterface $client): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            2,
            redactor: new QueryRedactor(),
            eventDispatcher: $this->dispatcher,
        );

        return new GraphqlEntityRepository($manager, FakeTask::class);
    }
}
