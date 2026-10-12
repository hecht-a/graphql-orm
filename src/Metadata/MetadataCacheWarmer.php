<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Exception\NotAGraphqlEntityException;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

/**
 * Builds the metadata of every entity during `cache:warmup`, so that no request pays for reading attributes and
 * docblocks.
 *
 * @internal
 */
final readonly class MetadataCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param GraphqlEntityMetadataFactory<object> $metadataFactory
     * @param class-string[]                       $entityClasses   classes found in the entity directory
     */
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
        private array $entityClasses,
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        foreach ($this->entityClasses as $class) {
            if (!class_exists($class)) {
                continue;
            }

            try {
                $this->metadataFactory->getMetadata($class);
            } catch (NotAGraphqlEntityException) {
                // The entity directory may hold other classes (enums, value objects): they have no metadata.
            }
        }

        return [];
    }
}
