<?php

declare(strict_types=1);

namespace GraphqlOrm\ValueResolver;

use GraphqlOrm\Attribute\GraphqlEntity;
use GraphqlOrm\Attribute\MapGraphqlEntity;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Gives a controller the entity whose identifier is in the route: `show(Task $task)` on `/tasks/{task}` or `/tasks/{id}`.
 * #[MapGraphqlEntity] names another route parameter, or finds the entity by other fields. The entity not found is a 404,
 * or null for a nullable argument.
 */
final readonly class GraphqlEntityValueResolver implements ValueResolverInterface
{
    private const ID_ATTRIBUTE = 'id';

    public function __construct(
        private GraphqlManager $manager,
    ) {
    }

    /**
     * @return array<object|null>
     *
     * @throws NotFoundHttpException    when the entity does not exist
     * @throws InvalidArgumentException when the mapping names a field the entity does not map
     */
    public function resolve(Request $request, ArgumentMetadata $argument): array
    {
        $class = $argument->getType();
        $options = $argument->getAttributesOfType(MapGraphqlEntity::class, ArgumentMetadata::IS_INSTANCEOF)[0] ?? new MapGraphqlEntity();

        if ($options->disabled || !$this->isEntity($class)) {
            return [];
        }

        $criteria = $options->mapping !== null
            ? $this->mappedCriteria($class, $request, $options->mapping)
            : $this->identifierCriteria($class, $request, $options->id ?? $argument->getName());

        if ($criteria === []) {
            return [];
        }

        $entity = \in_array(null, $criteria, true) ? null : (new GraphqlEntityRepository($this->manager, $class))->findOneBy($criteria);

        if ($entity === null && !$argument->isNullable()) {
            throw new NotFoundHttpException(\sprintf('"%s" object not found by the "%s" value resolver.', $class, self::class));
        }

        return [$entity];
    }

    /**
     * @phpstan-assert-if-true class-string $class
     */
    private function isEntity(?string $class): bool
    {
        return $class !== null && class_exists($class) && (new \ReflectionClass($class))->getAttributes(GraphqlEntity::class) !== [];
    }

    /**
     * @param class-string $class
     *
     * @return array<string, bool|float|int|string|null>
     */
    private function identifierCriteria(string $class, Request $request, string $parameter): array
    {
        $identifier = $this->manager->getMetadataFactory()->getMetadata($class)->identifier;
        $id = $request->attributes->get($parameter) ?? $request->attributes->get(self::ID_ATTRIBUTE);

        if ($identifier === null || !\is_scalar($id)) {
            return [];
        }

        return [$identifier->mappedFrom => $this->cast($class, $identifier, $id)];
    }

    /**
     * @param class-string          $class
     * @param array<string, string> $mapping route parameter => GraphQL field name
     *
     * @return array<string, bool|float|int|string|null>
     */
    private function mappedCriteria(string $class, Request $request, array $mapping): array
    {
        $fields = [];

        foreach ($this->manager->getMetadataFactory()->getMetadata($class)->fields as $field) {
            $fields[$field->mappedFrom] = $field;
        }

        $criteria = [];

        foreach ($mapping as $parameter => $fieldName) {
            $field = $fields[$fieldName] ?? throw new InvalidArgumentException(\sprintf('#[MapGraphqlEntity] maps the route parameter "%s" on "%s", which "%s" does not map.', $parameter, $fieldName, $class));
            $value = $request->attributes->get($parameter);

            if (\is_scalar($value)) {
                $criteria[$fieldName] = $this->cast($class, $field, $value);
            }
        }

        return $criteria;
    }

    /**
     * Route parameters are strings: an integer field is converted, and a value that is not one matches nothing (null).
     *
     * @param class-string $class
     */
    private function cast(string $class, GraphqlFieldMetadata $field, bool|float|int|string $value): bool|float|int|string|null
    {
        $type = (new \ReflectionProperty($class, $field->property))->getType();

        if (!$type instanceof \ReflectionNamedType || $type->getName() !== 'int' || \is_int($value)) {
            return $value;
        }

        return \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : null;
    }
}
