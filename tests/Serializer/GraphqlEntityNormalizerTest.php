<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Serializer;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\LazyTask;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Serializing an entity never loads what it does not hold yet: a lazy relation is written as its identifier, a partial
 * entity as its selected fields.
 */
final class GraphqlEntityNormalizerTest extends TestCase
{
    #[Test]
    public function itWritesALazyRelationAsItsIdentifierWithoutLoadingIt(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A', 'user' => ['id' => 7]]]]]]);
        [$task] = $this->repository($client, LazyTask::class)->findAll();

        $json = $this->serializer()->serialize($task, 'json');

        self::assertSame('{"id":1,"title":"A","user":{"id":7}}', $json);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itWritesAPartialEntityAsItsSelectedFields(): void
    {
        $client = new FakeGraphqlClient(['data' => ['labels' => ['items' => [['code' => 'bug']]]]]);
        $label = $this->repository($client, FakeLabel::class)->createQueryBuilder()->select('code')->getQuery()->getOneOrNullResult();

        self::assertSame('{"code":"bug"}', $this->serializer()->serialize($label, 'json'));
    }

    #[Test]
    public function itWritesOnlyTheSelectedFieldsWhenTheOthersHaveADefaultValue(): void
    {
        $client = new FakeGraphqlClient(['data' => ['chainAs' => ['items' => [['id' => 1]]]]]);
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $hydrator = new EntityHydrator($metadataFactory);
        $manager = new GraphqlManager($metadataFactory, $client, $hydrator, null, 1, new DataApiBuilderDialect());
        $chain = (new GraphqlEntityRepository($manager, ChainA::class))->createQueryBuilder()->select('id')->getQuery()->getOneOrNullResult();

        $serializer = new Serializer([new GraphqlEntityNormalizer($metadataFactory, $hydrator), new ObjectNormalizer()], [new JsonEncoder()]);

        self::assertSame('{"id":1}', $serializer->serialize($chain, 'json'));
    }

    #[Test]
    public function itTellsTheProfilerWhatItWroteWithoutQuery(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A', 'user' => ['id' => 7]]]]]]);
        [$task] = $this->repository($client, LazyTask::class)->findAll();
        $serializer = new Serializer([new GraphqlEntityNormalizer(new GraphqlEntityMetadataFactory(), collector: $collector), new ObjectNormalizer()], [new JsonEncoder()]);

        $serializer->serialize($task, 'json');

        self::assertSame(['identifiers' => 1, 'partial' => 0, 'lazy_loads' => []], $collector->getSerialization());
    }

    #[Test]
    public function itTellsTheProfilerTheRelationsLoadedWhileSerializing(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $client = new FakeGraphqlClient(
            ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A', 'user' => ['id' => 7]]]]]],
            ['data' => ['users' => ['items' => [['id' => 7, 'name' => 'User A', 'tasks' => ['items' => []]]]]]],
        );
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), $collector, 1, new DataApiBuilderDialect());
        [$task] = (new GraphqlEntityRepository($manager, LazyTask::class))->findAll();

        // Without the normalizer of the bundle, the ObjectNormalizer reads the lazy user, which loads it
        (new Serializer([new ObjectNormalizer()], [new JsonEncoder()]))->serialize($task, 'json', ['circular_reference_handler' => fn (object $object) => null]);

        self::assertSame([2], $collector->getSerialization()['lazy_loads']);
    }

    #[Test]
    public function itLeavesTheEntitiesHeldInFullToTheOtherNormalizers(): void
    {
        $client = new FakeGraphqlClient(['data' => ['labels' => ['items' => [['code' => 'bug', 'position' => 1]]]]]);
        [$label] = $this->repository($client, FakeLabel::class)->findAll();

        self::assertFalse((new GraphqlEntityNormalizer(new GraphqlEntityMetadataFactory()))->supportsNormalization($label));
    }

    private function serializer(): Serializer
    {
        return new Serializer([new GraphqlEntityNormalizer(new GraphqlEntityMetadataFactory()), new ObjectNormalizer()], [new JsonEncoder()]);
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
