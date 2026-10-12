<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
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
     * @param class-string[] $entityClasses
     */
    public function __construct(
        private SchemaIntrospector $introspector,
        private SchemaValidator $validator,
        #[Autowire('%graphql_orm.schema_validation.entity_classes%')]
        private array $entityClasses,
    ) {
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $schema = $this->introspector->introspectSchema();
        } catch (GraphqlOrmException $e) {
            $io->error('GraphQL schema introspection failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $violations = $this->validator->validate($this->entityClasses, $schema['types'], $schema['queryType']);

        if ($violations !== []) {
            $io->error(\sprintf('%d schema violation(s) found.', \count($violations)));
            $io->listing($violations);

            return Command::FAILURE;
        }

        $io->success(\sprintf('%d entity mapping(s) match the GraphQL schema.', \count($this->entityClasses)));

        return Command::SUCCESS;
    }
}
