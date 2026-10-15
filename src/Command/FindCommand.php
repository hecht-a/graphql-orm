<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\Direction;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Finds entities through the API and shows them as hydrated: to try a mapping or a criterion without writing code.
 *
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:find',
    description: 'Find entities through the GraphQL API and show them',
)]
final readonly class FindCommand
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private GraphqlManager $manager,
        private EntityClassLocator $entityClassLocator,
    ) {
    }

    /**
     * @param list<string> $criteria
     * @param list<string> $orderBy
     */
    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Entity class (short name or FQCN)')]
        string $entity,
        #[Option('Criterion "field=value", repeatable; the value is read as JSON when it is one: false, 3, null, [1,3]')]
        array $criteria = [],
        #[Option('Ordering "field=asc|desc", repeatable', name: 'order-by')]
        array $orderBy = [],
        #[Option('Maximum number of entities')]
        int $limit = self::DEFAULT_LIMIT,
        #[Option('Output format: table or json', shortcut: 'f')]
        string $format = 'table',
    ): int {
        $io = new SymfonyStyle($input, $output);
        $class = $this->entityClassLocator->resolve($entity);

        if ($class === null) {
            $io->error(\sprintf('Entity "%s" not found. Available entities: %s', $entity, implode(', ', $this->entityClassLocator->available())));

            return Command::FAILURE;
        }

        try {
            $entities = $this->manager->getRepository($class)->findBy(
                $this->pairs($criteria, $this->value(...)),
                $this->pairs($orderBy, fn (string $direction) => Direction::from(strtoupper($direction))),
                $limit,
            );
        } catch (\InvalidArgumentException|\ValueError|GraphqlOrmException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $metadata = $this->manager->getMetadataFactory()->getMetadata($class);
        $rows = array_map(fn (object $entity) => $this->row($metadata, $entity, $format === 'json'), array_values($entities));

        if ($format === 'json') {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $io->table(array_map(fn (GraphqlFieldMetadata $field) => $field->property, array_values($metadata->fields)), $rows);
        $io->writeln(\sprintf('%d %s found.', \count($rows), $metadata->name));

        return Command::SUCCESS;
    }

    /**
     * @template V
     *
     * @param list<string>        $pairs "field=value"
     * @param callable(string): V $parse
     *
     * @return array<string, V>
     *
     * @throws \InvalidArgumentException when a pair has no "="
     */
    private function pairs(array $pairs, callable $parse): array
    {
        $parsed = [];

        foreach ($pairs as $pair) {
            if (!str_contains($pair, '=')) {
                throw new \InvalidArgumentException(\sprintf('"%s" must be written field=value.', $pair));
            }

            [$field, $value] = explode('=', $pair, 2);
            $parsed[trim($field)] = $parse($value);
        }

        return $parsed;
    }

    /**
     * The value as JSON when it is one (false, 3, null, [1,3], "123"), as a string otherwise.
     */
    private function value(string $value): mixed
    {
        try {
            return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $value;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GraphqlEntityMetadata $metadata, object $entity, bool $json): array
    {
        $row = [];

        foreach ($metadata->fields as $field) {
            $property = new \ReflectionProperty($entity, $field->property);
            $value = $property->isInitialized($entity) ? $property->getValue($entity) : null;
            $row[$field->property] = $this->cell($field, $value, $json);
        }

        return $row;
    }

    /**
     * A relation is shown by its identifier, a collection by its size (its identifiers in JSON).
     */
    private function cell(GraphqlFieldMetadata $field, mixed $value, bool $json): mixed
    {
        if (\is_array($value) && $field->relation !== null) {
            $identifiers = array_map(fn (mixed $item) => \is_object($item) ? $this->identifier($item) : $item, array_values($value));

            return $json ? $identifiers : \sprintf('%d %s', \count($value), $field->mappedFrom);
        }

        if (\is_object($value) && $field->relation !== null) {
            $identifier = $this->identifier($value);

            return $json ? $identifier : '#' . (\is_scalar($identifier) ? (string) $identifier : '?');
        }

        $value = match (true) {
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            default => $value,
        };

        return $json ? $value : $this->text($value);
    }

    private function identifier(object $entity): mixed
    {
        $identifier = $this->manager->getMetadataFactory()->getMetadata($entity::class)->identifier;

        if ($identifier === null) {
            return null;
        }

        // A lazy ghost has its identifier set without being loaded: reading it sends no query.
        $property = new \ReflectionProperty($entity, $identifier->property);

        return $property->isInitialized($entity) ? $property->getValue($entity) : null;
    }

    private function text(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            \is_bool($value) => $value ? 'true' : 'false',
            \is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '?',
        };
    }
}
