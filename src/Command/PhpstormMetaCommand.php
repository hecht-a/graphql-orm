<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Codegen\PhpstormMetaGenerator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Generates the PhpStorm metadata completing the field names of the entities, in a file of its own of the
 * .phpstorm.meta.php directory: those of the application are left as they are.
 *
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:phpstorm-meta',
    description: 'Generate the PhpStorm metadata completing the field names of the GraphQL entities',
)]
final readonly class PhpstormMetaCommand
{
    private const DEFAULT_OUTPUT = '.phpstorm.meta.php/graphql_orm.php';

    /**
     * @param array<string, class-string[]> $managerEntityClasses the entities of each manager, by name
     */
    public function __construct(
        private GraphqlManagerRegistry $managers,
        #[Autowire('%graphql_orm.manager_entity_classes%')]
        private array $managerEntityClasses,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Option('The file written; .phpstorm.meta.php/graphql_orm.php of the project by default', name: 'output')]
        ?string $file = null,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $file ??= $this->projectDir . '/' . self::DEFAULT_OUTPUT;
        $directory = \dirname($file);

        // A .phpstorm.meta.php file of the application would have to become a directory: it is not replaced.
        if (is_file($directory) || (!is_dir($directory) && !@mkdir($directory, 0o777, true))) {
            $io->error(\sprintf('"%s" is not a directory the metadata can be written in: move the metadata of the application into a .phpstorm.meta.php directory, or give another file with --output.', $directory));

            return Command::FAILURE;
        }

        $entityClasses = array_values(array_unique(array_merge(...array_values($this->managerEntityClasses))));
        $meta = (new PhpstormMetaGenerator())->generate(
            $entityClasses,
            fn (string $class): GraphqlEntityMetadata => $this->managers->getManagerForClass($class)->getMetadataFactory()->getMetadata($class),
        );

        if (file_put_contents($file, $meta) === false) {
            $io->error(\sprintf('"%s" cannot be written.', $file));

            return Command::FAILURE;
        }

        $io->success(\sprintf('The field names of %d entities written to %s: PhpStorm completes them once it reads the file. Run the command again after changing the mapping.', \count($entityClasses), $file));

        return Command::SUCCESS;
    }
}
