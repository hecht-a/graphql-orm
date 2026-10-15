<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Form;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeProjectRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

final class GraphqlEntityTypeTest extends TestCase
{
    private const TASKS = ['data' => ['tasks' => ['items' => [['id' => 1, 'title' => 'A'], ['id' => 2, 'title' => 'B']]]]];

    #[Test]
    public function itLoadsTheChoicesOnlyWhenTheyAreNeeded(): void
    {
        $client = new FakeGraphqlClient(self::TASKS);

        $this->factory($client)->create(GraphqlEntityType::class, null, ['class' => FakeTask::class]);

        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itTurnsTheSubmittedIdentifierIntoTheEntity(): void
    {
        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, null, ['class' => FakeTask::class]);

        $form->submit('2');

        self::assertTrue($form->isSynchronized());
        self::assertInstanceOf(FakeTask::class, $form->getData());
        self::assertSame(2, $form->getData()->id);
    }

    #[Test]
    public function itRejectsAnIdentifierThatIsNotAChoice(): void
    {
        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, null, ['class' => FakeTask::class]);

        $form->submit('9');

        self::assertFalse($form->isSynchronized());
        self::assertNull($form->getData());
    }

    #[Test]
    public function itUsesTheIdentifiersAsValuesAndTheChoiceLabelOption(): void
    {
        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, null, ['class' => FakeTask::class, 'choice_label' => 'title']);

        self::assertSame([['1', 'A'], ['2', 'B']], self::choices($form->createView()->vars['choices']));
    }

    #[Test]
    public function itLabelsTheChoicesWithTheirIdentifierByDefault(): void
    {
        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, null, ['class' => FakeTask::class]);

        self::assertSame([['1', '1'], ['2', '2']], self::choices($form->createView()->vars['choices']));
    }

    #[Test]
    public function itSelectsTheChoiceOfAnEntityLoadedByAnotherQuery(): void
    {
        $task = new FakeTask();
        $task->id = 2;

        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, $task, ['class' => FakeTask::class]);

        self::assertSame('2', $form->createView()->vars['value']);
    }

    #[Test]
    public function itSelectsSeveralEntities(): void
    {
        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, null, ['class' => FakeTask::class, 'multiple' => true]);

        $form->submit(['1', '2']);

        $tasks = $form->getData();
        self::assertIsArray($tasks);
        self::assertCount(2, $tasks);
        self::assertInstanceOf(FakeTask::class, $tasks[0]);
        self::assertInstanceOf(FakeTask::class, $tasks[1]);
        self::assertSame([1, 2], [$tasks[0]->id, $tasks[1]->id]);
    }

    #[Test]
    public function itLoadsTheChoicesWithTheQueryBuilderOption(): void
    {
        $client = new FakeGraphqlClient(self::TASKS);
        $form = $this->factory($client)->create(GraphqlEntityType::class, null, [
            'class' => FakeTask::class,
            'query_builder' => fn (GraphqlEntityRepository $repository) => $repository->createQueryBuilder()->where($repository->createQueryBuilder()->expr()->eq('title', 'A')),
        ]);

        $form->createView();

        self::assertStringContainsString('filter: { title: { eq: "A" } }', $client->lastQuery);
    }

    #[Test]
    public function itRejectsAQueryBuilderOptionNotReturningAQueryBuilder(): void
    {
        $form = $this->factory(new FakeGraphqlClient(self::TASKS))->create(GraphqlEntityType::class, null, [
            'class' => FakeTask::class,
            'query_builder' => fn () => [],
        ]);

        $this->expectException(InvalidArgumentException::class);

        $form->createView();
    }

    #[Test]
    public function itGivesTheRepositoryServiceToTheQueryBuilderOption(): void
    {
        $client = new FakeGraphqlClient(['data' => ['projects' => ['items' => [['id' => 1, 'name' => 'P']]]]]);
        $received = null;
        $form = $this->factory($client, withRepositories: true)->create(GraphqlEntityType::class, null, [
            'class' => FakeProject::class,
            'query_builder' => function (GraphqlEntityRepository $repository) use (&$received) {
                $received = $repository;

                return $repository->createQueryBuilder();
            },
        ]);

        $form->createView();

        self::assertInstanceOf(FakeProjectRepository::class, $received);
    }

    #[Test]
    public function itNeedsTheClassOfTheEntities(): void
    {
        $factory = $this->factory(new FakeGraphqlClient());

        $this->expectException(MissingOptionsException::class);

        $factory->create(GraphqlEntityType::class);
    }

    /**
     * @return list<array{string, mixed}> value and label of each choice
     */
    private static function choices(mixed $choices): array
    {
        self::assertIsArray($choices);
        $values = [];

        foreach ($choices as $choice) {
            self::assertInstanceOf(ChoiceView::class, $choice);
            $values[] = [$choice->value, $choice->label];
        }

        return $values;
    }

    private function factory(FakeGraphqlClient $client, bool $withRepositories = false): FormFactoryInterface
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = null;
        $repositories = new ServiceLocator([FakeProjectRepository::class => function () use (&$manager): FakeProjectRepository {
            self::assertInstanceOf(GraphqlManager::class, $manager);

            return new FakeProjectRepository($manager);
        }]);
        $manager = new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 2, new DataApiBuilderDialect(), repositories: $withRepositories ? $repositories : null);

        return Forms::createFormFactoryBuilder()
            ->addType(new GraphqlEntityType($manager))
            ->getFormFactory();
    }
}
