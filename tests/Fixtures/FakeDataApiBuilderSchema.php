<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

/**
 * Two entities as Data API Builder describes them: a connection per root field, the fields of the entity type, and the
 * enumeration of the fields an aggregation accepts. "labels" can be counted on "position" or "weight", "notes" on no
 * field, its only numeric field being nullable.
 */
final class FakeDataApiBuilderSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function introspectionResponse(): array
    {
        $named = fn (string $kind, string $name) => ['kind' => $kind, 'name' => $name, 'ofType' => null];
        $nonNull = fn (string $kind, string $name) => ['kind' => 'NON_NULL', 'name' => null, 'ofType' => $named($kind, $name)];
        $field = fn (string $name, array $type) => ['name' => $name, 'type' => $type];
        $enum = fn (string $name, string ...$values) => [
            'name' => $name,
            'kind' => 'ENUM',
            'fields' => null,
            'enumValues' => array_map(fn (string $value) => ['name' => $value], $values),
        ];

        return ['data' => ['__schema' => [
            'queryType' => ['fields' => [
                $field('labels', $nonNull('OBJECT', 'LabelConnection')),
                $field('label_by_pk', $named('OBJECT', 'Label')),
                $field('notes', $nonNull('OBJECT', 'NoteConnection')),
            ]],
            'types' => [
                ['name' => 'Label', 'kind' => 'OBJECT', 'enumValues' => null, 'fields' => [
                    $field('code', $named('SCALAR', 'String')),
                    $field('name', $nonNull('SCALAR', 'String')),
                    $field('rank', $named('SCALAR', 'Int')),
                    $field('position', $nonNull('SCALAR', 'Int')),
                    $field('weight', $nonNull('SCALAR', 'Float')),
                ]],
                $enum('LabelNumericAggregateFields', 'rank', 'position', 'weight'),
                ['name' => 'Note', 'kind' => 'OBJECT', 'enumValues' => null, 'fields' => [
                    $field('id', $nonNull('SCALAR', 'String')),
                    $field('score', $named('SCALAR', 'Float')),
                ]],
                $enum('NoteNumericAggregateFields', 'score'),
            ],
        ]]];
    }
}
