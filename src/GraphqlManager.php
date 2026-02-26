<?php

declare(strict_types=1);

namespace GraphqlOrm;

use GraphqlOrm\Client\GraphqlClientInterface;
use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\Dialect\GraphqlQueryDialect;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\GraphqlQueryCompiler;
use GraphqlOrm\Query\Pagination\PaginatedResult;
use GraphqlOrm\Query\QueryOptions;
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
        public GraphqlOrmDataCollector $collector,
        public int $maxDepth,
        public GraphqlQueryDialect $dialect = new DefaultDialect(),
        private ?GraphqlQueryCompiler $compiler = null,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return T[]|PaginatedResult<T>
     */
    public function execute(QueryNode|string $graphql, callable $hydration, QueryOptions $options = new QueryOptions(), array $variables = []): array|PaginatedResult
    {
        $context = new GraphqlExecutionContext();

        if ($graphql instanceof QueryNode) {
            $compiled = $this->getQueryCompiler()->compile($graphql, $options);
            /** @var array<string|int, mixed> $ast */
            $ast = json_decode(json_encode($graphql, JSON_THROW_ON_ERROR), true);
            $context->trace->ast = $ast;
        } else {
            $compiled = $graphql;
        }

        $context->trace->graphql = $compiled;

        try {
            $result = $this->client
                ->query(
                    $compiled,
                    $context,
                    $variables
                );

            $entities = $hydration(
                $result,
                $context
            );
        } catch (\Throwable $e) {
            throw $e;
        } finally {
            $this->collector->addQuery(
                $context->trace
            );
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
}
