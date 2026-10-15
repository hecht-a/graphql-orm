<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * A metadata factory serving hand-written metadata instead of reading the attributes of their classes.
 */
final class FakeMetadataFactory
{
    public static function with(GraphqlEntityMetadata ...$metadata): GraphqlEntityMetadataFactory
    {
        $factory = new GraphqlEntityMetadataFactory();
        $loaded = [];

        foreach ($metadata as $entityMetadata) {
            $loaded[$entityMetadata->class] = $entityMetadata;
        }

        // The metadata already loaded are served as is.
        (new \ReflectionProperty(GraphqlEntityMetadataFactory::class, 'loaded'))->setValue($factory, $loaded);

        return $factory;
    }
}
