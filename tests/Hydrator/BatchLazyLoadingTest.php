<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Exception\EntityNotFoundException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BatchLazyLoadingTest extends TestCase
{
    private const DAB_CHAIN_AS = ['data' => ['chainAs' => ['items' => [
        ['id' => 1, 'label' => 'A1', 'next' => ['id' => 2]],
        ['id' => 4, 'label' => 'A4', 'next' => ['id' => 3]],
        ['id' => 5, 'label' => 'A5', 'next' => ['id' => 2]],
    ]]]];

    #[Test]
    public function itLoadsAllTheGhostsOfAClassWithOneQuery(): void
    {
        $client = new FakeGraphqlClient(self::DAB_CHAIN_AS, ['data' => ['chainBs' => ['items' => [
            ['id' => 2, 'label' => 'B2', 'next' => ['id' => 6]],
            ['id' => 3, 'label' => 'B3', 'next' => ['id' => 7]],
        ]]]]);
        $chainAs = $this->repository($client, new DataApiBuilderDialect())->findAll();

        $labels = [$chainAs[0]->next?->label, $chainAs[1]->next?->label, $chainAs[2]->next?->label];

        self::assertSame(['B2', 'B3', 'B2'], $labels);
        self::assertSame(2, $client->callCount);
        self::assertStringContainsString('chainBs(first: 2, filter: { id: { in: [2, 3] } })', $client->lastQuery);
    }

    #[Test]
    public function itLoadsGhostsOneByOneWhenTheDialectCannotFilterOnAList(): void
    {
        $client = new FakeGraphqlClient(
            ['data' => ['chainAs' => [['id' => 1, 'label' => 'A1', 'next' => ['id' => 2]], ['id' => 4, 'label' => 'A4', 'next' => ['id' => 3]]]]],
            ['data' => ['chainBs' => [['id' => 2, 'label' => 'B2', 'next' => ['id' => 6]]]]],
            ['data' => ['chainBs' => [['id' => 3, 'label' => 'B3', 'next' => ['id' => 7]]]]],
        );
        $chainAs = $this->repository($client, new DefaultDialect())->findAll();

        $labels = [$chainAs[0]->next?->label, $chainAs[1]->next?->label];

        self::assertSame(['B2', 'B3'], $labels);
        self::assertSame(3, $client->callCount);
    }

    #[Test]
    public function itLeavesAGhostMissingFromTheBatchToFailOnItsOwnAccess(): void
    {
        $client = new FakeGraphqlClient(
            self::DAB_CHAIN_AS,
            ['data' => ['chainBs' => ['items' => [['id' => 2, 'label' => 'B2', 'next' => ['id' => 6]]]]]],
            ['data' => ['chainBs' => ['items' => []]]],
        );
        $chainAs = $this->repository($client, new DataApiBuilderDialect())->findAll();
        $label = $chainAs[0]->next?->label;
        $missing = $chainAs[1]->next;
        self::assertNotNull($missing);

        $this->expectException(EntityNotFoundException::class);
        try {
            $missingLabel = $missing->label;
        } finally {
            self::assertSame('B2', $label);
            self::assertSame(3, $client->callCount);
        }
    }

    /**
     * @return GraphqlEntityRepository<ChainA>
     */
    private function repository(FakeGraphqlClient $client, GraphqlQueryDialect $dialect): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 0, $dialect, lazyLoading: true);

        return new GraphqlEntityRepository($manager, ChainA::class);
    }
}
