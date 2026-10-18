<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Mapping\MapFrom;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolverInterface;

/**
 * Maps the parameters of the constructor of a DTO to the fields of an entity: by their name, or by the path of their
 * #[MapFrom]. A parameter typed with a class maps a relation to a nested DTO, an array typed list<Dto> in a docblock a
 * collection.
 *
 * @internal
 */
final class DtoMetadataFactory
{
    private readonly TypeResolverInterface $typeResolver;

    /** @var array<string, DtoMetadata> by DTO and entity */
    private array $loaded = [];

    public function __construct(
        private readonly GraphqlEntityMetadataFactory $metadataFactory,
        ?TypeResolverInterface $typeResolver = null,
    ) {
        $this->typeResolver = $typeResolver ?? TypeResolver::create();
    }

    /**
     * @param class-string $dtoClass
     * @param class-string $entityClass
     * @param list<string> $nesting     the DTOs being mapped, to refuse a DTO nesting itself
     *
     * @throws InvalidArgumentException when a parameter maps no field of the entity, or a relation without a DTO
     */
    public function getMetadata(string $dtoClass, string $entityClass, array $nesting = []): DtoMetadata
    {
        $key = $dtoClass . '|' . $entityClass;

        if (isset($this->loaded[$key])) {
            return $this->loaded[$key];
        }

        if ($this->metadataFactory->isEntityClass($dtoClass)) {
            throw new InvalidArgumentException(\sprintf('"%s" is an entity: hydrateAs() creates DTOs, query the entity without it.', $dtoClass));
        }

        if (\in_array($dtoClass, $nesting, true)) {
            throw new InvalidArgumentException(\sprintf('"%s" nests itself through %s: a DTO cannot hold itself.', $dtoClass, implode(', ', $nesting)));
        }

        $constructor = (new \ReflectionClass($dtoClass))->getConstructor();
        $parameters = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $parameters[] = $this->parameter($dtoClass, $entityClass, $parameter, [...$nesting, $dtoClass]);
        }

        return $this->loaded[$key] = new DtoMetadata($dtoClass, $parameters);
    }

    /**
     * @param class-string $dtoClass
     * @param class-string $entityClass
     * @param list<string> $nesting
     */
    private function parameter(string $dtoClass, string $entityClass, \ReflectionParameter $parameter, array $nesting): DtoParameterMetadata
    {
        $mapFrom = ($parameter->getAttributes(MapFrom::class)[0] ?? null)?->newInstance();
        $path = $mapFrom->path ?? $parameter->getName();
        $names = explode('.', $path);
        $graphqlPath = [];
        $class = $entityClass;

        foreach ($names as $index => $name) {
            $metadata = $this->metadataFactory->getMetadata($class);
            $field = $metadata->field($metadata->property($name));
            \assert($field !== null);
            $graphqlPath[] = $field->mappedFrom;

            if ($index === \count($names) - 1) {
                break;
            }

            // A field of a value object, nested under its key in the rows: the last name of the path.
            if ($field->embedded !== null && $index === \count($names) - 2) {
                $field = $field->embedded->field($names[$index + 1])
                    ?? throw new InvalidArgumentException(\sprintf('"%s::$%s" maps "%s", but "%s" is not a field of "%s".', $dtoClass, $parameter->getName(), $path, $names[$index + 1], $field->embedded->class));
                $graphqlPath[] = $field->mappedFrom;

                break;
            }

            if ($field->relation === null || $field->isCollection) {
                throw new InvalidArgumentException(\sprintf('"%s::$%s" maps "%s" through "%s", which is not a relation to one entity of "%s".', $dtoClass, $parameter->getName(), $path, $name, $class));
            }

            $class = $field->relation;
        }

        $nested = null;

        if ($field->relation !== null) {
            $nestedClass = $field->isCollection ? $this->collectionItemClass($parameter) : $this->objectClass($parameter);

            if ($nestedClass === null) {
                throw new InvalidArgumentException(\sprintf('"%s::$%s" maps the relation "%s": type it with a class to hydrate, or map a field of the relation with #[MapFrom(\'%s.name\')].', $dtoClass, $parameter->getName(), $path, $path));
            }

            $nested = $this->getMetadata($nestedClass, $field->relation, $nesting);
        }

        $valueClass = GraphqlEntityMetadataFactory::valueClass($parameter->getType());

        return new DtoParameterMetadata(
            $parameter->getName(),
            $graphqlPath,
            $parameter->getType(),
            $parameter->allowsNull(),
            $parameter->isDefaultValueAvailable(),
            $nested,
            $field->isCollection,
            // The converter the entity names for its field, when the parameter is typed as its property.
            $field->converter !== null && $valueClass === $field->valueClass ? $field->converter : null,
            $valueClass,
            $field->embedded,
        );
    }

    /**
     * The class of a parameter typed with an object to hydrate: neither a date nor an enum.
     *
     * @return class-string|null
     */
    private function objectClass(\ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $class = $type->getName();

        if (!class_exists($class) || is_a($class, \DateTimeInterface::class, true) || is_a($class, \UnitEnum::class, true)) {
            return null;
        }

        return $class;
    }

    /**
     * The class of the items of a list, from the @param of the constructor or the @var of a promoted property.
     *
     * @return class-string|null
     */
    private function collectionItemClass(\ReflectionParameter $parameter): ?string
    {
        $reflections = [$parameter];

        if ($parameter->isPromoted()) {
            $class = $parameter->getDeclaringClass();
            \assert($class !== null);
            $reflections[] = $class->getProperty($parameter->getName());
        }

        foreach ($reflections as $reflection) {
            try {
                $type = $this->typeResolver->resolve($reflection);
            } catch (UnsupportedException) {
                continue;
            }

            foreach ($type->traverse() as $candidate) {
                if (!$candidate instanceof CollectionType) {
                    continue;
                }

                $valueType = $candidate->getCollectionValueType();

                if ($valueType instanceof ObjectType && class_exists($valueType->getClassName())) {
                    return $valueType->getClassName();
                }
            }
        }

        return null;
    }
}
