<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints entities as hydrated, a table or JSON: a relation by its identifier, a collection by its size (its identifiers
 * in JSON).
 *
 * @internal
 */
final readonly class EntityTable
{
    public function __construct(
        private GraphqlManagerRegistry $managers,
    ) {
    }

    /**
     * @param list<object> $entities
     */
    public function print(SymfonyStyle $io, OutputInterface $output, GraphqlEntityMetadata $metadata, array $entities, bool $json): void
    {
        $rows = array_map(fn (object $entity) => $this->row($metadata, $entity, $json), $entities);

        if ($json) {
            $output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }

        $io->table(array_map(fn (GraphqlFieldMetadata $field) => $field->property, array_values($metadata->fields)), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(GraphqlEntityMetadata $metadata, object $entity, bool $json): array
    {
        $row = [];

        foreach ($metadata->fields as $field) {
            $property = new \ReflectionProperty($entity, $field->property);
            $value = $property->isInitialized($entity) ? $property->getRawValue($entity) : null;
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
        $identifier = $this->managers->getManagerForClass($entity::class)->getMetadataFactory()->getMetadata($entity::class)->identifier;

        if ($identifier === null) {
            return null;
        }

        // A lazy ghost has its identifier set without being loaded: reading it sends no query.
        $property = new \ReflectionProperty($entity, $identifier->property);

        return $property->isInitialized($entity) ? $property->getRawValue($entity) : null;
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
