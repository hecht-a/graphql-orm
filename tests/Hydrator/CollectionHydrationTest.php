<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Hydrator;

use Doctrine\Common\Collections\ArrayCollection;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithTaskCollection;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * A relation whose property is typed Collection (doctrine/collections) is hydrated as an ArrayCollection.
 */
final class CollectionHydrationTest extends TestCase
{
    private const USER = ['data' => ['users' => ['items' => [
        ['id' => 1, 'tasks' => ['items' => [['id' => 7, 'title' => 'A'], ['id' => 8, 'title' => 'B']]]],
    ]]]];

    #[Test]
    public function itMapsTheCollectionOfItsGenericType(): void
    {
        $field = (new GraphqlEntityMetadataFactory())->getMetadata(FakeUserWithTaskCollection::class)->field('tasks');

        self::assertSame([FakeTask::class, true], [$field?->relation, $field?->isCollection]);
    }

    #[Test]
    public function itHydratesTheCollectionAsAnArrayCollection(): void
    {
        [$user] = $this->users(new FakeGraphqlClient(self::USER))->findAll();

        self::assertInstanceOf(ArrayCollection::class, $user->tasks);
        $titles = [];

        foreach ($user->tasks as $task) {
            self::assertInstanceOf(FakeTask::class, $task);
            $titles[] = $task->title;
        }

        self::assertSame(['A', 'B'], $titles);
    }

    #[Test]
    public function itSerializesTheCollectionAsAList(): void
    {
        [$user] = $this->users(new FakeGraphqlClient(self::USER))->findAll();
        $serializer = new Serializer([new GraphqlEntityNormalizer(new GraphqlEntityMetadataFactory()), new ObjectNormalizer()], [new JsonEncoder()]);

        self::assertSame('{"id":1,"tasks":[{"id":7,"title":"A"},{"id":8,"title":"B"}]}', $serializer->serialize($user, 'json'));
    }

    /**
     * @return GraphqlEntityRepository<FakeUserWithTaskCollection>
     */
    private function users(FakeGraphqlClient $client): GraphqlEntityRepository
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());

        return new GraphqlEntityRepository($manager, FakeUserWithTaskCollection::class);
    }
}
