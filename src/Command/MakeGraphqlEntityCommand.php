<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Codegen\PropertyDefinition;
use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Query\GraphqlName;
use GraphqlOrm\Schema\GraphqlSchema;
use GraphqlOrm\Schema\SchemaIntrospector;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:make:entity',
    description: 'Generate a GraphQL entity and its repository',
)]
final readonly class MakeGraphqlEntityCommand
{
    private const PHP_IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    public function __construct(
        #[Autowire('%graphql_orm.mapping.entity.dir%')]
        private string $entityDir,
        #[Autowire('%graphql_orm.mapping.entity.namespace%')]
        private string $entityNamespace,
        #[Autowire('%graphql_orm.mapping.repository.dir%')]
        private string $repositoryDir,
        #[Autowire('%graphql_orm.mapping.repository.namespace%')]
        private string $repositoryNamespace,
        private Filesystem $fs,
        private StubRenderer $stubRenderer,
        private SchemaIntrospector $introspector,
        private SchemaPropertyMapper $schemaPropertyMapper = new SchemaPropertyMapper(),
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('Entity name')] string $name,
        #[Option('GraphQL root')] ?string $root = null,
        #[Option('Overwrite files if exist', shortcut: 'f')] bool $force = false,
        #[Option('Generate the properties from the GraphQL schema instead of asking them', name: 'from-schema')] bool $fromSchema = false,
    ): int {
        $io = new SymfonyStyle($input, $output);

        $className = $this->normalizeClassName($name);
        $root = \is_string($root) && $root !== '' ? $root : $this->defaultRoot($className);

        $properties = $fromSchema ? $this->propertiesFromSchema($io, $root) : $this->askProperties($io);

        if ($properties === null) {
            return Command::FAILURE;
        }

        $propertiesCode = $this->renderProperties($properties);
        $relationImports = $this->collectRelationImports($properties);

        $entityFqcn = $this->entityNamespace . '\\' . $className;
        $entityPath = rtrim($this->entityDir, '/') . '/' . $className . '.php';

        $repoClassName = $className . 'Repository';
        $repoFqcn = $this->repositoryNamespace . '\\' . $repoClassName;
        $repoPath = rtrim($this->repositoryDir, '/') . '/' . $repoClassName . '.php';

        $entityCode = $this->stubRenderer->render('entity.stub.php', [
            'namespace' => $this->entityNamespace,
            'class_name' => $className,
            'root' => $root,
            'repo_namespace' => $this->repositoryNamespace,
            'repo_short' => $repoClassName,
            'properties' => $propertiesCode,
            'relation_imports' => $relationImports,
        ]);

        $repoCode = $this->stubRenderer->render('repository.stub.php', [
            'namespace' => $this->repositoryNamespace,
            'repo_class_name' => $repoClassName,
            'entity_namespace' => $this->entityNamespace,
            'entity_short' => $className,
        ]);

        if (!$force) {
            if ($this->fs->exists($entityPath)) {
                $io->error(\sprintf('The file already exists: %s (use --force to overwrite)', $entityPath));

                return Command::FAILURE;
            }
            if ($this->fs->exists($repoPath)) {
                $io->error(\sprintf('The file already exists: %s (use --force to overwrite)', $repoPath));

                return Command::FAILURE;
            }
        }

        $this->fs->mkdir([$this->entityDir, $this->repositoryDir]);

        $this->fs->dumpFile($entityPath, $entityCode);
        $this->fs->dumpFile($repoPath, $repoCode);

        $io->success([
            \sprintf('Entity : %s', $entityFqcn),
            \sprintf('Repository :   %s', $repoFqcn),
            \sprintf('GraphQL root :   %s', $root),
        ]);

        $this->listMissingRelationTargets($io, $properties);

        return Command::SUCCESS;
    }

    /**
     * @return PropertyDefinition[]|null null when the schema cannot be read or has no type for the root
     */
    private function propertiesFromSchema(SymfonyStyle $io, string $root): ?array
    {
        try {
            $introspection = $this->introspector->introspectSchema();
        } catch (GraphqlOrmException $e) {
            $io->error(\sprintf('The GraphQL schema could not be introspected: %s', $e->getMessage()));

            return null;
        }

        $schema = new GraphqlSchema($introspection['types'], $introspection['queryType']);
        $typeName = $schema->entityTypeOfRoot($root);

        if ($typeName === null) {
            $io->error(\sprintf('No GraphQL type found for the root "%s": pass the root query field with --root.', $root));

            return null;
        }

        $io->note(\sprintf('Properties generated from the GraphQL type "%s".', $typeName));

        return $this->schemaPropertyMapper->map($schema, $typeName);
    }

    /**
     * @param PropertyDefinition[] $properties
     */
    private function listMissingRelationTargets(SymfonyStyle $io, array $properties): void
    {
        $existing = $this->findExistingEntities();
        $missing = [];

        foreach ($properties as $property) {
            if ($property->relation && $property->targetEntity !== null && !\in_array($property->targetEntity, $existing, true)) {
                $missing[$property->targetEntity] = \sprintf('%s: php bin/console graphqlorm:make:entity %s --from-schema', $property->targetEntity, $property->targetEntity);
            }
        }

        if ($missing !== []) {
            $io->warning(['Relations point to entities that do not exist yet:', ...array_values($missing)]);
        }
    }

    /**
     * @return PropertyDefinition[]
     */
    private function askProperties(SymfonyStyle $io): array
    {
        $identifierAlreadyExists = false;

        $properties = [];

        while (true) {
            if (!$io->confirm('Add a property ?', empty($properties))) {
                break;
            }

            /** @var ?string $name */
            $name = $io->ask('Property name');

            if (!$name || preg_match(self::PHP_IDENTIFIER_PATTERN, $name) !== 1) {
                $io->warning('Invalid property name: it must be a valid PHP identifier');
                continue;
            }

            /** @var ?string $graphqlField */
            $graphqlField = $io->ask('Field name in GraphQL schema');

            if (!$graphqlField || !GraphqlName::isValid($graphqlField)) {
                $io->warning('Invalid GraphQL field name');
                continue;
            }

            $identifier = false;
            if (!$identifierAlreadyExists) {
                $identifier = $io->confirm('Identifier ?', false);
            }

            /** @var string $type */
            $type = $io->choice(
                'Type',
                [
                    'string',
                    'int',
                    'float',
                    'bool',
                    'relation',
                ],
                'string'
            );

            if ($type === 'relation') {
                $relation = $this->askRelation(
                    $io,
                    $name,
                    $graphqlField
                );

                if ($relation !== null) {
                    $properties[] = $relation;
                }

                continue;
            }

            $nullable = $io->confirm('Nullable ?', false);

            $properties[] = new PropertyDefinition(
                lcfirst($name),
                $type,
                $graphqlField,
                $nullable,
                $identifier
            );

            if ($identifier) {
                $identifierAlreadyExists = true;
            }
        }

        return $properties;
    }

    private function askRelation(SymfonyStyle $io, string $propertyName, string $graphqlField): ?PropertyDefinition
    {
        $entities = $this->findExistingEntities();

        if ($entities !== []) {
            /* @var ?string $target */
            $target = $io->choice('Target entity', $entities);
        } else {
            /* @var ?string $target */
            $target = $io->ask('Target entity');
        }

        if (!\is_string($target) || !$target) {
            $io->warning('Invalid target entity');

            return null;
        }

        $type = $io->choice(
            'Relation type',
            [
                'object',
                'collection',
            ],
            'object'
        );

        $collection = $type === 'collection';

        return new PropertyDefinition(
            name: lcfirst($propertyName),
            phpType: $collection ? 'array' : $target,
            mappedFrom: $graphqlField,
            nullable: !$collection,
            identifier: false,
            relation: true,
            collection: $collection,
            targetEntity: $target,
        );
    }

    /**
     * @param PropertyDefinition[] $properties
     */
    private function renderProperties(array $properties): string
    {
        if ($properties === []) {
            return '';
        }

        $out = [];

        foreach ($properties as $property) {
            $attribute = $property->identifier
                ? "#[GraphqlField(mappedFrom: '{$property->mappedFrom}', identifier: true)]"
                : "#[GraphqlField(mappedFrom: '{$property->mappedFrom}')]";

            if ($property->relation) {
                if ($property->collection) {
                    $out[] = <<<PHP
    /** @var {$property->targetEntity}[] */
    {$attribute}
    private array \${$property->name} = [];

PHP;

                    continue;
                }

                $out[] = <<<PHP
    {$attribute}
    private ?{$property->targetEntity} \${$property->name} = null;

PHP;

                continue;
            }

            $type = $property->nullable
                ? '?' . $property->phpType
                : $property->phpType;

            $default = $property->nullable
                ? ' = null'
                : '';

            $out[] = <<<PHP
    {$attribute}
    private {$type} \${$property->name}{$default};

PHP;
        }

        return implode("\n", $out);
    }

    /**
     * @param PropertyDefinition[] $properties
     */
    private function collectRelationImports(array $properties): string
    {
        $imports = [];

        foreach ($properties as $property) {
            if (!$property->relation) {
                continue;
            }

            $imports[] = 'use ' . $this->entityNamespace . '\\' . $property->targetEntity . ';';
        }

        return implode("\n", array_unique($imports));
    }

    /**
     * @return string[]
     */
    private function findExistingEntities(): array
    {
        if (!is_dir($this->entityDir)) {
            return [];
        }

        $finder = new Finder();
        $finder
            ->files()
            ->in($this->entityDir)
            ->name('*.php');

        $entities = [];

        foreach ($finder as $file) {
            $entities[] = pathinfo($file->getFilename(), PATHINFO_FILENAME);
        }

        sort($entities);

        return $entities;
    }

    private function normalizeClassName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^a-zA-Z0-9_\\\\]/', '', $name) ?: '';
        $name = str_replace('\\', '', $name);

        if ($name === '') {
            return 'Entity';
        }

        $name = preg_replace('/_+/', '_', $name) ?: $name;
        $parts = array_filter(explode('_', $name), fn ($p) => $p !== '');

        $out = '';
        foreach ($parts as $p) {
            $out .= ucfirst(strtolower($p));
        }

        return $out === '' ? 'Entity' : $out;
    }

    private function defaultRoot(string $className): string
    {
        $camel = lcfirst($className);

        if (str_ends_with($camel, 's')) {
            return $camel;
        }

        return $camel . 's';
    }
}
