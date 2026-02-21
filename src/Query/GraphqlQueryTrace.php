<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

final class GraphqlQueryTrace
{
    public string $graphql;
    /** @var array<string, mixed> */
    public array $variables = [];
    /** @var array{
     *   file: string,
     *   line: int|null,
     *   class: string|null,
     *   function: string|null
     * }|null */
    public ?array $caller = null;
    public ?string $endpoint = null;
    public int $responseSize = 0;
    // TODO
    //    /** @var array<string, mixed>|null  */
    //    public ?array $errors = null;
    public int $hydratedCount = 0;
    public int $depthUsed = 0;
    public int $hydratedEntities = 0;
    public int $hydratedRelations = 0;
    public int $hydratedCollections = 0;
    public int $hydrationMaxDepth = 0;
}
