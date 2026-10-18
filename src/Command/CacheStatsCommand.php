<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Cache\CachePoolDescriber;
use GraphqlOrm\Cache\GraphqlCache;
use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Describes the caches of each manager: their pools, adapters and lifetimes, the entities marked #[GraphqlOrm\Cache] by
 * region, and how many times they were evicted. It reads no value: every pool can be described.
 *
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:cache:stats',
    description: 'Describe the result cache and the second-level cache of GraphQL ORM',
)]
final readonly class CacheStatsCommand
{
    /**
     * @param ContainerInterface $cachePools the CachePoolDescriber of each manager, by name
     */
    public function __construct(
        private GraphqlManagerRegistry $managers,
        private CachedEntities $cachedEntities,
        private ContainerInterface $cachePools,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('The manager whose caches are described; every manager when unset')]
        ?string $manager = null,
    ): int {
        $io = new SymfonyStyle($input, $output);

        try {
            foreach ($this->cachedEntities->managerNames($manager) as $name) {
                $this->describe($io, $name);
            }
        } catch (\InvalidArgumentException|GraphqlOrmException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function describe(SymfonyStyle $io, string $name): void
    {
        $io->section(\sprintf('Manager "%s"', $name));
        $cache = $this->managers->getManager($name)->getCache();
        $describer = $this->cachePools->has($name) ? $this->cachePools->get($name) : null;
        $pools = $describer instanceof CachePoolDescriber ? $describer->describe() : [];

        if ($pools !== []) {
            $io->table(['Cache', 'Pool', 'Adapter', 'Lifetime', 'Evictions'], array_map(
                static fn (array $pool): array => [
                    $pool['name'],
                    $pool['id'],
                    $pool['adapter'],
                    self::lifetime($pool['lifetime']),
                    $pool['name'] === GraphqlCache::RESULT_CACHE ? $cache->resultsGeneration() : '',
                ],
                $pools,
            ));
        }

        if (!\in_array(GraphqlCache::RESULT_CACHE, array_column($pools, 'name'), true)) {
            $io->note('No result cache: set graphql_orm.result_cache.pool.');
        }

        if (!$cache->keepsEntities()) {
            $io->note('The second-level cache is disabled: enable graphql_orm.second_level_cache.');

            return;
        }

        $entities = $this->cachedEntities->all($name);

        if ($entities === []) {
            $io->note('No entity is marked #[GraphqlOrm\Cache].');

            return;
        }

        $io->table(['Entity', 'Region', 'Lifetime', 'Evictions'], array_map(
            static fn (array $entity): array => [
                substr((string) strrchr('\\' . $entity[1], '\\'), 1),
                $cache->regionName($entity[1]),
                self::lifetime($cache->entityLifetime($entity[1])),
                $cache->entityGeneration($entity[1]),
            ],
            $entities,
        ));
    }

    private static function lifetime(?int $lifetime): string
    {
        return $lifetime !== null ? \sprintf('%d s', $lifetime) : 'pool default';
    }
}
