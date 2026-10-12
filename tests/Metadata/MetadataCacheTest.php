<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Metadata;

use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\MetadataCacheWarmer;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUserWithCollection;
use GraphqlOrm\Tests\Fixtures\FakeEntity\NotAnEntity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapterEvent;

final class MetadataCacheTest extends TestCase
{
    #[Test]
    public function itServesMetadataFromThePersistentCache(): void
    {
        $pool = new TraceableAdapter(new ArrayAdapter());
        $built = (new GraphqlEntityMetadataFactory(cache: $pool))->getMetadata(FakeUserWithCollection::class);
        $pool->clearCalls();

        $cached = (new GraphqlEntityMetadataFactory(cache: $pool))->getMetadata(FakeUserWithCollection::class);

        self::assertEquals($built, $cached);
        $call = $pool->getCalls()[0] ?? null;
        self::assertInstanceOf(TraceableAdapterEvent::class, $call);
        self::assertSame(1, $call->hits);
    }

    #[Test]
    public function itDoesNotCacheAnInvalidEntity(): void
    {
        $pool = new ArrayAdapter();
        $factory = new GraphqlEntityMetadataFactory(cache: $pool);

        try {
            $factory->getMetadata(NotAnEntity::class);
            self::fail('An exception was expected.');
        } catch (NotAGraphqlEntityException) {
        }

        self::assertSame([], array_filter($pool->getValues()));
    }

    #[Test]
    public function itWarmsUpTheMetadataOfEveryEntity(): void
    {
        $pool = new ArrayAdapter();
        $warmer = new MetadataCacheWarmer(new GraphqlEntityMetadataFactory(cache: $pool), [FakeTask::class, FakeUserWithCollection::class]);

        $warmer->warmUp(sys_get_temp_dir());

        self::assertCount(2, array_filter($pool->getValues()));
        self::assertTrue($warmer->isOptional());
    }

    #[Test]
    public function itSkipsClassesOfTheEntityDirectoryThatAreNotEntities(): void
    {
        $pool = new ArrayAdapter();
        $warmer = new MetadataCacheWarmer(new GraphqlEntityMetadataFactory(cache: $pool), [NotAnEntity::class, 'App\Missing\Entity', FakeTask::class]);

        $warmer->warmUp(sys_get_temp_dir());

        self::assertCount(1, array_filter($pool->getValues()));
    }
}
