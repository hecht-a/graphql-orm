<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Query;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MaxDepthTest extends TestCase
{
    #[Test]
    public function itOnlySelectsTheIdentifierOfRelationsBeyondTheMaxDepth(): void
    {
        $repository = $this->createRepository(maxDepth: 1);

        $graphql = $repository->createQueryBuilder()->getQuery()->getGraphQL();

        self::assertSame(<<<'GRAPHQL'
            query {
              chainAs {
                id
                label
                next {
                  id
                  label
                  next {
                    id
                  }
                }
              }
            }
            GRAPHQL, $graphql);
    }

    #[Test]
    public function itExpandsRelationsUpToTheMaxDepth(): void
    {
        $repository = $this->createRepository(maxDepth: 2);

        $graphql = $repository->createQueryBuilder()->getQuery()->getGraphQL();

        self::assertSame(<<<'GRAPHQL'
            query {
              chainAs {
                id
                label
                next {
                  id
                  label
                  next {
                    id
                    label
                    next {
                      id
                    }
                  }
                }
              }
            }
            GRAPHQL, $graphql);
    }

    #[Test]
    public function itDoesNotLimitAnExplicitSelection(): void
    {
        $repository = $this->createRepository(maxDepth: 1);

        $graphql = $repository->createQueryBuilder()
            ->select('next.next.next.label')
            ->getQuery()
            ->getGraphQL();

        self::assertMatchesRegularExpression('/next \{\s+next \{\s+next \{\s+label/', $graphql);
    }

    /**
     * @return GraphqlEntityRepository<ChainA>
     */
    private function createRepository(int $maxDepth): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        $manager = new GraphqlManager(
            $metadataFactory,
            new FakeGraphqlClient(),
            new EntityHydrator($metadataFactory),
            new GraphqlOrmDataCollector(),
            $maxDepth,
        );

        return new GraphqlEntityRepository($manager, ChainA::class);
    }
}
