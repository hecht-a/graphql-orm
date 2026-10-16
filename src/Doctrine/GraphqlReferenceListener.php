<?php

declare(strict_types=1);

namespace GraphqlOrm\Doctrine;

use Doctrine\Persistence\Event\LifecycleEventArgs;
use Doctrine\Persistence\ObjectManager;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManagerInterface;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * Sets the #[GraphqlReference] properties of the Doctrine entities: on load, with a reference to the GraphQL entity
 * loaded on first access; before the first persist, the identifier column from the reference set. Doctrine sees no
 * change of a property it does not map: to change the reference of a stored entity, set its identifier too.
 */
final class GraphqlReferenceListener
{
    /**
     * @var array<class-string, list<array{\ReflectionProperty, \ReflectionProperty, class-string}>> the reference, the
     *                                                                                               identifier and the
     *                                                                                               target of each class
     */
    private array $references = [];

    public function __construct(
        private readonly GraphqlManagerInterface $manager,
        private readonly GraphqlEntityMetadataFactory $metadataFactory,
    ) {
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function postLoad(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        foreach ($this->references($entity::class) as [$reference, $identifier, $target]) {
            $value = $identifier->getValue($entity);

            if (\is_int($value) || \is_string($value)) {
                $reference->setValue($entity, $this->manager->getRepository($target)->getReference($value));
            }
        }
    }

    /**
     * @param LifecycleEventArgs<ObjectManager> $args
     */
    public function prePersist(LifecycleEventArgs $args): void
    {
        $entity = $args->getObject();

        foreach ($this->references($entity::class) as [$reference, $identifier, $target]) {
            $referenced = $reference->getValue($entity);

            if (!\is_object($referenced) || $identifier->getValue($entity) !== null) {
                continue;
            }

            // The identifier of a reference not loaded yet is set: reading it loads nothing.
            $identifier->setValue($entity, (new \ReflectionProperty($target, $this->identifierProperty($target)))->getValue($referenced));
        }
    }

    /**
     * @param class-string $class
     *
     * @return list<array{\ReflectionProperty, \ReflectionProperty, class-string}>
     */
    private function references(string $class): array
    {
        if (isset($this->references[$class])) {
            return $this->references[$class];
        }

        $references = [];

        foreach ((new \ReflectionClass($class))->getProperties() as $property) {
            foreach ($property->getAttributes(GraphqlReference::class) as $attribute) {
                $mapping = $attribute->newInstance();
                $references[] = [$property, new \ReflectionProperty($class, $mapping->identifier), $mapping->targetEntity ?? self::targetOf($property)];
            }
        }

        return $this->references[$class] = $references;
    }

    /**
     * @return class-string
     *
     * @throws InvalidArgumentException when the type of the property names no class
     */
    private static function targetOf(\ReflectionProperty $property): string
    {
        $type = $property->getType();

        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin() && class_exists($type->getName())) {
            return $type->getName();
        }

        throw new InvalidArgumentException(\sprintf('"%s::$%s" is a #[GraphqlReference] whose type is no class: give its targetEntity.', $property->class, $property->getName()));
    }

    /**
     * @param class-string $target
     */
    private function identifierProperty(string $target): string
    {
        return $this->metadataFactory->getMetadata($target)->identifier->property
            ?? throw new InvalidArgumentException(\sprintf('"%s" has no identifier: it cannot be referenced from a Doctrine entity.', $target));
    }
}
