<?php

declare(strict_types=1);

namespace GraphqlOrm\Command;

use GraphqlOrm\Exception\GraphqlOrmException;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends a raw GraphQL query to the API of a manager and prints its response as JSON: to try a query without writing
 * code. The query goes through the manager: it is logged and shown in the profiler, its values masked there as any
 * other, while the response printed keeps them.
 *
 * @internal
 */
#[AsCommand(
    name: 'graphqlorm:query',
    description: 'Send a raw GraphQL query to the API and print the JSON response',
)]
final readonly class QueryCommand
{
    public function __construct(
        private GraphqlManagerRegistry $managers,
        private EntityClassLocator $entityClassLocator,
        private EntityTable $table,
    ) {
    }

    public function __invoke(
        InputInterface $input,
        OutputInterface $output,
        #[Argument('The GraphQL query; read from the standard input when omitted')]
        ?string $query = null,
        #[Option('The variables of the query, as a JSON object: \'{"id": 1}\'')]
        ?string $variables = null,
        #[Option('The manager whose API is queried; the default one when unset')]
        ?string $manager = null,
        #[Option('Hydrate the response as this entity (short name or FQCN) and print the entities, as graphqlorm:find')]
        ?string $hydrate = null,
        #[Option('With --hydrate, the output format: table or json', shortcut: 'f')]
        string $format = 'table',
    ): int {
        $io = new SymfonyStyle($input, $output);
        $errors = $io->getErrorStyle();
        $query ??= $this->readStandardInput($input);

        if ($query === null || trim($query) === '') {
            $errors->error('No GraphQL query: pass it as argument, or on the standard input.');

            return Command::FAILURE;
        }

        if ($hydrate !== null) {
            return $this->hydrate($io, $output, $query, $hydrate, $variables, $format);
        }

        try {
            $response = $this->managers->getManager($manager)->execute(
                $query,
                static fn (array $result): array => $result,
                variables: $this->variables($variables),
            );
        } catch (\InvalidArgumentException|GraphqlOrmException $exception) {
            $errors->error($exception->getMessage());

            return Command::FAILURE;
        }

        $output->writeln(json_encode($response, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // Partial data along with errors: printed, but the command fails as the query did in part.
        return isset($response['errors']) ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Sends the query through a query of the entity: its root field must be the one of the entity.
     */
    private function hydrate(SymfonyStyle $io, OutputInterface $output, string $query, string $entity, ?string $variables, string $format): int
    {
        $class = $this->entityClassLocator->resolve($entity);

        if ($class === null) {
            $io->getErrorStyle()->error($this->entityClassLocator->notFound($entity));

            return Command::FAILURE;
        }

        try {
            if ($variables !== null && $this->variables($variables) !== []) {
                throw new \InvalidArgumentException('--hydrate sends the query without variables: write their values in the query.');
            }

            $repository = $this->managers->getRepository($class);
            $result = $repository->createQueryBuilder()->setGraphQL($query)->getQuery()->getResult();
        } catch (\InvalidArgumentException|GraphqlOrmException $exception) {
            $io->getErrorStyle()->error($exception->getMessage());

            return Command::FAILURE;
        }

        $entities = array_values($result instanceof PaginatedResult ? $result->items : $result);
        $metadata = $this->managers->getManagerForClass($class)->getMetadataFactory()->getMetadata($class);
        $this->table->print($io, $output, $metadata, $entities, $format === 'json');

        if ($format !== 'json') {
            $io->writeln(\sprintf('%d %s hydrated as %s.', \count($entities), $metadata->name, $class));
        }

        return Command::SUCCESS;
    }

    /**
     * The query piped to the command; none from a terminal, rather than waiting for one to be typed.
     */
    private function readStandardInput(InputInterface $input): ?string
    {
        $stream = $input instanceof StreamableInputInterface ? $input->getStream() : null;

        if ($stream === null) {
            if (stream_isatty(\STDIN)) {
                return null;
            }

            $stream = \STDIN;
        }

        $query = stream_get_contents($stream);

        return $query !== false ? $query : null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the variables are not a JSON object
     */
    private function variables(?string $variables): array
    {
        if ($variables === null) {
            return [];
        }

        try {
            $decoded = json_decode($variables, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }

        if (!\is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \InvalidArgumentException(\sprintf('The variables must be a JSON object, as \'{"id": 1}\': %s given.', $variables));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
