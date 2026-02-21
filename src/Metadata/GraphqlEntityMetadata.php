<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

final readonly class GraphqlEntityMetadata
{
    /**
     * @param GraphqlFieldMetadata[] $fields
     */
    public function __construct(
        public string $class,
        public string $name,
        public ?string $repositoryClass,
        public array $fields,
        public ?GraphqlFieldMetadata $identifier = null,
    ) {
    }
}
