<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Metadata;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Mapping\Fetch;
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
        $this->expectExceptionMessage('#[Field]');

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
        self::assertContains(\sprintf('Since hecht-a/graphql-orm 4.9: The mapping of "%s" uses #[GraphqlEntity] or #[GraphqlField], use #[Entity] and #[Field] of GraphqlOrm\Mapping instead.', LegacyTask::class), $deprecations);
        self::assertContains(\sprintf('Since hecht-a/graphql-orm 4.9: The "identifier" argument of #[GraphqlField] on "%s::$id" is deprecated, add #[Identifier] instead.', LegacyTask::class), $deprecations);
        self::assertContains(\sprintf('Since hecht-a/graphql-orm 4.9: Mapping the relation "%s::$user" with #[GraphqlField] is deprecated, use #[Relation] instead.', LegacyTask::class), $deprecations);
    }

    #[Test]
    public function itKeepsTheFetchEnumOfVersion48AsADeprecatedAlias(): void
    {
        // In a PHP process of its own: the alias reports its deprecation when first loaded only.
        $script = \sprintf(
            'require %s; set_error_handler(function (int $type, string $message): bool { echo $message, "\\n"; return true; }, E_USER_DEPRECATED); echo \\GraphqlOrm\\Attribute\\Fetch::Lazy === \\GraphqlOrm\\Mapping\\Fetch::Lazy ? "same enum" : "other enum";',
            var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true),
        );

        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -r ' . escapeshellarg($script));

        self::assertStringContainsString('Since hecht-a/graphql-orm 4.9: The "GraphqlOrm\\Attribute\\Fetch" enum is deprecated, use "GraphqlOrm\\Mapping\\Fetch" instead.', $output);
        self::assertStringContainsString('same enum', $output);
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
