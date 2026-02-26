<?php

declare(strict_types=1);

namespace GraphqlOrm\Repository;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\Execution\GraphqlExecutionContext;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\GraphqlQueryBuilder;
use GraphqlOrm\Query\GraphqlQueryStringBuilder;

/**
 * @template T of object
 */
class GraphqlEntityRepository
{
    /**
     * @param GraphqlManager<T> $manager
     * @param class-string<T>   $entityClass
     */
    public function __construct(
        protected readonly GraphqlManager $manager,
        protected string $entityClass,
    ) {
    }

    /**
     * @return GraphqlQueryBuilder<T>
     */
    public function createQueryBuilder(): GraphqlQueryBuilder
    {
        return new GraphqlQueryBuilder(
            $this->entityClass,
            $this->manager
        );
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return T[]
     */
    public function findBy(array $criteria): array
    {
        $metadata = $this
            ->manager
            ->metadataFactory
            ->getMetadata($this->entityClass);

        $fields = array_map(
            fn ($f) => $f->mappedFrom,
            $metadata->fields
        );

        $graphql = (new GraphqlQueryStringBuilder($this->manager))
            ->entity($this->entityClass)
            ->root($metadata->name)
            ->arguments($criteria)
            ->fields($fields)
            ->build();

        /** @var T[] $result */
        $result = $this
            ->manager
            ->execute($graphql, hydration: function (array $result, GraphqlExecutionContext $context) use ($metadata) {
                $dialect = $this->manager->getDialect();
                $data = $result['data'] ?? [];

                if (!\array_key_exists($metadata->name, $data)) {
                    return [];
                }

                $root = $data[$metadata->name];

                if ($root === null) {
                    return [];
                }

                if (!\is_array($root)) {
                    throw InvalidGraphqlResponseException::expectedArray($root);
                }

                $collection = $dialect->extractCollection($root);
                $rows = !empty($collection) ? $collection : null;

                if ($rows === null) {
                    return [];
                }

                if (!\is_array($rows)) {
                    throw InvalidGraphqlResponseException::expectedArray($rows);
                }

                if (array_is_list($rows) === false) {
                    $rows = [$rows];
                }

                return array_map(
                    fn ($row) => $this->manager
                        ->hydrator
                        ->hydrate(
                            $metadata,
                            $row,
                            $context
                        ),
                    $rows
                );
            });

        return $result;
    }

    /**
     * @return T[]
     */
    public function findAll(): array
    {
        return $this->findBy([]);
    }
}
