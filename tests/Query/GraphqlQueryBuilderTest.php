<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\GraphqlQuery;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntity;
use GraphqlOrm\Tests\Fixtures\FakeEntityHydrator;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeRepository;
use PHPUnit\Framework\TestCase;

final class GraphqlQueryBuilderTest extends TestCase
{
    public function testManualGraphqlIsReturned(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryBuilder(FakeEntity::class, $manager))
            ->setGraphQL('query { custom }')
            ->getQuery();

        self::assertInstanceOf(GraphqlQuery::class, $query);

        self::assertSame(
            'query { custom }',
            $query->getGraphQL()
        );
    }

    public function testDefaultSelectUsesMetadataFields(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryBuilder(FakeEntity::class, $manager))->getQuery();

        $graphql = $query->getGraphQL();

        self::assertStringContainsString('id', $graphql);
        self::assertStringContainsString('title', $graphql);
    }

    public function testSelectOverridesFields(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryBuilder(FakeEntity::class, $manager))
            ->select('title')
            ->getQuery();

        $graphql = $query->getGraphQL();

        self::assertStringContainsString('title', $graphql);
        self::assertStringNotContainsString("\nid\n", $graphql);
    }

    public function testAddSelectAddsField(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryBuilder(FakeEntity::class, $manager))
            ->select('title')
            ->addSelect('description')
            ->getQuery();

        $graphql = $query->getGraphQL();

        self::assertStringContainsString('title', $graphql);
        self::assertStringContainsString('description', $graphql);
    }

    public function testWhereAddsArguments(): void
    {
        $manager = $this->createManager(new DataApiBuilderDialect());

        $query = (new GraphqlQueryBuilder(FakeEntity::class, $manager));
        $query = $query
            ->where($query->expr()->andX(
                $query->expr()->eq('id', 1),
                $query->expr()->eq('status', 'OPEN')
            ))
            ->getQuery();

        $graphql = $query->getGraphQL();

        self::assertStringContainsString('and: [{ id: { eq: 1 } }, { status: { eq: "OPEN" } }]', $graphql);
    }

    public function testAddSelectWithoutCallingSelectFirst(): void
    {
        $manager = $this->createManager();

        $query = (new GraphqlQueryBuilder(FakeEntity::class, $manager))
            ->addSelect('title')
            ->getQuery();

        $graphql = $query->getGraphQL();

        self::assertStringContainsString(
            'title',
            $graphql
        );
    }

    private function createManager(GraphqlQueryDialect $dialect = new DefaultDialect()): GraphqlManager
    {
        $metadataFactory = new FakeMetadataFactory(
            new GraphqlEntityMetadata(
                FakeEntity::class,
                'tasks',
                FakeRepository::class,
                [
                    new GraphqlFieldMetadata(
                        'id',
                        'id'
                    ),
                    new GraphqlFieldMetadata(
                        'title',
                        'title'
                    ),
                    new GraphqlFieldMetadata(
                        'description',
                        'description'
                    ),
                ],
                new GraphqlFieldMetadata(
                    'id',
                    'id'
                )
            )
        );

        return new GraphqlManager(
            $metadataFactory,
            new FakeGraphqlClient(),
            new FakeEntityHydrator(),
            new GraphqlOrmDataCollector(),
            3,
            $dialect,
        );
    }
}
