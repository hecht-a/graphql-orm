<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;

/**
 * Hints for a failing query naming fields the entity does not map: they are sent as is, as the API may accept them
 * (a foreign key such as `user_id`), but are most often typos.
 *
 * @internal
 */
final readonly class UnmappedFieldHints
{
    public function __construct(
        private GraphqlEntityMetadataFactory $metadataFactory,
    ) {
    }

    /**
     * @param class-string     $class
     * @param iterable<string> $paths field names, or paths through relations (`user.name`)
     *
     * @return list<string>
     */
    public function for(string $class, iterable $paths): array
    {
        $hints = [];

        foreach ($paths as $path) {
            $hint = $this->hint($class, $path);

            if ($hint !== null) {
                $hints[$hint] = $hint;
            }
        }

        return array_values($hints);
    }

    /**
     * @param class-string $class
     */
    private function hint(string $class, string $path): ?string
    {
        $metadata = $this->metadataFactory->getMetadata($class);

        foreach (explode('.', $path) as $segment) {
            $field = $metadata->field($segment);

            if ($field === null) {
                $closest = $metadata->closestField($segment);

                return \sprintf('Field "%s" is not mapped on %s%s', $segment, $metadata->class, $closest !== null ? \sprintf(': did you mean "%s"?', $closest) : '.');
            }

            if ($field->relation === null) {
                return null;
            }

            $metadata = $this->metadataFactory->getMetadata($field->relation);
        }

        return null;
    }
}
