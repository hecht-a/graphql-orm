<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Metadata\Naming;

use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityNamedByStrategy;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeUser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NamingStrategyTest extends TestCase
{
    #[Test]
    public function itNamesTheFieldsAfterTheirPropertyByDefault(): void
    {
        $metadata = (new GraphqlEntityMetadataFactory())->getMetadata(FakeEntityNamedByStrategy::class);

        self::assertSame(['id', 'title', 'dueDate', 'deadline_at', 'assignedUser'], self::names($metadata->fields));
        self::assertSame('id', $metadata->identifier?->mappedFrom);
        self::assertSame(FakeUser::class, $metadata->fields[4]->relation);
    }

    #[Test]
    public function itNamesTheFieldsInSnakeCaseWithTheSnakeCaseStrategy(): void
    {
        $metadata = (new GraphqlEntityMetadataFactory(namingStrategy: new SnakeCaseNamingStrategy()))->getMetadata(FakeEntityNamedByStrategy::class);

        self::assertSame(['id', 'title', 'due_date', 'deadline_at', 'assigned_user'], self::names($metadata->fields));
    }

    #[Test]
    public function itTurnsPropertyNamesIntoSnakeCase(): void
    {
        $strategy = new SnakeCaseNamingStrategy();

        self::assertSame(
            ['id', 'due_date', 'created_by_user_id', 'html_content'],
            array_map($strategy->fieldName(...), ['id', 'dueDate', 'createdByUserId', 'htmlContent']),
        );
    }

    #[Test]
    public function itKeepsThePropertyNameWithTheDefaultStrategy(): void
    {
        self::assertSame('dueDate', (new DefaultNamingStrategy())->fieldName('dueDate'));
    }

    /**
     * @param GraphqlFieldMetadata[] $fields
     *
     * @return list<string>
     */
    private static function names(array $fields): array
    {
        return array_values(array_map(fn (GraphqlFieldMetadata $field) => $field->mappedFrom, $fields));
    }
}
