<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests;

use GraphqlOrm\Client\GraphqlClient;
use GraphqlOrm\Codegen\PropertyDefinition;
use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\DebugEntityCommand;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\Command\SchemaValidateCommand;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\DependencyInjection\Configuration;
use GraphqlOrm\DependencyInjection\GraphqlOrmExtension;
use GraphqlOrm\EventListener\SchemaValidationListener;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Metadata\MetadataCacheWarmer;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryStringBuilder;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guards the 3.x public API: what is not meant to be extended is final, what is not meant to be used is internal.
 */
final class PublicApiTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function implementationClasses(): iterable
    {
        foreach ([
            GraphqlClient::class,
            GraphqlQueryCompiler::class,
            GraphqlOrmDataCollector::class,
            GraphqlExecutionContext::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function internalClasses(): iterable
    {
        foreach ([
            GraphqlQueryCompiler::class,
            GraphqlQueryStringBuilder::class,
            EntityHydrator::class,
            GraphqlEntityMetadataFactory::class,
            GraphqlEntityMetadata::class,
            GraphqlFieldMetadata::class,
            MetadataCacheWarmer::class,
            QueryRedactor::class,
            RootArgumentTypeResolver::class,
            SchemaIntrospector::class,
            GraphqlSchema::class,
            SchemaValidator::class,
            SchemaValidationListener::class,
            StubRenderer::class,
            SchemaPropertyMapper::class,
            PropertyDefinition::class,
            DebugEntityCommand::class,
            MakeGraphqlEntityCommand::class,
            SchemaValidateCommand::class,
            GraphqlOrmDataCollector::class,
            Configuration::class,
            GraphqlOrmExtension::class,
        ] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('implementationClasses')]
    public function itClosesImplementationClassesToInheritance(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        $isFinal = $reflection->isFinal();

        self::assertTrue($isFinal, $class . ' should be final.');
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('internalClasses')]
    public function itMarksImplementationDetailsAsInternal(string $class): void
    {
        $reflection = new \ReflectionClass($class);

        $docComment = (string) $reflection->getDocComment();

        self::assertStringContainsString('@internal', $docComment, $class . ' should be marked @internal.');
    }

    #[Test]
    public function itDoesNotExposeTheManagerDependencies(): void
    {
        $reflection = new \ReflectionClass(GraphqlManager::class);

        $publicProperties = array_map(
            fn (\ReflectionProperty $property) => $property->getName(),
            $reflection->getProperties(\ReflectionProperty::IS_PUBLIC),
        );

        self::assertSame([], $publicProperties);
    }
}
