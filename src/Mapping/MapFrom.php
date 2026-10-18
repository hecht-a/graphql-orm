<?php

declare(strict_types=1);

namespace GraphqlOrm\Mapping;

/**
 * Maps a parameter of the constructor of a DTO, hydrated with hydrateAs(), to a field of the entity queried: another
 * name, or a field of a relation by its path (assignee.name). Without it, the parameter maps the field of its name.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class MapFrom
{
    public function __construct(
        /** The PHP property or GraphQL name of the field, a path through relations for a nested one */
        public string $path,
    ) {
    }
}
