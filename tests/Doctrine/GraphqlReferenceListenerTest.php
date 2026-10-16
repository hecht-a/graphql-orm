<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Doctrine;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Doctrine\GraphqlReferenceListener;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\Doctrine\FakeInvoice;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeLabel;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A Doctrine entity referencing an entity of the GraphQL API by its identifier.
 */
final class GraphqlReferenceListenerTest extends TestCase
{
    private FakeGraphqlClient $client;

    protected function setUp(): void
    {
        $this->client = new FakeGraphqlClient(['data' => ['labels' => ['items' => [['code' => 'bug', 'position' => 1]]]]]);
    }

    #[Test]
    public function itReferencesTheGraphqlEntityOnLoadWithoutQuery(): void
    {
        $invoice = new FakeInvoice();
        $invoice->labelCode = 'bug';

        $this->listener()->postLoad($this->event($invoice));

        self::assertInstanceOf(FakeLabel::class, $invoice->label);
        self::assertSame('bug', $invoice->label->code);
        self::assertSame(0, $this->client->callCount);
    }

    #[Test]
    public function itLoadsTheGraphqlEntityOnFirstAccess(): void
    {
        $invoice = new FakeInvoice();
        $invoice->labelCode = 'bug';
        $this->listener()->postLoad($this->event($invoice));

        self::assertSame(1, $invoice->label?->position);
        self::assertSame(1, $this->client->callCount);
    }

    #[Test]
    public function itLeavesTheReferenceEmptyWithoutIdentifier(): void
    {
        $invoice = new FakeInvoice();

        $this->listener()->postLoad($this->event($invoice));

        self::assertNull($invoice->label);
    }

    #[Test]
    public function itStoresTheIdentifierOfTheReferenceOfANewEntity(): void
    {
        $invoice = new FakeInvoice();
        $invoice->label = new FakeLabel();
        $invoice->label->code = 'docs';

        $this->listener()->prePersist($this->event($invoice));

        self::assertSame('docs', $invoice->labelCode);
    }

    private function listener(): GraphqlReferenceListener
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlReferenceListener(new GraphqlManager($metadataFactory, $this->client, new EntityHydrator($metadataFactory), null, 1, new DataApiBuilderDialect()), $metadataFactory);
    }

    /**
     * @return LifecycleEventArgs<ObjectManager>
     */
    private function event(object $entity): LifecycleEventArgs
    {
        return new LifecycleEventArgs($entity, $this->createStub(ObjectManager::class));
    }
}
