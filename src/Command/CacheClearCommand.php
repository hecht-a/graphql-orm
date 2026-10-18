<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Evicts the result cache and the second-level cache of the bundle, through GraphqlCache: with any pool, even shared
 * with the application, the values evicted are no longer read, and expire.
 *
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:cache:clear',
    description: 'Evict the result cache and the second-level cache of GraphQL ORM',
)]
final readonly class CacheClearCommand
{
    public function __construct(
        private GraphqlManagerRegistry $managers,
        private CachedEntities $cachedEntities,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('Evict the results of the result cache')]
        bool $results = false,
        #[Option('Evict the entities of the second-level cache')]
        bool $entities = false,
        #[Option('Evict the entities of this class only (short name or FQCN)')]
        ?string $class = null,
        #[Option('Evict the result kept under this key only, given to enableResultCache()')]
        ?string $key = null,
        #[Option('The manager whose caches are evicted; every manager when unset')]
        ?string $manager = null,
    ): int {
        $io = new SymfonyStyle($input, $output);
        // Nothing chosen: both caches.
        $all = !$results && !$entities && $class === null && $key === null;

        try {
            if ($class !== null) {
                [$name, $entityClass] = $this->cachedEntities->entity($class, $manager);
                $this->managers->getManager($name)->getCache()->evictEntityRegion($entityClass);
                $io->writeln(\sprintf('The entities of %s evicted from the second-level cache of "%s".', $entityClass, $name));
            }

            foreach ($this->cachedEntities->managerNames($manager) as $name) {
                $this->clear($io, $name, $key ?? ($all || $results), $all || $entities);
            }
        } catch (\InvalidArgumentException|GraphqlOrmException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success('Caches cleared.');

        return Command::SUCCESS;
    }

    /**
     * @param string|bool $results the key of the result to evict, true for every result, false for none
     */
    private function clear(SymfonyStyle $io, string $name, string|bool $results, bool $entities): void
    {
        $cache = $this->managers->getManager($name)->getCache();
        $done = [];

        if (\is_string($results)) {
            $cache->evictQueryResult($results);
            $done[] = \sprintf('the result "%s"', $results);
        } elseif ($results) {
            $done[] = \sprintf('the results (generation %d)', $cache->evictQueryResults());
        }

        if ($entities) {
            foreach ($this->cachedEntities->all($name) as [, $class]) {
                $cache->evictEntityRegion($class);
                $done[] = \sprintf('the entities of %s', $class);
            }
        }

        if ($done !== []) {
            $io->writeln(\sprintf('Caches of "%s" cleared: %s.', $name, implode(', ', $done)));
        }
    }
}
