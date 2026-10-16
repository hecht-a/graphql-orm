<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\DependencyInjection;

use GraphqlOrm\DependencyInjection\Configuration;
use GraphqlOrm\DependencyInjection\ManagerConfigs;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\HasuraDialect;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * Several GraphQL APIs, each with a manager of its own: the options of the root are those of every manager unless it
 * sets them, and a configuration without managers is the one of the manager "default".
 */
final class ManagerConfigsTest extends TestCase
{
    #[Test]
    public function itMakesTheRootTheDefaultManagerWithoutManagers(): void
    {
        $configs = $this->resolve(['endpoint' => 'https://api.example.com/graphql', 'max_depth' => 2]);

        self::assertSame('default', $configs->defaultManager);
        self::assertSame(['default'], array_keys($configs->managers));
        self::assertSame(['https://api.example.com/graphql', 2, DefaultDialect::class], [
            $configs->managers['default']['endpoint'],
            $configs->managers['default']['max_depth'],
            $configs->managers['default']['dialect'],
        ]);
    }

    #[Test]
    public function itGivesEveryManagerTheOptionsOfTheRootItDoesNotSet(): void
    {
        $configs = $this->resolve([
            'max_depth' => 2,
            'headers' => ['X-App' => 'shop'],
            'managers' => [
                'main' => ['endpoint' => 'https://main.example.com/graphql'],
                'crm' => ['endpoint' => 'https://crm.example.com/graphql', 'dialect' => HasuraDialect::class, 'max_depth' => 3, 'headers' => ['X-Tenant' => 'a']],
            ],
        ]);

        self::assertSame([2, DefaultDialect::class, ['X-App' => 'shop']], [$configs->managers['main']['max_depth'], $configs->managers['main']['dialect'], $configs->managers['main']['headers']]);
        self::assertSame([3, HasuraDialect::class, ['X-App' => 'shop', 'X-Tenant' => 'a']], [$configs->managers['crm']['max_depth'], $configs->managers['crm']['dialect'], $configs->managers['crm']['headers']]);
    }

    #[Test]
    public function itMergesTheNestedOptionsOfAManagerWithThoseOfTheRoot(): void
    {
        $configs = $this->resolve([
            'second_level_cache' => ['enabled' => true],
            'managers' => [
                'crm' => [
                    'endpoint' => 'https://crm.example.com/graphql',
                    'second_level_cache' => ['pool' => 'cache.redis'],
                    'mapping' => ['entity' => ['dir' => '%kernel.project_dir%/src/Crm', 'namespace' => 'App\\Crm']],
                ],
            ],
        ]);

        $crm = $configs->managers['crm'];
        self::assertSame([true, 'cache.redis', 3600], [$crm['second_level_cache']['enabled'], $crm['second_level_cache']['pool'], $crm['second_level_cache']['default_lifetime']]);
        self::assertSame('App\\Crm', $crm['mapping']['entity']['namespace']);
        self::assertSame('App\\GraphQL\\Repository', $crm['mapping']['repository']['namespace']);
    }

    #[Test]
    public function itMakesTheFirstManagerTheDefaultOneUnlessNamed(): void
    {
        $managers = ['main' => ['endpoint' => 'https://main.example.com/graphql'], 'crm' => ['endpoint' => 'https://crm.example.com/graphql']];

        self::assertSame('main', $this->resolve(['managers' => $managers])->defaultManager);
        self::assertSame('crm', $this->resolve(['default_manager' => 'crm', 'managers' => $managers])->defaultManager);
    }

    #[Test]
    public function itRefusesADefaultManagerItDoesNotKnow(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"crm"');
        $this->resolve(['default_manager' => 'crm', 'managers' => ['main' => ['endpoint' => 'https://main.example.com/graphql']]]);
    }

    #[Test]
    public function itNeedsTheEndpointOfEveryManager(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"crm"');
        $this->resolve(['managers' => ['main' => ['endpoint' => 'https://main.example.com/graphql'], 'crm' => ['dialect' => HasuraDialect::class]]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolve(array $config): ManagerConfigs
    {
        return ManagerConfigs::fromProcessedConfiguration((new Processor())->processConfiguration(new Configuration(), [$config]));
    }
}
