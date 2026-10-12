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
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\GraphqlQueryTrace;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Query\QueryOptions;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * @template T of object
 */
#[AutoconfigureTag('graphql.manager')]
class GraphqlManager
{
    /**
     * @param GraphqlEntityMetadataFactory<T> $metadataFactory
     * @param EntityHydrator<T>               $hydrator
     */
    public function __construct(
        public GraphqlEntityMetadataFactory $metadataFactory,
        public GraphqlClientInterface $client,
        public EntityHydrator $hydrator,
        public ?GraphqlOrmDataCollector $collector,
        public int $maxDepth,
        public GraphqlQueryDialect $dialect = new DefaultDialect(),
        private ?GraphqlQueryCompiler $compiler = null,
        private ?LoggerInterface $logger = null,
        private ?QueryRedactor $redactor = null,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return T[]|PaginatedResult<T>
     */
    public function execute(QueryNode|string $graphql, callable $hydration, QueryOptions $options = new QueryOptions(), array $variables = []): array|PaginatedResult
    {
        $context = new GraphqlExecutionContext(dialect: $this->dialect);

        if ($graphql instanceof QueryNode) {
            $compiled = $this->getQueryCompiler()->compile($graphql, $options);
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

    public function getQueryCompiler(): GraphqlQueryCompiler
    {
        if ($this->compiler !== null) {
            return $this->compiler;
        }

        $walker = $this->dialect->createWalker();

        $this->compiler = new GraphqlQueryCompiler($walker);

        return $this->compiler;
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
