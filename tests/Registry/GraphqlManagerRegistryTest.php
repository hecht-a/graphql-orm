<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Registry;

use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Registry\ManagerRouter;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\Crm\Contact;
use GraphqlOrm\Tests\Fixtures\Crm\ContactWithTask;
use GraphqlOrm\Tests\Fixtures\Depth\ChainA;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeCrmLabel;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityOfUnknownManager;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Several GraphQL APIs, a manager each: an entity belongs to the manager its #[Entity] names, or whose entity namespace
 * holds it, or to the default manager.
 */
final class GraphqlManagerRegistryTest extends TestCase
{
    private FakeGraphqlClient $mainClient;

    private FakeGraphqlClient $crmClient;

    private GraphqlManager $main;

    private GraphqlManager $crm;

    private GraphqlManagerRegistry $registry;

    protected function setUp(): void
    {
        $router = new ManagerRouter(['main' => 'GraphqlOrm\\Tests\\Fixtures\\FakeEntity', 'crm' => 'GraphqlOrm\\Tests\\Fixtures\\Crm'], 'main');
        $this->mainClient = new FakeGraphqlClient(['data' => ['tasks' => []]]);
        $this->crmClient = new FakeGraphqlClient(['data' => ['contacts' => [['id' => 1, 'name' => 'Contact A']]]]);
        $this->main = $this->manager('main', $this->mainClient, $router);
        $this->crm = $this->manager('crm', $this->crmClient, $router);
        $this->registry = new GraphqlManagerRegistry(new ServiceLocator(['main' => fn () => $this->main, 'crm' => fn () => $this->crm]), $router);
    }

    #[Test]
    public function itGivesTheManagersByTheirName(): void
    {
        self::assertSame($this->main, $this->registry->getManager());
        self::assertSame($this->crm, $this->registry->getManager('crm'));
        self::assertSame(['main', 'crm'], $this->registry->getManagerNames());
        self::assertSame('main', $this->registry->getDefaultManagerName());
    }

    #[Test]
    public function itRefusesAManagerItDoesNotKnow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"billing"');
        $this->registry->getManager('billing');
    }

    #[Test]
    public function itGivesAnEntityTheManagerWhoseNamespaceHoldsIt(): void
    {
        self::assertSame($this->crm, $this->registry->getManagerForClass(Contact::class));
        self::assertSame($this->main, $this->registry->getManagerForClass(FakeTask::class));
    }

    #[Test]
    public function itGivesAnEntityOutsideTheNamespacesTheDefaultManager(): void
    {
        self::assertSame($this->main, $this->registry->getManagerForClass(ChainA::class));
    }

    #[Test]
    public function itGivesAnEntityTheManagerItsEntityAttributeNames(): void
    {
        self::assertSame($this->crm, $this->registry->getManagerForClass(FakeCrmLabel::class));
    }

    #[Test]
    public function itRefusesAnEntityNamingAManagerItDoesNotKnow(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"billing"');
        $this->registry->getManagerForClass(FakeEntityOfUnknownManager::class);
    }

    #[Test]
    public function itSendsTheQueriesOfAnEntityToItsApi(): void
    {
        $contacts = $this->registry->getRepository(Contact::class)->findAll();

        self::assertSame('Contact A', $contacts[0]->name);
        self::assertSame([0, 1], [$this->mainClient->callCount, $this->crmClient->callCount]);
    }

    #[Test]
    public function itRefusesTheEntityOfAnotherApi(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"GraphqlOrm\\Tests\\Fixtures\\Crm\\Contact" belongs to the GraphQL ORM manager "crm", not "main"');
        new GraphqlEntityRepository($this->main, Contact::class);
    }

    #[Test]
    public function itRefusesARelationToAnEntityOfAnotherApi(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('ContactWithTask::$task targets "GraphqlOrm\\Tests\\Fixtures\\FakeEntity\\FakeTask" of the manager "main"');
        $this->registry->getRepository(ContactWithTask::class);
    }

    #[Test]
    public function itRefusesARelationToAnEntityOfAnotherApiEveryTime(): void
    {
        foreach ([1, 2] as $attempt) {
            try {
                $this->registry->getRepository(ContactWithTask::class);
                self::fail('Attempt ' . $attempt . ': the relation to another API was accepted.');
            } catch (LogicException) {
            }
        }

        $this->expectException(LogicException::class);
        new GraphqlEntityRepository($this->crm, ContactWithTask::class);
    }

    private function manager(string $name, FakeGraphqlClient $client, ManagerRouter $router): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DefaultDialect(), name: $name, router: $router);
    }
}
