<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
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
        private ?GraphqlManagerRegistry $managers = null,
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
            $io->error($this->entityClassLocator->notFound($entity));

            return Command::FAILURE;
        }

        try {
            $manager = $this->managers?->getManagerForClass($fqcn);
            $metadata = ($manager?->getMetadataFactory() ?? $this->metadataFactory)->getMetadata($fqcn);
        } catch (\Throwable $e) {
            $io->error(\sprintf('Could not load metadata for "%s": %s', $fqcn, $e->getMessage()));

            return Command::FAILURE;
        }

        [$scalarFields, $relationFields] = $this->describeFields($metadata);

        if ($format === 'json') {
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

        $this->renderTables($io, $metadata, $manager?->getName(), $scalarFields, $relationFields);

        return Command::SUCCESS;
    }

    /**
     * @return array{
     *   list<array{property: string, mappedFrom: string, type: string, isIdentifier: bool}>,
     *   list<array{property: string, mappedFrom: string, targetEntity: class-string, isCollection: bool, isIdentifier: bool}>
     * } the scalar fields, then the relations
     */
    private function describeFields(GraphqlEntityMetadata $metadata): array
    {
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
                $scalarFields[] = [
                    'property' => $field->property,
                    'mappedFrom' => $field->mappedFrom,
                    'type' => $this->resolvePhpType($metadata->class, $field->property),
                    'isIdentifier' => $field->isIdentifier,
                ];
            }
        }

        return [$scalarFields, $relationFields];
    }

    /**
     * @param list<array{property: string, mappedFrom: string, type: string, isIdentifier: bool}>                                   $scalarFields
     * @param list<array{property: string, mappedFrom: string, targetEntity: class-string, isCollection: bool, isIdentifier: bool}> $relationFields
     */
    private function renderTables(SymfonyStyle $io, GraphqlEntityMetadata $metadata, ?string $manager, array $scalarFields, array $relationFields): void
    {
        $io->title(\sprintf('GraphQL ORM — Entity "%s"', (new \ReflectionClass($metadata->class))->getShortName()));

        $io->section('General');
        $io->definitionList(
            ['FQCN' => $metadata->class],
            ['Manager' => $manager ?? '(default)'],
            ['GraphQL root' => $metadata->name],
            ['Repository' => $metadata->repositoryClass ?? '(none)'],
            ['Identifier' => $metadata->identifier
                ? \sprintf('%s → %s', $metadata->identifier->property, $metadata->identifier->mappedFrom)
                : '(none)'],
        );

        $io->section('Scalar fields');

        if ($scalarFields === []) {
            $io->text('No scalar fields.');
        } else {
            $io->table(['Property', 'mappedFrom', 'PHP type'], array_map(
                static fn (array $field): array => [self::marked($field['property'], $field['isIdentifier']), $field['mappedFrom'], $field['type']],
                $scalarFields,
            ));
        }

        $io->section('Relations');

        if ($relationFields === []) {
            $io->text('No relations.');
        } else {
            $io->table(['Property', 'mappedFrom', 'Target entity', 'Type'], array_map(
                static fn (array $field): array => [
                    self::marked($field['property'], $field['isIdentifier']),
                    $field['mappedFrom'],
                    (new \ReflectionClass($field['targetEntity']))->getShortName(),
                    $field['isCollection'] ? 'collection' : 'object',
                ],
                $relationFields,
            ));
        }
    }

    /**
     * The property, starred when it is the identifier.
     */
    private static function marked(string $property, bool $isIdentifier): string
    {
        return $property . ($isIdentifier ? ' ★' : '');
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
