<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;

/**
 * @internal
 */
final readonly class SchemaIntrospector
{
    private const INTROSPECTION_QUERY = <<<'GQL'
        query IntrospectionQuery {
            __schema {
                queryType {
                    name
                }
                types {
                    name
                    kind
                    fields(includeDeprecated: true) {
                        name
                        type {
                            name
                            kind
                            ofType {
                                name
                                kind
                                ofType {
                                    name
                                    kind
                                    ofType {
                                        name
                                        kind
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
    ) {
    }

    private const DEFAULT_QUERY_TYPE = 'Query';

    /**
     * @return array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}>
     */
    public function introspect(): array
    {
        return $this->introspectSchema()['types'];
    }

    /**
     * @return array{
     *   queryType: string,
     *   types: array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}>
     * }
     */
    public function introspectSchema(): array
    {
        $context = new GraphqlExecutionContext();

        $result = $this->client->query(self::INTROSPECTION_QUERY, $context);

        $data = $result['data'] ?? null;

        if (!\is_array($data)) {
            throw new InvalidGraphqlResponseException('Introspection query returned an unexpected response.');
        }

        $schema = $data['__schema'] ?? null;

        if (!\is_array($schema)) {
            throw new InvalidGraphqlResponseException('Introspection response is missing "__schema".');
        }

        $types = $schema['types'] ?? null;

        if (!\is_array($types)) {
            throw new InvalidGraphqlResponseException('Introspection response is missing "types".');
        }

        $queryType = \is_array($schema['queryType'] ?? null) ? $schema['queryType']['name'] ?? null : null;

        $map = [];

        foreach ($types as $type) {
            if (!\is_array($type)) {
                continue;
            }

            $name = $type['name'] ?? null;
            $kind = $type['kind'] ?? null;

            if (!\is_string($name) || !\is_string($kind)) {
                continue;
            }

            // Skip built-in introspection types
            if (str_starts_with($name, '__')) {
                continue;
            }

            $fields = [];

            $rawFields = $type['fields'] ?? [];

            if (\is_array($rawFields)) {
                foreach ($rawFields as $field) {
                    if (!\is_array($field)) {
                        continue;
                    }

                    $fieldName = $field['name'] ?? null;

                    if (!\is_string($fieldName)) {
                        continue;
                    }

                    $rawType = $field['type'] ?? [];
                    $fields[$fieldName] = $this->resolveFieldType(\is_array($rawType) ? $rawType : []);
                }
            }

            $map[$name] = [
                'kind' => $kind,
                'fields' => $fields,
            ];
        }

        return [
            'queryType' => \is_string($queryType) ? $queryType : self::DEFAULT_QUERY_TYPE,
            'types' => $map,
        ];
    }

    /**
     * @param array<mixed> $type
     *
     * @return array{kind: string, name: string|null}
     */
    private function resolveFieldType(array $type): array
    {
        $current = $type;

        while (true) {
            $kind = $current['kind'] ?? null;

            if (!\is_string($kind) || ($kind !== 'NON_NULL' && $kind !== 'LIST')) {
                break;
            }

            $ofType = $current['ofType'] ?? [];
            $current = \is_array($ofType) ? $ofType : [];
        }

        $kind = $current['kind'] ?? null;
        $name = $current['name'] ?? null;

        return [
            'kind' => \is_string($kind) ? $kind : 'UNKNOWN',
            'name' => \is_string($name) ? $name : null,
        ];
    }
}
