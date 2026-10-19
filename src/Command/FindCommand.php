<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\CompiledQuery;
use GraphqlOrm\Query\Direction;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
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

    private EntityTable $table;

    public function __construct(
        private GraphqlManagerRegistry $managers,
        private EntityClassLocator $entityClassLocator,
        ?EntityTable $table = null,
    ) {
        $this->table = $table ?? new EntityTable($managers);
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
        #[Option('Show the query and its variables as they would be sent, without sending it', name: 'dry-run')]
        bool $dryRun = false,
    ): int {
        $io = new SymfonyStyle($input, $output);
        $class = $this->entityClassLocator->resolve($entity);

        if ($class === null) {
            $io->error($this->entityClassLocator->notFound($entity));

            return Command::FAILURE;
        }

        try {
            $manager = $this->managers->getManagerForClass($class);
            $query = $manager->getRepository($class)->createFindByQuery(
                $this->pairs($criteria, $this->value(...)),
                $this->pairs($orderBy, fn (string $direction) => Direction::from(strtoupper($direction))),
                $limit,
            );

            if ($dryRun) {
                $this->show($io, $output, $manager, $query->getCompiledQuery(), $format === 'json');

                return Command::SUCCESS;
            }

            /** @var object[] $entities findBy() does not paginate */
            $entities = $query->getResult();
        } catch (\InvalidArgumentException|\ValueError|GraphqlOrmException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $metadata = $this->managers->getManagerForClass($class)->getMetadataFactory()->getMetadata($class);
        $this->table->print($io, $output, $metadata, array_values($entities), $format === 'json');

        if ($format !== 'json') {
            $io->writeln(\sprintf('%d %s found.', \count($entities), $metadata->name));
        }

        return Command::SUCCESS;
    }

    /**
     * The query as it would be sent, with the manager and the dialect sending it.
     */
    private function show(SymfonyStyle $io, OutputInterface $output, GraphqlManager $manager, CompiledQuery $query, bool $json): void
    {
        if ($json) {
            $output->writeln(json_encode([
                'manager' => $manager->getName(),
                'dialect' => $manager->getDialect()::class,
                'graphql' => $query->graphql,
                'variables' => $query->variables,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return;
        }

        $io->definitionList(['Manager' => $manager->getName()], ['Dialect' => $manager->getDialect()::class]);
        $io->section('GraphQL');
        $io->writeln($query->graphql);

        if ($query->variables !== []) {
            $io->section('Variables');
            $io->writeln(json_encode($query->variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $io->note('Not sent: the query above is what graphqlorm:find would send without --dry-run.');
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
}
