<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Query\Ast\QueryNode;

/**
 * @template T of object
 */
final readonly class GraphqlQuery
{
    /**
     * @param class-string<T>   $entityClass
     * @param GraphqlManager<T> $manager
     */
    public function __construct(
        private QueryNode|string $graphql,
        private string $entityClass,
        private GraphqlManager $manager,
    ) {
    }

    public function getGraphQL(): string
    {
        if ($this->graphql instanceof QueryNode) {
            return $this->manager->getQueryCompiler()->compile($this->graphql);
        }

        return $this->graphql;
    }

    /**
     * @return T[]
     */
    public function getResult(): array
    {
        $metadata = $this
            ->manager
            ->metadataFactory
            ->getMetadata($this->entityClass);

        return $this
            ->manager
            ->execute($this->graphql, hydration: function ($result, $context) use ($metadata) {
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

                return array_map(fn ($row) => $this->manager
                        ->hydrator
                        ->hydrate(
                            $metadata,
                            $row,
                            $context
                        ),
                    $rows
                );
            });
    }

    /**
     * @return T|null
     */
    public function getOneOrNullResult(): ?object
    {
        return $this->getResult()[0] ?? null;
    }
}
