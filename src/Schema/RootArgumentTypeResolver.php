<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Knows the GraphQL type of each argument of the root query fields, so they can be declared as variables.
 * The introspection result is cached; a failed introspection is cached as well, so that an API forbidding
 * introspection is not queried on every request.
 *
 * @internal
 */
final readonly class RootArgumentTypeResolver
{
    private const CACHE_KEY = 'graphql_orm.root_argument_types';

    private const INTROSPECTION_QUERY = <<<'GQL'
        query RootArgumentTypes {
            __schema {
                queryType {
                    fields {
                        name
                        args {
                            name
                            type {
                                kind
                                name
                                ofType {
                                    kind
                                    name
                                    ofType {
                                        kind
                                        name
                                        ofType {
                                            kind
                                            name
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;

    public function __construct(
        private GraphqlClientInterface $client,
        private CacheInterface $cache,
        private int $ttl,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, string>|null argument name => GraphQL type (e.g. `[ID!]!`), null when unknown
     */
    public function argumentTypes(string $rootField): ?array
    {
        $fields = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): ?array {
            $item->expiresAfter($this->ttl);

            return $this->introspect();
        });

        if (!\is_array($fields)) {
            return null;
        }

        $types = $fields[$rootField] ?? null;

        /** @var array<string, string>|null $types */
        return \is_array($types) ? $types : null;
    }

    /**
     * @return array<string, array<string, string>>|null
     */
    private function introspect(): ?array
    {
        try {
            $result = $this->client->query(self::INTROSPECTION_QUERY, new GraphqlExecutionContext());
        } catch (GraphqlOrmException $e) {
            $this->warn($e->getMessage());

            return null;
        }

        $data = $result['data'] ?? null;
        $fields = \is_array($data) ? ($data['__schema']['queryType']['fields'] ?? null) : null;

        if (!\is_array($fields)) {
            $this->warn('the response does not describe the root query type');

            return null;
        }

        $types = [];

        foreach ($fields as $field) {
            if (\is_array($field) && \is_string($field['name'] ?? null)) {
                $types[$field['name']] = $this->argumentTypesOf($field['args'] ?? []);
            }
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    private function argumentTypesOf(mixed $args): array
    {
        $types = [];

        foreach (\is_array($args) ? $args : [] as $arg) {
            $type = \is_array($arg) ? $this->printType($arg['type'] ?? null) : null;

            if ($type !== null && \is_string($arg['name'] ?? null)) {
                $types[$arg['name']] = $type;
            }
        }

        return $types;
    }

    private function printType(mixed $type): ?string
    {
        if (!\is_array($type)) {
            return null;
        }

        $inner = $this->printType($type['ofType'] ?? null);

        return match ($type['kind'] ?? null) {
            'NON_NULL' => $inner !== null ? $inner . '!' : null,
            'LIST' => $inner !== null ? '[' . $inner . ']' : null,
            default => \is_string($type['name'] ?? null) ? $type['name'] : null,
        };
    }

    private function warn(string $reason): void
    {
        $this->logger?->warning('GraphQL ORM: introspection of root argument types failed, values are sent inline: ' . $reason);
    }
}
