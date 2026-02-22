<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidGraphqlResponseException;
use GraphqlOrm\GraphqlManager;

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
        private string $graphql,
        private string $entityClass,
        private GraphqlManager $manager,
    ) {
    }

    public function getGraphQL(): string
    {
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
                $rows = $result['data'][$metadata->name] ?? null;

                if ($rows === null) {
                    return [];
                }

                if (!\is_array($rows)) {
                    throw InvalidGraphqlResponseException::expectedArray($rows);
                }

                if ($rows && array_is_list($rows) === false) {
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
