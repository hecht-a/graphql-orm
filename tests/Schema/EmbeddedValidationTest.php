<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\Embedded\Order;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fields of an embeddable exist on the GraphQL object of a nested one, on the type of the entity for prefixed ones.
 */
final class EmbeddedValidationTest extends TestCase
{
    #[Test]
    public function itAcceptsTheFieldsOfTheEmbeddables(): void
    {
        self::assertSame([], $this->validate(self::types()));
    }

    #[Test]
    public function itReportsAMissingFieldOfAnEmbeddable(): void
    {
        $types = self::types(without: ['total_currency', 'currency']);

        self::assertSame([
            '[GraphqlOrm\Tests\Fixtures\Embedded\Order] Field "price.currency" (mapped from "currency") does not exist on GraphQL type "Price".',
            '[GraphqlOrm\Tests\Fixtures\Embedded\Order] Field "total.currency" (mapped from "total_currency") does not exist on GraphQL type "Order".',
        ], $this->validate($types));
    }

    #[Test]
    public function itReportsAScalarOfAnEmbeddableIncompatibleWithItsProperty(): void
    {
        $types = self::types(scalars: ['total_amount' => 'Boolean']);

        self::assertSame(
            ['[GraphqlOrm\Tests\Fixtures\Embedded\Order] Field "total_amount" has PHP type "int" but GraphQL scalar is "Boolean" (expected one of: Int, Byte, Short, Long, bigint, smallint, integer, ID).'],
            $this->validate($types),
        );
    }

    /**
     * @param list<string>          $without fields left out of the schema
     * @param array<string, string> $scalars the scalar of some fields, by name
     *
     * @return array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}>
     */
    private static function types(array $without = [], array $scalars = []): array
    {
        $types = [
            'Order' => ['kind' => 'OBJECT', 'fields' => [
                'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
                'price' => ['kind' => 'OBJECT', 'name' => 'Price'],
                'total_amount' => ['kind' => 'SCALAR', 'name' => 'Int'],
                'total_currency' => ['kind' => 'SCALAR', 'name' => 'String'],
            ]],
            'Price' => ['kind' => 'OBJECT', 'fields' => [
                'amount' => ['kind' => 'SCALAR', 'name' => 'Int'],
                'currency' => ['kind' => 'SCALAR', 'name' => 'String'],
            ]],
        ];

        foreach ($types as $name => $type) {
            $fields = array_diff_key($type['fields'], array_flip($without));

            foreach (array_intersect_key($scalars, $fields) as $field => $scalar) {
                $fields[$field] = ['kind' => 'SCALAR', 'name' => $scalar];
            }

            $types[$name]['fields'] = $fields;
        }

        return $types;
    }

    /**
     * @param array<string, array{kind: string, fields: array<string, array{kind: string, name: string|null}>}> $types
     *
     * @return string[]
     */
    private function validate(array $types): array
    {
        return (new SchemaValidator(new GraphqlEntityMetadataFactory()))->validate([Order::class], $types);
    }
}
