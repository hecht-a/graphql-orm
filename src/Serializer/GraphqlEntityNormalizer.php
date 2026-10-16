<?php

declare(strict_types=1);

namespace GraphqlOrm\Serializer;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizes the entities whose fields are not all loaded, without loading them: a relation loaded on first access
 * (Fetch::Lazy, lazy_loading) gives its identifier, a partial entity its selected fields. Reading them through the
 * other normalizers would send a query per entity, or fail on the fields not selected. The entities held in full are
 * left to the other normalizers.
 */
final class GraphqlEntityNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    /**
     * @param EntityHydrator|null $hydrator tells the fields a partial entity was hydrated without, even those keeping a
     *                                      default value
     */
    public function __construct(
        private readonly GraphqlEntityMetadataFactory $metadataFactory,
        private readonly ?EntityHydrator $hydrator = null,
        private readonly ?GraphqlOrmDataCollector $collector = null,
        private readonly ?GraphqlManagerRegistry $managers = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> the mapped properties already loaded
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert(\is_object($data));
        $values = [];
        $unselected = $this->unselectedProperties($data);
        $this->collector?->entityNormalized($data::class, $unselected !== [] ? 'partial' : 'identifier');

        foreach ($this->metadataFactory->getMetadata($data::class)->fields as $field) {
            $property = new \ReflectionProperty($data, $field->property);

            if ($property->isLazy($data) || \in_array($field->property, $unselected, true)) {
                continue;
            }

            // A property already set is read without initializing the lazy object.
            $value = $property->getValue($data);
            $values[$field->property] = $value === null || \is_scalar($value) ? $value : $this->normalizer->normalize($value, $format, $context);
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return \is_object($data)
            && $this->metadataFactory->isEntityClass($data::class)
            && ((new \ReflectionClass($data))->isUninitializedLazyObject($data) || $this->unselectedProperties($data) !== []);
    }

    /**
     * The mapped properties the query hydrating the entity did not select, told by the hydrator of the manager of its API.
     *
     * @return list<string>
     */
    private function unselectedProperties(object $entity): array
    {
        $hydrator = $this->managers?->getManagerForClass($entity::class)->getHydrator() ?? $this->hydrator;

        return $hydrator?->unselectedProperties($entity) ?? [];
    }

    public function getSupportedTypes(?string $format): array
    {
        // Whether an entity is supported depends on its state: the answer cannot be cached by type.
        return ['object' => false];
    }
}
