<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests;

use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FinalClassesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function classes(): iterable
    {
        yield 'manager, simulated or decorated through GraphqlManagerInterface' => [GraphqlManager::class];
        yield 'hydrator' => [EntityHydrator::class];
        yield 'metadata factory' => [GraphqlEntityMetadataFactory::class];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('classes')]
    public function itCannotBeExtended(string $class): void
    {
        self::assertTrue((new \ReflectionClass($class))->isFinal());
    }
}
