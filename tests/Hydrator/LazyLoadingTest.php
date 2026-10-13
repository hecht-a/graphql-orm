<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\EntityNotFoundException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\Depth\ChainB;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LazyLoadingTest extends TestCase
{
    private const CHAIN_A = ['data' => ['chainAs' => [['id' => 1, 'label' => 'A', 'next' => ['id' => 2]]]]];

    private const CHAIN_B = ['data' => ['chainBs' => [['id' => 2, 'label' => 'B', 'next' => ['id' => 3]]]]];

    private GraphqlOrmDataCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new GraphqlOrmDataCollector();
    }

    #[Test]
    public function itLoadsARelationReducedToItsIdentifierOnFirstAccess(): void
    {
        $client = new FakeGraphqlClient(self::CHAIN_A, self::CHAIN_B);
        $chainA = $this->repository($client, lazyLoading: true)->findAll()[0];
        $chainB = $chainA->next;
        self::assertInstanceOf(ChainB::class, $chainB);

        $id = $chainB->id;
        $callsBeforeAccess = $client->callCount;
        $label = $chainB->label;

        self::assertSame(2, $id);
        self::assertSame(1, $callsBeforeAccess);
        self::assertSame('B', $label);
        self::assertSame(2, $client->callCount);
        self::assertStringContainsString('chainBs(id: 2', $client->lastQuery);
        self::assertSame(2, $this->collector->getQueryCount());
    }

    #[Test]
    public function itLoadsTheRelationsOfALazyEntityLazilyToo(): void
    {
        $client = new FakeGraphqlClient(self::CHAIN_A, self::CHAIN_B);
        $chainA = $this->repository($client, lazyLoading: true)->findAll()[0];

        $chainC = $chainA->next?->next;

        self::assertSame(3, $chainC?->id);
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itKeepsTheRelationReducedToItsIdentifierWhenLazyLoadingIsDisabled(): void
    {
        $client = new FakeGraphqlClient(self::CHAIN_A, self::CHAIN_B);
        $chainA = $this->repository($client, lazyLoading: false)->findAll()[0];

        $label = $chainA->next?->label;

        self::assertNull($label);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itThrowsWhenTheLazyEntityNoLongerExists(): void
    {
        $client = new FakeGraphqlClient(self::CHAIN_A, ['data' => ['chainBs' => []]]);
        $chainB = $this->repository($client, lazyLoading: true)->findAll()[0]->next;
        self::assertInstanceOf(ChainB::class, $chainB);

        $this->expectException(EntityNotFoundException::class);
        $this->expectExceptionMessage(ChainB::class);
        $label = $chainB->label;
    }

    #[Test]
    public function itStillLoadsAGhostReferencedTwiceInTheResponse(): void
    {
        $client = new FakeGraphqlClient(['data' => ['chainAs' => [
            ['id' => 1, 'label' => 'A', 'next' => ['id' => 2]],
            ['id' => 5, 'label' => 'E', 'next' => ['id' => 2]],
        ]]], self::CHAIN_B);
        $chainAs = $this->repository($client, lazyLoading: true)->findAll();

        $label = $chainAs[0]->next?->label;

        self::assertSame($chainAs[0]->next, $chainAs[1]->next);
        self::assertSame('B', $label);
        self::assertSame(2, $client->callCount);
    }

    #[Test]
    public function itCompletesALazyEntityFoundLaterInTheSameResponseWithoutQuery(): void
    {
        $client = new FakeGraphqlClient(['data' => ['chainAs' => [
            ['id' => 1, 'label' => 'A', 'next' => ['id' => 2]],
            ['id' => 4, 'label' => 'D', 'next' => ['id' => 2, 'label' => 'B', 'next' => ['id' => 3]]],
        ]]]);
        $chainAs = $this->repository($client, lazyLoading: true, maxDepth: 1)->findAll();

        $label = $chainAs[0]->next?->label;

        self::assertSame($chainAs[0]->next, $chainAs[1]->next);
        self::assertSame('B', $label);
        self::assertSame(1, $client->callCount);
    }

    /**
     * @return GraphqlEntityRepository<ChainA>
     */
    private function repository(FakeGraphqlClient $client, bool $lazyLoading, int $maxDepth = 0): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            $this->collector,
            $maxDepth,
            lazyLoading: $lazyLoading,
        );

        return new GraphqlEntityRepository($manager, ChainA::class);
    }
}
