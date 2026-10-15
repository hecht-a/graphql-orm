<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * As Doctrine indexBy: the result keyed by a field of its entities.
 */
final class IndexByTest extends TestCase
{
    private const LABELS = ['data' => ['labels' => ['items' => [
        ['code' => 'bug', 'position' => 1],
        ['code' => 'docs', 'position' => 3],
    ]]]];

    #[Test]
    public function itKeysTheEntitiesFoundByAPropertyOfTheirs(): void
    {
        $labels = $this->repository(new FakeGraphqlClient(self::LABELS), FakeLabel::class)->findBy([], indexBy: 'code');

        self::assertSame(['bug', 'docs'], array_keys($labels));
        self::assertSame(3, $labels['docs']->position);
    }

    #[Test]
    public function itKeysTheResultOfAQueryBuilder(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 7, 'title' => 'A'], ['id' => 12, 'title' => 'B']]]]]);

        $tasks = $this->repository($client, FakeTask::class)->createQueryBuilder()->indexBy('id')->getQuery()->getResult();

        self::assertIsArray($tasks);
        self::assertSame([7, 12], array_keys($tasks));
    }

    #[Test]
    public function itKeysTheItemsOfAPage(): void
    {
        $client = new FakeGraphqlClient(['data' => ['labels' => ['items' => [['code' => 'bug', 'position' => 1]], 'hasNextPage' => false, 'endCursor' => null]]]);

        $page = $this->repository($client, FakeLabel::class)->createQueryBuilder()->indexBy('code')->paginate()->limit(10)->getQuery()->getResult();

        self::assertInstanceOf(PaginatedResult::class, $page);
        self::assertSame(['bug'], array_keys($page->items));
    }

    #[Test]
    public function itRejectsAFieldTheEntityDoesNotMap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"titel"');
        $this->repository(new FakeGraphqlClient(), FakeTask::class)->findBy([], indexBy: 'titel');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $entityClass
     *
     * @return GraphqlEntityRepository<T>
     */
    private function repository(FakeGraphqlClient $client, string $entityClass): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect());

        return new GraphqlEntityRepository($manager, $entityClass);
    }
}
