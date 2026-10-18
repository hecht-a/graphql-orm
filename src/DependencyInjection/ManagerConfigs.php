<?php

declare(strict_types=1);

namespace GraphqlOrm\DependencyInjection;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * The options of each manager: those of the root, replaced by the ones the manager sets, nested options merged key by
 * key. Without managers, the root is the configuration of the manager "default".
 *
 * @phpstan-type ManagerOptions array{
 *   endpoint: string,
 *   http_client_options: array{verify_host: bool, verify_peer: bool},
 *   redact_query_values: bool,
 *   variables: array{enabled: bool, cache_pool: string, ttl: int},
 *   http_client: string|null,
 *   dialect: string,
 *   headers: array<string, string>,
 *   metadata_cache_pool: string|null,
 *   result_cache: array{pool: string|null},
 *   second_level_cache: array{enabled: bool, pool: string, default_lifetime: int, regions: array<string, array{pool: string|null, lifetime: int|null}>},
 *   naming_strategy: string,
 *   slow_query_threshold: int|float|null,
 *   identity_map: bool,
 *   headers_provider: string|null,
 *   lazy_loading: bool,
 *   max_depth: int,
 *   schema_validation: array{mode: string|null},
 *   mapping: array{
 *     entity: array{dir: string, namespace: string},
 *     repository: array{dir: string, namespace: string}
 *   }
 * }
 *
 * @internal
 */
final readonly class ManagerConfigs
{
    public const DEFAULT_MANAGER = 'default';

    /**
     * @param array<string, ManagerOptions> $managers the options of each manager, by name
     */
    private function __construct(
        public string $defaultManager,
        public array $managers,
    ) {
    }

    /**
     * @param array<mixed> $config the configuration processed by Configuration
     *
     * @throws InvalidConfigurationException when a manager has no endpoint, or the default manager is unknown
     */
    public static function fromProcessedConfiguration(array $config): self
    {
        /** @var array<string, array<string, mixed>> $declared */
        $declared = \is_array($config['managers'] ?? null) ? $config['managers'] : [];
        $defaultManager = $config['default_manager'] ?? null;
        unset($config['managers'], $config['default_manager'], $config['profiler']);

        $managers = $declared === [] ? [self::DEFAULT_MANAGER => $config] : [];

        foreach ($declared as $name => $options) {
            $managers[$name] = self::merge($config, $options);
        }

        foreach ($managers as $name => $options) {
            if (!\is_string($options['endpoint'] ?? null) || $options['endpoint'] === '') {
                throw new InvalidConfigurationException(\sprintf('The GraphQL ORM manager "%s" has no endpoint: set graphql_orm.managers.%1$s.endpoint, or graphql_orm.endpoint for every manager.', $name));
            }
        }

        $defaultManager = \is_string($defaultManager) ? $defaultManager : (string) array_key_first($managers);

        if (!isset($managers[$defaultManager])) {
            throw new InvalidConfigurationException(\sprintf('The default GraphQL ORM manager "%s" is not one of graphql_orm.managers: %s.', $defaultManager, implode(', ', array_keys($managers))));
        }

        /** @var array<string, ManagerOptions> $managers every option set, by the root at least */
        return new self($defaultManager, $managers);
    }

    /**
     * @param array<mixed> $root
     * @param array<mixed> $manager
     *
     * @return array<mixed>
     */
    private static function merge(array $root, array $manager): array
    {
        foreach ($manager as $key => $value) {
            $root[$key] = \is_array($value) && \is_array($root[$key] ?? null) && !array_is_list($root[$key])
                ? self::merge($root[$key], $value)
                : $value;
        }

        return $root;
    }
}
