<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Registry;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Command\FindCommand;
use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Doctrine\GraphqlReferenceListener;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Registry\ManagerRouter;
use GraphqlOrm\Serializer\GraphqlEntityNormalizer;
use GraphqlOrm\Tests\Fixtures\Crm\Contact;
use GraphqlOrm\Tests\Fixtures\Doctrine\FakeOrder;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * The services of the bundle work with the entities of every API: each one through the manager of the entity.
 */
final class MultipleApisTest extends TestCase
{
    private const CONTACT = ['data' => ['contacts' => [['id' => 1, 'name' => 'Contact A', 'email' => 'contact.a@example.com']]]];

    private FakeGraphqlClient $mainClient;

    private FakeGraphqlClient $crmClient;

    private GraphqlManagerRegistry $registry;

    protected function setUp(): void
    {
        $this->mainClient = new FakeGraphqlClient();
        $this->crmClient = new FakeGraphqlClient(self::CONTACT);
        $this->registry = $this->registry($this->crmClient);
    }

    #[Test]
    public function itResolvesTheArgumentOfAControllerFromTheApiOfItsEntity(): void
    {
        $resolved = (new GraphqlEntityValueResolver($this->registry))
            ->resolve(new Request(attributes: ['contact' => '1']), new ArgumentMetadata('contact', Contact::class, false, false, null));

        self::assertInstanceOf(Contact::class, $resolved[0] ?? null);
        $this->assertSentToTheCrm();
    }

    #[Test]
    public function itLoadsTheChoicesOfAFormFieldFromTheApiOfItsEntity(): void
    {
        $factory = Forms::createFormFactoryBuilder()->addType(new GraphqlEntityType($this->registry))->getFormFactory();

        $factory->create(GraphqlEntityType::class, null, ['class' => Contact::class])->createView();

        $this->assertSentToTheCrm();
    }

    #[Test]
    public function itSearchesTheEntitiesOfAnAutocompleteFieldInTheApiOfTheirClass(): void
    {
        $signer = new UriSigner('secret');
        $url = $signer->sign(EntityAutocompleteController::PATH . '?' . http_build_query(['class' => Contact::class, 'fields' => 'name']));

        (new EntityAutocompleteController($this->registry, $signer))(Request::create($url . '&query=A'));

        $this->assertSentToTheCrm();
    }

    #[Test]
    public function itWritesAPartialEntityOfAnotherApiWithItsSelectedFieldsOnly(): void
    {
        $registry = $this->registry(new FakeGraphqlClient(['data' => ['contacts' => [['id' => 1, 'name' => 'Contact A']]]]));
        $contact = $registry->getRepository(Contact::class)->createQueryBuilder()->select('name')->getQuery()->getOneOrNullResult();
        $serializer = new Serializer([new GraphqlEntityNormalizer(new GraphqlEntityMetadataFactory(), managers: $registry), new ObjectNormalizer()]);

        self::assertSame(['id' => 1, 'name' => 'Contact A'], $serializer->normalize($contact));
    }

    #[Test]
    public function itReferencesFromADoctrineEntityAnEntityOfAnotherApi(): void
    {
        $order = new FakeOrder();
        $order->contactId = 1;

        (new GraphqlReferenceListener($this->registry))->postLoad(new LifecycleEventArgs($order, $this->createStub(ObjectManager::class)));

        self::assertSame('Contact A', $order->contact?->name);
        $this->assertSentToTheCrm();
    }

    #[Test]
    public function itFindsTheEntitiesOfAnotherApiFromTheConsole(): void
    {
        $locator = new EntityClassLocator('', '', [['dir' => \dirname(__DIR__) . '/Fixtures/FakeEntity', 'namespace' => 'GraphqlOrm\\Tests\\Fixtures\\FakeEntity'], ['dir' => \dirname(__DIR__) . '/Fixtures/Crm', 'namespace' => 'GraphqlOrm\\Tests\\Fixtures\\Crm']]);
        $tester = new CommandTester((new Command('graphqlorm:find'))->setCode(new FindCommand($this->registry, $locator)));

        $tester->execute(['entity' => 'Contact']);

        self::assertStringContainsString('Contact A', $tester->getDisplay());
        $this->assertSentToTheCrm();
    }

    private function assertSentToTheCrm(): void
    {
        self::assertSame([0, 1], [$this->mainClient->callCount, $this->crmClient->callCount]);
    }

    private function registry(FakeGraphqlClient $crmClient): GraphqlManagerRegistry
    {
        $router = new ManagerRouter(['main' => 'GraphqlOrm\\Tests\\Fixtures\\FakeEntity', 'crm' => 'GraphqlOrm\\Tests\\Fixtures\\Crm'], 'main');
        $main = $this->manager('main', $this->mainClient, $router);
        $crm = $this->manager('crm', $crmClient, $router);

        return new GraphqlManagerRegistry(new ServiceLocator(['main' => static fn () => $main, 'crm' => static fn () => $crm]), $router);
    }

    private function manager(string $name, FakeGraphqlClient $client, ManagerRouter $router): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DefaultDialect(), name: $name, router: $router);
    }
}
