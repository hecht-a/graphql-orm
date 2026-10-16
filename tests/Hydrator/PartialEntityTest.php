<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\PartialEntityException;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An entity hydrated from a selection of its fields tells which ones were not selected, rather than the error of PHP
 * for a typed property read before initialization.
 */
final class PartialEntityTest extends TestCase
{
    private const LABEL_CODE_ONLY = ['data' => ['labels' => ['items' => [['code' => 'bug']]]]];

    #[Test]
    public function itTellsThePropertiesTheQueryDidNotSelect(): void
    {
        $label = $this->labels(new FakeGraphqlClient(self::LABEL_CODE_ONLY))->createQueryBuilder()->select('code')->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(FakeLabel::class, $label);

        $this->expectException(PartialEntityException::class);
        $this->expectExceptionMessage('"GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel" was hydrated without position, not selected by the query');
        self::assertGreaterThan(0, $label->position);
    }

    #[Test]
    public function itKeepsTheSelectedProperties(): void
    {
        $label = $this->labels(new FakeGraphqlClient(self::LABEL_CODE_ONLY))->createQueryBuilder()->select('code')->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(FakeLabel::class, $label);

        try {
            self::assertGreaterThan(0, $label->position);
        } catch (PartialEntityException) {
        }

        self::assertSame('bug', $label->code);
    }

    #[Test]
    public function itKeepsTheDefaultValueOfAPropertyNotSelected(): void
    {
        $client = new FakeGraphqlClient(['data' => ['chainAs' => ['items' => [['id' => 1]]]]]);
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect());

        $chain = (new GraphqlEntityRepository($manager, ChainA::class))->createQueryBuilder()->select('id')->getQuery()->getOneOrNullResult();

        self::assertInstanceOf(ChainA::class, $chain);
        self::assertNull($chain->label);
    }

    #[Test]
    public function itRemembersTheFieldsNotSelectedEvenWithADefaultValue(): void
    {
        $client = new FakeGraphqlClient(['data' => ['chainAs' => ['items' => [['id' => 1]]]]], ['data' => ['chainAs' => ['items' => [['id' => 1, 'label' => 'A', 'next' => null]]]]]);
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $hydrator = new EntityHydrator($metadataFactory);
        $manager = new GraphqlManager($metadataFactory, $client, $hydrator, null, 1, new DataApiBuilderDialect(), identityMap: new IdentityMap());
        $chains = new GraphqlEntityRepository($manager, ChainA::class);
        $chain = $chains->createQueryBuilder()->select('id')->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(ChainA::class, $chain);
        $unselected = $hydrator->unselectedProperties($chain);

        $chains->findAll();

        self::assertSame(['label', 'next'], $unselected);
        self::assertSame([], $hydrator->unselectedProperties($chain));
    }

    #[Test]
    public function itCompletesThePartialEntityMetAgainInFull(): void
    {
        $client = new FakeGraphqlClient(self::LABEL_CODE_ONLY, ['data' => ['labels' => ['items' => [['code' => 'bug', 'position' => 1]]]]]);
        $labels = $this->labels($client, new IdentityMap());
        $partial = $labels->createQueryBuilder()->select('code')->getQuery()->getOneOrNullResult();
        self::assertInstanceOf(FakeLabel::class, $partial);

        $labels->findAll();

        self::assertSame(1, $partial->position);
    }

    /**
     * @return GraphqlEntityRepository<FakeLabel>
     */
    private function labels(FakeGraphqlClient $client, ?IdentityMap $identityMap = null): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect(), identityMap: $identityMap);

        return new GraphqlEntityRepository($manager, FakeLabel::class);
    }
}
