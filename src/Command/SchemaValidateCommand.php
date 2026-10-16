<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:schema:validate',
    description: 'Validate the entity mapping against the live GraphQL schema',
)]
final readonly class SchemaValidateCommand
{
    /**
     * @param class-string[]                $entityClasses        the entities of the default manager, without registry
     * @param array<string, class-string[]> $managerEntityClasses the entities of each manager, by name
     */
    public function __construct(
        private SchemaIntrospector $introspector,
        private SchemaValidator $validator,
        #[Autowire('%graphql_orm.schema_validation.entity_classes%')]
        private array $entityClasses,
        private ?GraphqlManagerRegistry $managers = null,
        #[Autowire('%graphql_orm.manager_entity_classes%')]
        private array $managerEntityClasses = [],
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('The manager whose API is validated; every manager when unset')] ?string $manager = null,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $valid = true;

        foreach ($this->validations($manager) as $name => [$introspector, $validator, $entityClasses]) {
            if ($name !== null) {
                $io->section(\sprintf('Manager "%s"', $name));
            }

            $valid = $this->validate($io, $introspector, $validator, $entityClasses) && $valid;
        }

        return $valid ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The schema of each API and the entities to check against it, by manager; a single unnamed one without registry.
     *
     * @return iterable<string|null, array{SchemaIntrospector, SchemaValidator, class-string[]}>
     */
    private function validations(?string $manager): iterable
    {
        if ($this->managers === null) {
            yield null => [$this->introspector, $this->validator, $this->entityClasses];

            return;
        }

        foreach ($manager !== null ? [$manager] : $this->managers->getManagerNames() as $name) {
            $graphqlManager = $this->managers->getManager($name);

            yield $name => [
                new SchemaIntrospector($graphqlManager->getClient()),
                new SchemaValidator($graphqlManager->getMetadataFactory()),
                $this->managerEntityClasses[$name] ?? [],
            ];
        }
    }

    /**
     * @param class-string[] $entityClasses
     */
    private function validate(SymfonyStyle $io, SchemaIntrospector $introspector, SchemaValidator $validator, array $entityClasses): bool
    {
        try {
            $schema = $introspector->introspectSchema();
        } catch (GraphqlOrmException $e) {
            $io->error('GraphQL schema introspection failed: ' . $e->getMessage());

            return false;
        }

        $violations = $validator->validate($entityClasses, $schema['types'], $schema['queryType']);

        if ($violations !== []) {
            $io->error(\sprintf('%d schema violation(s) found.', \count($violations)));
            $io->listing($violations);

            return false;
        }

        $io->success(\sprintf('%d entity mapping(s) match the GraphQL schema.', \count($entityClasses)));

        return true;
    }
}
