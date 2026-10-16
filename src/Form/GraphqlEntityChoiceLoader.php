<?php

declare(strict_types=1);

namespace GraphqlOrm\Form;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use Symfony\Component\Form\ChoiceList\ArrayChoiceList;
use Symfony\Component\Form\ChoiceList\ChoiceListInterface;
use Symfony\Component\Form\ChoiceList\Loader\ChoiceLoaderInterface;

/**
 * The choices of GraphqlEntityType: every entity (or those of the "query_builder" option) for the choice list, but the
 * submitted ones only, by their identifier, to read a submission: with "choice_lazy", the list is never loaded.
 *
 * @internal
 */
final class GraphqlEntityChoiceLoader implements ChoiceLoaderInterface
{
    private ?ChoiceListInterface $choiceList = null;

    /** @var array<string, object> the entities loaded for submitted values, by value: Symfony asks for them more than once */
    private array $loaded = [];

    /**
     * @param class-string $class
     * @param mixed        $queryBuilder the "query_builder" option: null, or a callable receiving the repository
     */
    public function __construct(
        private readonly GraphqlManager $manager,
        private readonly string $class,
        private readonly mixed $queryBuilder,
    ) {
    }

    public function loadChoiceList(?callable $value = null): ChoiceListInterface
    {
        return $this->choiceList ??= new ArrayChoiceList($this->load(null), $value);
    }

    /**
     * @param array<int|string, string> $values
     *
     * @return list<object>
     */
    public function loadChoicesForValues(array $values, ?callable $value = null): array
    {
        $values = array_values(array_filter($values, fn (string $value) => $value !== ''));

        if ($values === [] || $this->choiceList !== null) {
            return $values === [] ? [] : array_values(array_filter($this->loadChoiceList($value)->getChoicesForValues($values), 'is_object'));
        }

        $missing = array_values(array_diff($values, array_keys($this->loaded)));

        foreach ($missing !== [] ? $this->load($missing) : [] as $entity) {
            $this->loaded[self::value($value, $entity)] = $entity;
        }

        // In the order of the submitted values, those found only.
        return array_values(array_filter(array_map(fn (string $submitted): ?object => $this->loaded[$submitted] ?? null, $values)));
    }

    /**
     * @param array<int|string, mixed> $choices
     *
     * @return list<string>
     */
    public function loadValuesForChoices(array $choices, ?callable $value = null): array
    {
        return array_values(array_map(fn (mixed $choice): string => self::value($value, $choice), $choices));
    }

    private static function value(?callable $value, mixed $choice): string
    {
        $result = $value !== null ? $value($choice) : null;

        return \is_scalar($result) || $result instanceof \Stringable ? (string) $result : '';
    }

    /**
     * @param list<string>|null $identifiers the identifiers to load, every entity when null
     *
     * @return list<object>
     *
     * @throws InvalidArgumentException when the "query_builder" option does not return a query builder
     */
    private function load(?array $identifiers): array
    {
        $repository = $this->manager->getRepository($this->class);
        $qb = \is_callable($this->queryBuilder) ? ($this->queryBuilder)($repository) : $repository->createQueryBuilder();

        if (!$qb instanceof GraphqlQueryBuilder) {
            throw new InvalidArgumentException(\sprintf('The "query_builder" option of "%s" must return a "%s".', GraphqlEntityType::class, GraphqlQueryBuilder::class));
        }

        if ($identifiers !== null) {
            $identifier = $this->manager->getMetadataFactory()->getMetadata($this->class)->identifier
                ?? throw new InvalidArgumentException(\sprintf('"%s" has no identifier to load the submitted choices.', $this->class));
            $type = (new \ReflectionProperty($this->class, $identifier->property))->getType();
            // The submitted values are strings: an integer identifier is sent as such.
            $values = $type instanceof \ReflectionNamedType && $type->getName() === 'int'
                ? array_map(intval(...), array_filter($identifiers, ctype_digit(...)))
                : $identifiers;
            $qb->andWhere($qb->expr()->in($identifier->mappedFrom, array_values($values)));
        }

        $result = $qb->getQuery()->getResult();

        return array_values($result instanceof PaginatedResult ? $result->items : $result);
    }
}
