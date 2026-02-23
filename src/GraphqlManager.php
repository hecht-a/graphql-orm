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
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return T[]
     */
    public function execute(
        string $graphql,
        callable $hydration,
        array $variables = [],
    ): array {
        $context = new GraphqlExecutionContext();

        $context->trace->graphql = $graphql;

        $result = $this->client
            ->query(
                $graphql,
                $context,
                $variables
            );

        $entities = $hydration(
            $result,
            $context
        );

        $this->collector->addQuery(
            $context->trace
        );

        return $entities;
    }

    public function getDialect(): GraphqlQueryDialect
    {
        return $this->dialect;
    }
}
