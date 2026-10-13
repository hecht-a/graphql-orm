<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SchemaIntrospectorWrappersTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, bool, bool}>
     */
    public static function wrappedTypes(): iterable
    {
        $named = fn (string $kind, string $name) => ['kind' => $kind, 'name' => $name, 'ofType' => null];
        $wrapped = fn (string $kind, array $ofType) => ['kind' => $kind, 'name' => null, 'ofType' => $ofType];

        yield 'String' => [$named('SCALAR', 'String'), true, false];
        yield 'Int!' => [$wrapped('NON_NULL', $named('SCALAR', 'Int')), false, false];
        yield '[String]' => [$wrapped('LIST', $named('SCALAR', 'String')), true, true];
        yield '[Task!]!' => [$wrapped('NON_NULL', $wrapped('LIST', $wrapped('NON_NULL', $named('OBJECT', 'Task')))), false, true];
    }

    /**
     * @param array<string, mixed> $type
     */
    #[Test]
    #[DataProvider('wrappedTypes')]
    public function itTellsWhetherAFieldIsNullableAndAList(array $type, bool $nullable, bool $list): void
    {
        $client = new FakeGraphqlClient(['data' => ['__schema' => ['types' => [
            ['name' => 'Task', 'kind' => 'OBJECT', 'fields' => [['name' => 'field', 'type' => $type]]],
        ]]]]);

        $field = (new SchemaIntrospector($client))->introspect()['Task']['fields']['field'];

        self::assertSame($nullable, $field['nullable']);
        self::assertSame($list, $field['list']);
    }
}
