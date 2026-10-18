<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Schema;

use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\Converter\CentsConverter;
use GraphqlOrm\Tests\Fixtures\Converter\EmailConverter;
use GraphqlOrm\Tests\Fixtures\Converter\MoneyConverter;
use GraphqlOrm\Tests\Fixtures\Converter\Product;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A property typed with a value object needs a type converter, whose scalars, when it tells them, the schema must use.
 */
final class TypeConverterValidationTest extends TestCase
{
    #[Test]
    public function itAcceptsTheValueObjectsGivenByAConverter(): void
    {
        self::assertSame([], $this->validate([new MoneyConverter(), new EmailConverter()], price: 'Int'));
    }

    #[Test]
    public function itReportsAValueObjectWithoutConverter(): void
    {
        self::assertSame([
            '[GraphqlOrm\Tests\Fixtures\Converter\Product] Field "price" has PHP type "GraphqlOrm\Tests\Fixtures\Converter\Money", which no type converter converts: register one with #[AsTypeConverter(Money::class)], or name it with #[GraphqlOrm\\Field(converter: ...)].',
            '[GraphqlOrm\Tests\Fixtures\Converter\Product] Field "contact" has PHP type "GraphqlOrm\Tests\Fixtures\Converter\Email", which no type converter converts: register one with #[AsTypeConverter(Email::class)], or name it with #[GraphqlOrm\\Field(converter: ...)].',
        ], $this->validate([], price: 'Int'));
    }

    #[Test]
    public function itChecksTheScalarsAConverterTells(): void
    {
        self::assertSame(
            ['[GraphqlOrm\Tests\Fixtures\Converter\Product] Field "price" is converted by GraphqlOrm\Tests\Fixtures\Converter\CentsConverter from Int, but GraphQL scalar is "Float".'],
            $this->validate([new CentsConverter(), new EmailConverter()], price: 'Float'),
        );
        self::assertSame([], $this->validate([new CentsConverter(), new EmailConverter()], price: 'Int'));
    }

    /**
     * @param list<\GraphqlOrm\Converter\TypeConverterInterface> $converters
     *
     * @return string[]
     */
    private function validate(array $converters, string $price): array
    {
        $types = ['Product' => ['kind' => 'OBJECT', 'fields' => [
            'id' => ['kind' => 'SCALAR', 'name' => 'Int'],
            'name' => ['kind' => 'SCALAR', 'name' => 'String'],
            'price' => ['kind' => 'SCALAR', 'name' => $price],
            'discount' => ['kind' => 'SCALAR', 'name' => 'Float'],
            'contact' => ['kind' => 'SCALAR', 'name' => 'String'],
        ]]];

        return (new SchemaValidator(new GraphqlEntityMetadataFactory(), new TypeConverterRegistry($converters)))->validate([Product::class], $types);
    }
}
