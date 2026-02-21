<?php

declare(strict_types=1);

namespace GraphqlOrm\Client;

use GraphqlOrm\Execution\GraphqlExecutionContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('graphql.client')]
interface GraphqlClientInterface
{
    /**
     * @param array<string, mixed> $variables
     *
     * @return string[]
     */
    public function query(
        string $query,
        GraphqlExecutionContext $context,
        array $variables = [],
    ): array;
}
