<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Dialect\CursorPaginationDialect;
use GraphqlOrm\Dialect\OffsetPaginationDialect;
use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loads the entities marked #[GraphqlOrm\Cache] from the API, page by page, so that the second-level cache keeps them:
 * find(), the references and the lazy loads then read them without a query, from the first request on.
 *
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:cache:warmup',
    description: 'Load the entities marked #[GraphqlOrm\Cache] into the second-level cache',
)]
final readonly class CacheWarmupCommand
{
    private const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private GraphqlManagerRegistry $managers,
        private CachedEntities $cachedEntities,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Entity class (short name or FQCN); every entity marked #[Cache] when omitted')]
        ?string $entity = null,
        #[Option('The manager whose entities are loaded; every manager when unset')]
        ?string $manager = null,
        #[Option('The entities loaded by each query', name: 'batch-size')]
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            $targets = $entity !== null ? [$this->cachedEntities->entity($entity, $manager)] : $this->cachedEntities->all($manager);
        } catch (\InvalidArgumentException|GraphqlOrmException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        if ($targets === []) {
            $io->warning('No entity is kept in the second-level cache: mark entities with #[GraphqlOrm\Cache], and enable graphql_orm.second_level_cache.');

            return Command::SUCCESS;
        }

        $rows = [];
        $kept = 0;
        $failed = false;

        foreach ($targets as [$name, $class]) {
            $graphqlManager = $this->managers->getManager($name);

            try {
                [$hydrated, $keptOfClass, $duration, $paginated] = $this->warmUp($graphqlManager, $class, max(1, $batchSize));
            } catch (GraphqlOrmException $exception) {
                $io->error(\sprintf('%s: %s', $class, $exception->getMessage()));
                $failed = true;

                continue;
            }

            if (!$paginated) {
                $io->note(\sprintf('The dialect of the manager "%s" cannot paginate: the entities of %s are loaded by one query.', $name, $class));
            }

            $rows[] = [self::shortName($class), $name, $graphqlManager->getCache()->regionName($class), $hydrated, $keptOfClass, \sprintf('%d ms', $duration)];
            $kept += $keptOfClass;
        }

        $io->table(['Entity', 'Manager', 'Region', 'Hydrated', 'Kept', 'Time'], $rows);

        if ($failed) {
            return Command::FAILURE;
        }

        $io->success(\sprintf('%d entities kept in the second-level cache.', $kept));

        return Command::SUCCESS;
    }

    /**
     * Loads every entity of the class, page by page when the dialect paginates: each one hydrated in full is kept.
     *
     * @param class-string $class
     *
     * @return array{int, int, int, bool} the entities hydrated, those kept, the milliseconds spent and whether paginated
     */
    private function warmUp(GraphqlManager $manager, string $class, int $batchSize): array
    {
        $startedAt = hrtime(true);
        $dialect = $manager->getDialect();
        $paginated = $dialect instanceof CursorPaginationDialect || $dialect instanceof OffsetPaginationDialect;
        $repository = $manager->getRepository($class);
        $identifier = $manager->getMetadataFactory()->getMetadata($class)->identifier?->property;
        $hydrated = 0;
        $kept = 0;

        $entities = $paginated
            ? $repository->createQueryBuilder()->limit($batchSize)->paginate()->getQuery()->toIterable()
            : $repository->findAll();

        foreach ($entities as $entity) {
            ++$hydrated;
            $value = $identifier !== null ? (new \ReflectionProperty($entity, $identifier))->getValue($entity) : null;

            if ((\is_int($value) || \is_string($value)) && $manager->getCache()->containsEntity($class, $value)) {
                ++$kept;
            }
        }

        // findAll() keeps the entities in the identity map of the console: forget them.
        $manager->clear();

        return [$hydrated, $kept, (int) round((hrtime(true) - $startedAt) / 1e6), $paginated];
    }

    private static function shortName(string $class): string
    {
        return substr((string) strrchr('\\' . $class, '\\'), 1);
    }
}
