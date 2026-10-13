<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:debug:entity',
    description: 'Show metadata of a GraphQL Entity',
)]
final readonly class DebugEntityCommand
{
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
        private EntityClassLocator $entityClassLocator,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Entity class (short name or FQCN)')]
        string $entity,
        #[Option('Output format', shortcut: 'f')]
        string $format = 'table',
    ): int {
        $io = new SymfonyStyle($input, $output);

        $fqcn = $this->entityClassLocator->resolve($entity);

        if ($fqcn === null) {
            $io->error(\sprintf(
                'Entity "%s" not found. Available entities: %s',
                $entity,
                implode(', ', $this->entityClassLocator->available()),
            ));

            return Command::FAILURE;
        }

        try {
            $metadata = $this->metadataFactory->getMetadata($fqcn);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Could not load metadata for "%s": %s', $fqcn, $e->getMessage()));

            return Command::FAILURE;
        }

        if ($format === 'json') {
            $scalarFields = [];
            $relationFields = [];

            foreach ($metadata->fields as $field) {
                if ($field->relation !== null) {
                    $relationFields[] = [
                        'property' => $field->property,
                        'mappedFrom' => $field->mappedFrom,
                        'targetEntity' => $field->relation,
                        'isCollection' => $field->isCollection,
                        'isIdentifier' => $field->isIdentifier,
                    ];
                } else {
                    $type = $this->resolvePhpType($fqcn, $field->property);
                    $scalarFields[] = [
                        'property' => $field->property,
                        'mappedFrom' => $field->mappedFrom,
                        'type' => $type,
                        'isIdentifier' => $field->isIdentifier,
                    ];
                }
            }

            $output->writeln(json_encode([
                'class' => $metadata->class,
                'graphqlRoot' => $metadata->name,
                'repository' => $metadata->repositoryClass,
                'identifier' => $metadata->identifier?->mappedFrom,
                'scalarFields' => $scalarFields,
                'relationFields' => $relationFields,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $io->title(\sprintf('GraphQL ORM — Entity "%s"', (new \ReflectionClass($fqcn))->getShortName()));

        $io->section('General');
        $io->definitionList(
            ['FQCN' => $metadata->class],
            ['GraphQL root' => $metadata->name],
            ['Repository' => $metadata->repositoryClass ?? '(none)'],
            ['Identifier' => $metadata->identifier
                ? \sprintf('%s → %s', $metadata->identifier->property, $metadata->identifier->mappedFrom)
                : '(none)'],
        );

        $scalarRows = [];
        $relationRows = [];

        foreach ($metadata->fields as $field) {
            $identifier = $field->isIdentifier ? ' ★' : '';

            if ($field->relation !== null) {
                $relationRows[] = [
                    $field->property . $identifier,
                    $field->mappedFrom,
                    (new \ReflectionClass($field->relation))->getShortName(),
                    $field->isCollection ? 'collection' : 'object',
                ];
            } else {
                $type = $this->resolvePhpType($fqcn, $field->property);
                $scalarRows[] = [
                    $field->property . $identifier,
                    $field->mappedFrom,
                    $type,
                ];
            }
        }

        $io->section('Scalar fields');

        if ($scalarRows === []) {
            $io->text('No scalar fields.');
        } else {
            $io->table(['Property', 'mappedFrom', 'PHP type'], $scalarRows);
        }

        $io->section('Relations');

        if ($relationRows === []) {
            $io->text('No relations.');
        } else {
            $io->table(['Property', 'mappedFrom', 'Target entity', 'Type'], $relationRows);
        }

        return Command::SUCCESS;
    }

    /**
     * @param class-string $class
     */
    private function resolvePhpType(string $class, string $property): string
    {
        try {
            $reflection = new \ReflectionProperty($class, $property);
            $type = $reflection->getType();

            if ($type instanceof \ReflectionNamedType) {
                return ($type->allowsNull() ? '?' : '') . $type->getName();
            }

            if ($type instanceof \ReflectionUnionType) {
                return implode('|', array_map(
                    function (\ReflectionType $t): string {
                        if ($t instanceof \ReflectionNamedType) {
                            return $t->getName();
                        }

                        // The other members of a union type are intersection types (DNF types).
                        return '(' . implode('&', array_map(
                            fn (\ReflectionType $i) => $i instanceof \ReflectionNamedType ? $i->getName() : '',
                            $t->getTypes(),
                        )) . ')';
                    },
                    $type->getTypes(),
                ));
            }
        } catch (\ReflectionException) {
        }

        return 'mixed';
    }
}
