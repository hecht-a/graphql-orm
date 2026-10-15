<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Metadata;

use GraphqlOrm\Attribute\Fetch;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\Legacy\LegacyTask;
use GraphqlOrm\Tests\Fixtures\Mapping\FieldThatIsAlsoARelation;
use GraphqlOrm\Tests\Fixtures\Mapping\IdentifierWithoutField;
use GraphqlOrm\Tests\Fixtures\Mapping\MappedTask;
use GraphqlOrm\Tests\Fixtures\Mapping\MappedUser;
use GraphqlOrm\Tests\Fixtures\Mapping\RelationToAValue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappingAttributesTest extends TestCase
{
    #[Test]
    public function itReadsTheIdentifierFromItsAttribute(): void
    {
        $metadata = (new GraphqlEntityMetadataFactory())->getMetadata(MappedUser::class);

        self::assertSame('id', $metadata->identifier?->property);
        self::assertSame('user_id', $metadata->identifier->mappedFrom);
    }

    #[Test]
    public function itReadsTheRelationsFromTheirAttribute(): void
    {
        $factory = new GraphqlEntityMetadataFactory();
        $task = $factory->getMetadata(MappedTask::class);
        $user = $factory->getMetadata(MappedUser::class);

        self::assertSame([MappedUser::class, 'user', Fetch::Lazy], [$task->fields[2]->relation, $task->fields[2]->mappedFrom, $task->fields[2]->fetch]);
        self::assertSame([MappedUser::class, 'reviewer', Fetch::Eager], [$task->fields[3]->relation, $task->fields[3]->mappedFrom, $task->fields[3]->fetch]);
        self::assertSame([MappedTask::class, true], [$user->fields[1]->relation, $user->fields[1]->isCollection]);
    }

    #[Test]
    public function itReportsNoDeprecationForTheMappingOfItsAttributes(): void
    {
        self::assertSame([], self::deprecations(fn () => (new GraphqlEntityMetadataFactory())->getMetadata(MappedTask::class)));
    }

    #[Test]
    public function itRejectsAnIdentifierWithoutField(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('#[GraphqlField]');

        (new GraphqlEntityMetadataFactory())->getMetadata(IdentifierWithoutField::class);
    }

    #[Test]
    public function itRejectsAFieldThatIsAlsoARelation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GraphqlEntityMetadataFactory())->getMetadata(FieldThatIsAlsoARelation::class);
    }

    #[Test]
    public function itRejectsARelationToAValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new GraphqlEntityMetadataFactory())->getMetadata(RelationToAValue::class);
    }

    #[Test]
    public function itStillReadsTheMappingOfVersion48AndReportsItsDeprecation(): void
    {
        $metadata = null;

        $deprecations = self::deprecations(function () use (&$metadata): void {
            $metadata = (new GraphqlEntityMetadataFactory())->getMetadata(LegacyTask::class);
        });

        self::assertNotNull($metadata);
        self::assertSame('id', $metadata->identifier?->property);
        self::assertSame([MappedUser::class, Fetch::Lazy], [$metadata->fields[1]->relation, $metadata->fields[1]->fetch]);
        self::assertSame([
            \sprintf('Since hecht-a/graphql-orm 4.9: The "identifier" argument of #[GraphqlField] on "%s::$id" is deprecated, add #[Identifier] instead.', LegacyTask::class),
            \sprintf('Since hecht-a/graphql-orm 4.9: Mapping the relation "%s::$user" with #[GraphqlField] is deprecated, use #[Relation] instead.', LegacyTask::class),
        ], $deprecations);
    }

    /**
     * The deprecations triggered by a call, silenced as trigger_deprecation() does.
     *
     * @return list<string>
     */
    private static function deprecations(callable $call): array
    {
        $messages = [];
        set_error_handler(function (int $type, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $call();
        } finally {
            restore_error_handler();
        }

        return $messages;
    }
}
