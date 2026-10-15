<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Repository;

use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeProjectRepository;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class RepositoryRegistryTest extends TestCase
{
    #[Test]
    public function itGivesTheRepositoryServiceOfTheEntity(): void
    {
        $manager = $this->manager(withRepositories: true);

        $repository = $manager->getRepository(FakeProject::class);

        self::assertInstanceOf(FakeProjectRepository::class, $repository);
        self::assertSame($repository, $manager->getRepository(FakeProject::class));
    }

    #[Test]
    public function itGivesAGenericRepositoryToAnEntityWithoutRepositoryService(): void
    {
        $manager = $this->manager(withRepositories: true);

        $repository = $manager->getRepository(Task::class);

        self::assertSame(GraphqlEntityRepository::class, $repository::class);
        self::assertSame($repository, $manager->getRepository(Task::class));
    }

    #[Test]
    public function itGivesAGenericRepositoryOutsideTheContainer(): void
    {
        self::assertSame(GraphqlEntityRepository::class, $this->manager(withRepositories: false)->getRepository(FakeProject::class)::class);
    }

    private function manager(bool $withRepositories): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = null;
        $repository = null;
        // As a container, the locator gives the shared repository service.
        $repositories = new ServiceLocator([FakeProjectRepository::class => function () use (&$manager, &$repository): FakeProjectRepository {
            self::assertInstanceOf(GraphqlManager::class, $manager);

            return $repository ??= new FakeProjectRepository($manager);
        }]);

        return $manager = new GraphqlManager(
            $metadataFactory,
            new FakeGraphqlClient(),
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
            repositories: $withRepositories ? $repositories : null,
        );
    }
}
