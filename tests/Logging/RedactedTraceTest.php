<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Logging;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RedactedTraceTest extends TestCase
{
    private const EMAIL = 'user@example.com';

    private FakeGraphqlClient $client;

    private GraphqlOrmDataCollector $collector;

    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->client = new FakeGraphqlClient(['data' => ['tasks' => []]]);
        $this->collector = new GraphqlOrmDataCollector();
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function itSendsTheRealValuesToTheApi(): void
    {
        $this->findByEmail(new QueryRedactor());

        self::assertStringContainsString(self::EMAIL, $this->client->lastQuery);
    }

    #[Test]
    public function itMasksValuesInTheLogs(): void
    {
        $this->findByEmail(new QueryRedactor());

        $query = $this->logger->records[0]['context']['query'] ?? '';
        self::assertIsString($query);
        self::assertStringNotContainsString(self::EMAIL, $query);
        self::assertStringContainsString('"***"', $query);
    }

    #[Test]
    public function itMasksValuesInTheProfiler(): void
    {
        $this->findByEmail(new QueryRedactor());

        $recorded = $this->collector->getQueries()[0];
        self::assertStringNotContainsString(self::EMAIL, (string) json_encode($recorded));
    }

    #[Test]
    public function itKeepsTheValuesWithoutRedactor(): void
    {
        $this->findByEmail(null);

        $query = $this->logger->records[0]['context']['query'] ?? '';
        self::assertIsString($query);
        self::assertStringContainsString(self::EMAIL, $query);
    }

    private function findByEmail(?QueryRedactor $redactor): void
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $this->client,
            new EntityHydrator($metadataFactory),
            $this->collector,
            2,
            logger: $this->logger,
            redactor: $redactor,
        );

        (new GraphqlEntityRepository($manager, FakeTask::class))->findBy(['email' => self::EMAIL]);
    }
}
