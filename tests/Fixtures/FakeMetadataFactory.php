<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Fixtures;

use GraphqlOrm\Exception\NotAGraphqlEntityException;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * Serves hand-written metadata instead of reading attributes. A single metadata is served for any class.
 */
final class FakeMetadataFactory extends GraphqlEntityMetadataFactory
{
    /** @var array<string, GraphqlEntityMetadata> */
    private array $metadata = [];

    public function __construct(GraphqlEntityMetadata ...$metadata)
    {
        parent::__construct();

        foreach ($metadata as $entityMetadata) {
            $this->metadata[$entityMetadata->class] = $entityMetadata;
        }
    }

    public function getMetadata(string $class): GraphqlEntityMetadata
    {
        if (isset($this->metadata[$class])) {
            return $this->metadata[$class];
        }

        if (\count($this->metadata) === 1) {
            return reset($this->metadata);
        }

        throw NotAGraphqlEntityException::forClass($class);
    }
}
