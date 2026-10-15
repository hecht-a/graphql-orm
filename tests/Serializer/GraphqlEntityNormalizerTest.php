<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Serializer;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
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
