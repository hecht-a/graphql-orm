<?php

declare(strict_types=1);

namespace GraphqlOrm\Form;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Repository\GraphqlEntityRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\ChoiceList\Loader\CallbackChoiceLoader;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A choice among the entities of a class, as Doctrine EntityType: the choices are loaded once the form needs them, with
 * findAll() or the "query_builder" option, and the identifiers are the submitted values.
 */
final class GraphqlEntityType extends AbstractType
{
    public function __construct(
        private readonly GraphqlManager $manager,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('class');
        $resolver->setAllowedTypes('class', 'string');
        $resolver->setDefault('query_builder', null);
        $resolver->setAllowedTypes('query_builder', ['null', 'callable']);

        $resolver->setDefault('choice_loader', fn (Options $options) => new CallbackChoiceLoader(
            fn () => $this->load(self::entityClass($options), $options['query_builder']),
        ));
        $resolver->setDefault('choice_value', fn (Options $options) => fn (?object $entity) => $this->identifierValue(self::entityClass($options), $entity));
        $resolver->setDefault('choice_label', fn (Options $options) => fn (object $entity) => $entity instanceof \Stringable
            ? (string) $entity
            : $this->identifierValue(self::entityClass($options), $entity));
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * @return class-string
     *
     * @throws InvalidArgumentException when the "class" option is not a class
     */
    private static function entityClass(Options $options): string
    {
        $class = $options['class'];

        return \is_string($class) && class_exists($class)
            ? $class
            : throw new InvalidArgumentException(\sprintf('The "class" option of "%s" must be the class of a GraphQL entity.', self::class));
    }

    /**
     * @param class-string $class
     * @param mixed        $queryBuilder the "query_builder" option: null, or a callable receiving the repository
     *
     * @return list<object>
     *
     * @throws InvalidArgumentException when the "query_builder" option does not return a query builder
     */
    private function load(string $class, mixed $queryBuilder): array
    {
        $repository = new GraphqlEntityRepository($this->manager, $class);

        if (!\is_callable($queryBuilder)) {
            return array_values($repository->findAll());
        }

        $qb = $queryBuilder($repository);

        if (!$qb instanceof GraphqlQueryBuilder) {
            throw new InvalidArgumentException(\sprintf('The "query_builder" option of "%s" must return a "%s".', self::class, GraphqlQueryBuilder::class));
        }

        $result = $qb->getQuery()->getResult();

        return array_values($result instanceof PaginatedResult ? $result->items : $result);
    }

    /**
     * @param class-string $class
     *
     * @throws LogicException when the entity has no identifier
     */
    private function identifierValue(string $class, ?object $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        $identifier = $this->manager->getMetadataFactory()->getMetadata($class)->identifier
            ?? throw new LogicException(\sprintf('"%s" has no identifier to tell its choices apart: mark one of its fields with #[GraphqlField(identifier: true)].', $class));
        $property = new \ReflectionProperty($entity, $identifier->property);
        $value = $property->isInitialized($entity) ? $property->getValue($entity) : null;

        return \is_scalar($value) ? (string) $value : null;
    }
}
