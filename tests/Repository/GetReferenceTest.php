<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\EntityNotFoundException;
use GraphqlOrm\Execution\IdentityMap;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * As Doctrine getReference(): an entity of which only the identifier is known, loaded on first access.
 */
final class GetReferenceTest extends TestCase
{
    private const LABEL = ['data' => ['labels' => ['items' => [['code' => 'bug', 'position' => 1]]]]];

    #[Test]
    public function itGivesTheEntityWithoutQuery(): void
    {
        $client = new FakeGraphqlClient(self::LABEL);

        $label = $this->labels($client)->getReference('bug');

        self::assertSame('bug', $label->code);
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itLoadsTheEntityOnFirstAccess(): void
    {
        $client = new FakeGraphqlClient(self::LABEL);
        $label = $this->labels($client)->getReference('bug');

        self::assertSame(1, $label->position);
        self::assertSame(1, $client->callCount);
        self::assertStringContainsString('bug', $client->lastQuery);
    }

    #[Test]
    public function itGivesTheEntityTheIdentityMapKnows(): void
    {
        $labels = $this->labels(new FakeGraphqlClient(self::LABEL), new IdentityMap());
        [$known] = $labels->findAll();

        self::assertSame($known, $labels->getReference('bug'));
    }

    #[Test]
    public function itLoadsTheReferencesOfAClassWithOneQueryThroughTheSharedIdentityMap(): void
    {
        $client = new FakeGraphqlClient(['data' => ['labels' => ['items' => [['code' => 'bug', 'position' => 1], ['code' => 'docs', 'position' => 3]]]]]);
        $labels = $this->labels($client, new IdentityMap());
        $bug = $labels->getReference('bug');
        $docs = $labels->getReference('docs');

        self::assertSame(1, $bug->position);
        self::assertSame(3, $docs->position);
        self::assertSame(1, $client->callCount);
    }

    #[Test]
    public function itThrowsOnFirstAccessWhenTheEntityDoesNotExist(): void
    {
        $label = $this->labels(new FakeGraphqlClient(['data' => ['labels' => ['items' => []]]]))->getReference('nope');

        $this->expectException(EntityNotFoundException::class);
        self::assertGreaterThan(0, $label->position);
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
