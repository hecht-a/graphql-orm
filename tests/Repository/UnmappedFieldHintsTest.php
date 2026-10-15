<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\GraphqlRequestException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Test\MockGraphqlServer;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A query failing while it names fields the entity does not map tells which ones, with the closest mapped field.
 */
final class UnmappedFieldHintsTest extends TestCase
{
    private const SERVER_ERROR = [['message' => 'The specified input object field `titel` does not exist.']];

    #[Test]
    public function itPointsOutTheUnmappedCriteriaOfAFailingQuery(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respondWithErrors(self::SERVER_ERROR));

        $message = self::failure(fn () => $tasks->findBy(['titel' => 'A'], ['dueDat' => Direction::Asc]));

        self::assertStringContainsString('The specified input object field `titel` does not exist.', $message);
        self::assertStringContainsString(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', Task::class), $message);
        self::assertStringContainsString(\sprintf('Field "dueDat" is not mapped on %s: did you mean "dueDate"?', Task::class), $message);
    }

    #[Test]
    public function itPointsOutTheUnmappedFieldsOfAFailingQueryBuilder(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respondWithErrors(self::SERVER_ERROR));
        $qb = $tasks->createQueryBuilder();

        $message = self::failure(fn () => $qb->select('title', 'user.nmae')
            ->where($qb->expr()->orX($qb->expr()->eq('titel', 'A'), $qb->expr()->eq('done', true)))
            ->getQuery()
            ->getResult());

        self::assertStringContainsString(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', Task::class), $message);
        self::assertStringContainsString(\sprintf('Field "nmae" is not mapped on %s: did you mean "name"?', User::class), $message);
        self::assertStringNotContainsString('"done"', $message);
    }

    #[Test]
    public function itPointsOutTheUnmappedCriteriaOfAFailingCount(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respondWithErrors(self::SERVER_ERROR));

        $message = self::failure(fn () => $tasks->count(['titel' => 'A']));

        self::assertStringContainsString(\sprintf('Field "titel" is not mapped on %s: did you mean "title"?', Task::class), $message);
    }

    #[Test]
    public function itTellsAnUnmappedFieldWithoutCloseNameAsIs(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respondWithErrors(self::SERVER_ERROR));

        $message = self::failure(fn () => $tasks->findBy(['priority_level' => 1]));

        self::assertStringContainsString(\sprintf('Field "priority_level" is not mapped on %s.', Task::class), $message);
    }

    #[Test]
    public function itLetsAQueryOnAnUnmappedFieldSucceed(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respond(['tasks' => ['items' => []]]));

        self::assertSame([], $tasks->findBy(['user_id' => 1]));
    }

    #[Test]
    public function itAddsNothingWhenEveryFieldIsMapped(): void
    {
        $tasks = $this->tasks((new MockGraphqlServer())->respondWithErrors([['message' => 'Forbidden']]));

        $message = self::failure(fn () => $tasks->findBy(['title' => 'A']));

        self::assertStringNotContainsString('is not mapped', $message);
    }

    private static function failure(callable $query): string
    {
        try {
            $query();
        } catch (GraphqlRequestException $exception) {
            return $exception->getMessage();
        }

        self::fail('The query must fail.');
    }

    /**
     * @return GraphqlEntityRepository<Task>
     */
    private function tasks(MockGraphqlServer $server): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager(
            $metadataFactory,
            new GraphqlClient($server, 'https://graphql.example.com/graphql'),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
        );

        return new GraphqlEntityRepository($manager, Task::class);
    }
}
