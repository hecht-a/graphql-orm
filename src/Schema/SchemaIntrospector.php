<?php

declare(strict_types=1);

namespace GraphqlOrm\Schema;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\InvalidGraphqlResponseException;

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
        private ?GraphqlOrmDataCollector $collector = null,
    ) {
    }

    private const DEFAULT_QUERY_TYPE = 'Query';

    /**
     * @return array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null, nullable: bool, list: bool}>}>
     */
    public function introspect(): array
    {
        return $this->introspectSchema()['types'];
    }

    /**
     * @return array{
     *   queryType: string,
     *   types: array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null, nullable: bool, list: bool}>}>
     * }
     */
    public function introspectSchema(): array
    {
        $result = IntrospectionQuery::send($this->client, self::INTROSPECTION_QUERY, $this->collector);

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
     * Unwraps the NON_NULL and LIST wrappers down to the named type, recording whether the field may be null and whether
     * it is a list.
     *
     * @param array<mixed> $type
     *
     * @return array{kind: string, name: string|null, nullable: bool, list: bool}
     */
    private function resolveFieldType(array $type): array
    {
        $current = $type;
        $nullable = ($current['kind'] ?? null) !== 'NON_NULL';
        $list = false;

        while (true) {
            $kind = $current['kind'] ?? null;

            if (!\is_string($kind) || ($kind !== 'NON_NULL' && $kind !== 'LIST')) {
                break;
            }

            $list = $list || $kind === 'LIST';
            $ofType = $current['ofType'] ?? [];
            $current = \is_array($ofType) ? $ofType : [];
        }

        $kind = $current['kind'] ?? null;
        $name = $current['name'] ?? null;

        return [
            'kind' => \is_string($kind) ? $kind : 'UNKNOWN',
            'name' => \is_string($name) ? $name : null,
            'nullable' => $nullable,
            'list' => $list,
        ];
    }
}
