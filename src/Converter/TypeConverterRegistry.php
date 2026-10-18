<?php

declare(strict_types=1);

namespace GraphqlOrm\Converter;

use GraphqlOrm\Attribute\AsTypeConverter;
use GraphqlOrm\Exception\InvalidArgumentException;

/**
 * The type converters of the application: by their class, and by the class of the values they handle, told by their
 * #[AsTypeConverter]. In Symfony, the services implementing TypeConverterInterface are registered by autoconfiguration.
 */
final class TypeConverterRegistry
{
    /** @var array<string, TypeConverterInterface>|null by their class, once the services are read */
    private ?array $byClass = null;

    /** @var array<string, TypeConverterInterface> by the class of the values they handle */
    private array $byType = [];

    /**
     * @param iterable<TypeConverterInterface> $converters
     */
    public function __construct(
        private readonly iterable $converters = [],
    ) {
    }

    /**
     * The converter of a field: the one it names, or the one handling its type; none for the others.
     *
     * @param string|null $converter the class of the converter named by #[Field(converter: ...)]
     * @param string|null $type      the class the property or the parameter is typed with
     *
     * @throws InvalidArgumentException when the converter named is not registered and cannot be created
     */
    public function converterFor(?string $converter, ?string $type): ?TypeConverterInterface
    {
        $byClass = $this->load();

        if ($converter !== null) {
            return $byClass[$converter] ?? $this->create($converter);
        }

        return $type !== null ? $this->byType[$type] ?? null : null;
    }

    /**
     * A value of a filter or a criterion on a field, its value objects converted for the API: the value itself, a list
     * of values, or a comparison such as {eq: value}. The converter is the one the field names, else the one of its
     * type, else the one of the class of the value; the other values are kept as they are.
     *
     * @param string|null $converter the class of the converter named by #[Field(converter: ...)]
     * @param string|null $type      the class the property is typed with
     *
     * @throws InvalidArgumentException when the converter named is not registered and cannot be created
     */
    public function toGraphql(?string $converter, ?string $type, mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->toGraphql($converter, $type, $item), $value);
        }

        if (!\is_object($value)) {
            return $value;
        }

        $typeConverter = $this->converterFor($converter, $type) ?? $this->converterFor(null, $value::class);

        return $typeConverter !== null ? $typeConverter->toGraphql($value) : $value;
    }

    /**
     * A converter without dependencies needs no registration.
     *
     * @throws InvalidArgumentException when the class is no converter, or needs arguments
     */
    private function create(string $class): TypeConverterInterface
    {
        $constructor = class_exists($class) ? (new \ReflectionClass($class))->getConstructor() : null;

        if (!is_a($class, TypeConverterInterface::class, true) || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
            throw new InvalidArgumentException(\sprintf('The type converter "%s" is not registered: implement %s in a service, autoconfigured, or give it to the TypeConverterRegistry.', $class, TypeConverterInterface::class));
        }

        return $this->byClass[$class] = new $class();
    }

    /**
     * @return array<string, TypeConverterInterface>
     */
    private function load(): array
    {
        if ($this->byClass !== null) {
            return $this->byClass;
        }

        $this->byClass = [];

        foreach ($this->converters as $converter) {
            $this->byClass[$converter::class] = $converter;

            foreach ((new \ReflectionClass($converter))->getAttributes(AsTypeConverter::class) as $attribute) {
                $this->byType[$attribute->newInstance()->class] = $converter;
            }
        }

        return $this->byClass;
    }
}
