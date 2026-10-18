<?php

declare(strict_types=1);

namespace GraphqlOrm\Metadata;

/**
 * A DTO hydrated with hydrateAs(): the parameters of its constructor, each mapped to a field of the entity queried.
 *
 * @internal
 */
final readonly class DtoMetadata
{
    /**
     * @param class-string               $class
     * @param list<DtoParameterMetadata> $parameters
     */
    public function __construct(
        public string $class,
        public array $parameters,
    ) {
    }

    /**
     * The fields to select, as paths of GraphQL names: those of the parameters, those of the nested DTOs under their
     * relation.
     *
     * @return list<string>
     */
    public function selection(): array
    {
        $paths = [];

        foreach ($this->parameters as $parameter) {
            $path = implode('.', $parameter->path);
            $nested = $parameter->nested?->selection() ?? [];

            if ($nested === []) {
                $paths[] = $path;

                continue;
            }

            foreach ($nested as $nestedPath) {
                $paths[] = $path . '.' . $nestedPath;
            }
        }

        return $paths;
    }
}
