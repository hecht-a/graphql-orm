<?php

declare(strict_types=1);

namespace GraphqlOrm;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Logging\QueryRedactor;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\CompiledQuery;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Query\QueryOptions;
use GraphqlOrm\Schema\RootArgumentTypeResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('graphql.manager')]
class GraphqlManager
{
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
        private GraphqlClientInterface $client,
        private EntityHydrator $hydrator,
        private ?GraphqlOrmDataCollector $collector,
        private int $maxDepth,
        private GraphqlQueryDialect $dialect = new DefaultDialect(),
        private ?GraphqlQueryCompiler $compiler = null,
        private ?LoggerInterface $logger = null,
        private ?QueryRedactor $redactor = null,
        private ?RootArgumentTypeResolver $argumentTypeResolver = null,
    ) {
    }

    /**
     * @template E of object
     *
     * @param callable(array<string, mixed>, GraphqlExecutionContext): (E[]|PaginatedResult<E>) $hydration
     * @param array<string, mixed>                                                              $variables
     *
     * @return E[]|PaginatedResult<E>
     */
    public function execute(QueryNode|string $graphql, callable $hydration, QueryOptions $options = new QueryOptions(), array $variables = []): array|PaginatedResult
    {
        $context = new GraphqlExecutionContext(dialect: $this->dialect);

        if ($graphql instanceof QueryNode) {
            $compiledQuery = $this->compile($graphql, $options);
            $compiled = $compiledQuery->graphql;
            $variables = [...$variables, ...$compiledQuery->variables];
            /** @var array<string|int, mixed> $ast */
            $ast = json_decode(json_encode($graphql, JSON_THROW_ON_ERROR), true);
            $context->trace->ast = $ast;
        } else {
            $compiled = $graphql;
        }

        $context->trace->graphql = $compiled;
        $context->trace->variables = $variables;

        try {
            $result = $this->client->query($compiled, $context, $variables);

            $entities = $hydration($result, $context);
            $context->trace->hydratedCount = \count($entities instanceof PaginatedResult ? $entities->items : $entities);
        } finally {
            $trace = $this->redact($context->trace);
            $this->collector?->addQuery($trace);
            $this->log($trace);
        }

        return $entities;
    }

    public function getDialect(): GraphqlQueryDialect
    {
        return $this->dialect;
    }

    public function getMetadataFactory(): GraphqlEntityMetadataFactory
    {
        return $this->metadataFactory;
    }

    public function getHydrator(): EntityHydrator
    {
        return $this->hydrator;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getQueryCompiler(): GraphqlQueryCompiler
    {
        if ($this->compiler !== null) {
            return $this->compiler;
        }

        $walker = $this->dialect->createWalker();

        $this->compiler = new GraphqlQueryCompiler($walker);

        return $this->compiler;
    }

    private function compile(QueryNode $query, QueryOptions $options): CompiledQuery
    {
        $rootField = \count($query->fields) === 1 ? $query->fields[0]->name : null;
        $argumentTypes = $rootField !== null ? $this->argumentTypeResolver?->argumentTypes($rootField) : null;

        if ($argumentTypes === null) {
            return new CompiledQuery($this->getQueryCompiler()->compile($query, $options));
        }

        $compiled = $this->getQueryCompiler()->compileWithVariables($query, $options, $argumentTypes);

        if ($compiled->untypedArguments !== []) {
            $this->logger?->warning('GraphQL ORM: arguments missing from the schema, values are sent inline', [
                'root_field' => $rootField,
                'arguments' => $compiled->untypedArguments,
            ]);
        }

        return $compiled;
    }

    /**
     * The query sent to the API keeps its values: only what is logged or shown in the profiler is redacted.
     */
    private function redact(GraphqlQueryTrace $trace): GraphqlQueryTrace
    {
        if ($this->redactor === null) {
            return $trace;
        }

        $redacted = clone $trace;
        $redacted->graphql = $this->redactor->redactQuery($trace->graphql);
        $redacted->variables = $this->redactor->redactValues($trace->variables);
        $redacted->ast = $trace->ast !== null ? $this->redactor->redactAst($trace->ast) : null;

        return $redacted;
    }

    private function log(GraphqlQueryTrace $trace): void
    {
        if ($this->logger === null) {
            return;
        }

        $context = [
            'endpoint' => $trace->endpoint,
            'duration_ms' => round($trace->duration, 2),
            'response_size' => $trace->responseSize,
            'hydrated_entities' => $trace->hydratedEntities,
            'hydrated_relations' => $trace->hydratedRelations,
            'caller' => $trace->caller,
            'query' => $trace->graphql,
        ];

        if (!empty($trace->errors)) {
            $this->logger->error('GraphQL query returned errors', [
                ...$context,
                'errors' => $trace->errors,
            ]);

            return;
        }

        $this->logger->debug('GraphQL query executed', $context);
    }
}
