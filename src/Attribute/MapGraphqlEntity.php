<?php

declare(strict_types=1);

namespace GraphqlOrm\Attribute;

use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use Symfony\Component\HttpKernel\Attribute\ValueResolver;

/**
 * Tells how to find the entity of a controller argument, as Doctrine #[MapEntity] does.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class MapGraphqlEntity extends ValueResolver
{
    /**
     * @param string|null                $id       route parameter holding the identifier, instead of the argument name or "id"
     * @param array<string, string>|null $mapping  route parameter => GraphQL field name or PHP property, the entity being found with findOneBy()
     * @param bool                       $disabled true to leave the argument to the other resolvers
     */
    public function __construct(
        public readonly ?string $id = null,
        public readonly ?array $mapping = null,
        bool $disabled = false,
    ) {
        parent::__construct(GraphqlEntityValueResolver::class, $disabled);
    }
}
