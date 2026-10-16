<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Form;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Choices among many entities: only the submitted ones are loaded, and the search of an autocomplete field is sent to a
 * signed endpoint of the bundle.
 */
final class GraphqlEntityAutocompleteTest extends TestCase
{
    private const TASK_2 = ['data' => ['tasks' => ['items' => [['id' => 2, 'title' => 'B']]]]];

    #[Test]
    public function itLoadsOnlyTheSubmittedEntitiesWhenTheChoicesAreLazy(): void
    {
        $client = new FakeGraphqlClient(self::TASK_2);
        $form = $this->factory($client)->create(GraphqlEntityType::class, null, ['class' => FakeTask::class, 'choice_lazy' => true]);

        $form->submit('2');

        self::assertInstanceOf(FakeTask::class, $form->getData());
        self::assertSame(2, $form->getData()->id);
        self::assertSame(1, $client->callCount);
        self::assertStringContainsString('id: { in: [2] }', $client->lastQuery);
    }

    #[Test]
    public function itKeepsTheRestrictionOfTheQueryBuilderForTheSubmittedEntities(): void
    {
        $client = new FakeGraphqlClient(['data' => ['tasks' => ['items' => []]]]);
        $form = $this->factory($client)->create(GraphqlEntityType::class, null, [
            'class' => FakeTask::class,
            'choice_lazy' => true,
            'query_builder' => fn (GraphqlEntityRepository $repository) => $repository->createQueryBuilder()->where($repository->createQueryBuilder()->expr()->eq('title', 'A')),
        ]);

        $form->submit('2');

        self::assertFalse($form->isSynchronized());
        self::assertStringContainsString('title: { eq: "A" }', $client->lastQuery);
        self::assertStringContainsString('id: { in: [2] }', $client->lastQuery);
    }

    #[Test]
    public function itGivesAnAutocompleteFieldTheSignedUrlOfItsSearch(): void
    {
        $client = new FakeGraphqlClient();
        $form = $this->factory($client)->create(GraphqlEntityType::class, null, ['class' => FakeTask::class, 'autocomplete' => true, 'search_fields' => ['title']]);

        $form->createView();
        $url = $form->getConfig()->getOption('autocomplete_url');

        self::assertIsString($url);
        self::assertStringStartsWith('/_graphql_orm/autocomplete?', $url);
        self::assertStringContainsString('class=' . rawurlencode(FakeTask::class) . '&fields=title', $url);
        self::assertTrue((new UriSigner('secret'))->check($url));
        self::assertTrue($form->getConfig()->getOption('choice_lazy'));
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itSignsTheNumberOfResultsOfTheMaxResultsOption(): void
    {
        $factory = Forms::createFormFactoryBuilder()
            ->addType(new GraphqlEntityType(GraphqlManagerRegistry::forManager($this->manager(new FakeGraphqlClient())), new UriSigner('secret')))
            ->addTypeExtension(new class extends \Symfony\Component\Form\AbstractTypeExtension {
                public static function getExtendedTypes(): iterable
                {
                    return [\Symfony\Component\Form\Extension\Core\Type\ChoiceType::class];
                }

                public function configureOptions(\Symfony\Component\OptionsResolver\OptionsResolver $resolver): void
                {
                    // As Symfony UX Autocomplete does
                    $resolver->setDefault('max_results', 10);
                }
            })
            ->getFormFactory();

        $form = $factory->create(GraphqlEntityType::class, null, ['class' => FakeTask::class, 'autocomplete' => true, 'search_fields' => ['title'], 'max_results' => 5]);

        $url = $form->getConfig()->getOption('autocomplete_url');
        self::assertIsString($url);
        self::assertStringContainsString('max=5', $url);
    }

    #[Test]
    public function itNeedsTheFieldsToSearchForAnAutocompleteField(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('search_fields');
        $this->factory(new FakeGraphqlClient())->create(GraphqlEntityType::class, null, ['class' => FakeTask::class, 'autocomplete' => true])->createView();
    }

    private function factory(FakeGraphqlClient $client): FormFactoryInterface
    {
        return Forms::createFormFactoryBuilder()
            ->addType(new GraphqlEntityType(GraphqlManagerRegistry::forManager($this->manager($client)), new UriSigner('secret')))
            ->getFormFactory();
    }

    private function manager(FakeGraphqlClient $client): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect());
    }
}
