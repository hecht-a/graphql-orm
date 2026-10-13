<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Expr;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;

/**
 * A filter whose fields, named after their GraphQL name or their PHP property, are given their GraphQL name.
 *
 * @internal
 */
final readonly class GraphqlNamedFilter implements FilterExpressionInterface
{
    private const LOGICAL_OPERATORS = ['and', 'or'];

    public function __construct(
        private FilterExpressionInterface $filter,
        private GraphqlEntityMetadata $metadata,
    ) {
    }

    public function toArray(): array
    {
        return $this->rename($this->filter->toArray());
    }

    /**
     * The fields a filter names, as written.
     *
     * @param array<mixed> $filter `{field: {operator: value}}` or `{and|or: [filter, ...]}`
     *
     * @return list<string>
     */
    public static function fieldNames(array $filter): array
    {
        $names = [];

        foreach ($filter as $key => $value) {
            $key = (string) $key;

            if (\in_array($key, self::LOGICAL_OPERATORS, true) && \is_array($value)) {
                foreach ($value as $operand) {
                    $names = [...$names, ...(\is_array($operand) ? self::fieldNames($operand) : [])];
                }

                continue;
            }

            $names[] = $key;
        }

        return $names;
    }

    /**
     * @param array<mixed> $filter `{field: {operator: value}}` or `{and|or: [filter, ...]}`
     *
     * @return array<string, mixed>
     */
    private function rename(array $filter): array
    {
        $renamed = [];

        foreach ($filter as $key => $value) {
            $key = (string) $key;

            if (\in_array($key, self::LOGICAL_OPERATORS, true) && \is_array($value)) {
                $renamed[$key] = array_map(fn (mixed $operand) => \is_array($operand) ? $this->rename($operand) : $operand, $value);

                continue;
            }

            $renamed[$this->metadata->graphqlName($key)] = $value;
        }

        return $renamed;
    }
}
