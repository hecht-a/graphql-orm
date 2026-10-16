<?php

declare(strict_types=1);

namespace GraphqlOrm\Controller;

use GraphqlOrm\Form\GraphqlEntityType;
use GraphqlOrm\GraphqlManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Answers the search of an autocomplete GraphqlEntityType, as Symfony UX Autocomplete expects: the first entities whose
 * fields contain the query. The entity and its fields come from the URL the form signed, so that nothing else is queried.
 * The results are not paginated: typing more narrows them.
 *
 * @internal
 */
final class EntityAutocompleteController
{
    /** The results without the max_results option of UX Autocomplete, and their ceiling whatever it says. */
    private const DEFAULT_RESULTS = 20;
    private const MAX_RESULTS = 100;

    /** The parameters the form signs; UX Autocomplete adds the query and the page. */
    private const SIGNED_PARAMETERS = ['_hash', 'class', 'fields', 'label', 'max'];

    public function __construct(
        private readonly GraphqlManager $manager,
        private readonly ?UriSigner $uriSigner,
    ) {
    }

    /**
     * @throws AccessDeniedHttpException when the URL was not signed by a form
     * @throws BadRequestHttpException   when the signed parameters name no entity
     */
    public function __invoke(Request $request): JsonResponse
    {
        $signed = array_intersect_key($request->query->all(), array_flip(self::SIGNED_PARAMETERS));

        if ($this->uriSigner === null || !$this->uriSigner->check(GraphqlEntityType::AUTOCOMPLETE_PATH . '?' . http_build_query($signed))) {
            throw new AccessDeniedHttpException('The search of an autocomplete field must come from the URL its form signed.');
        }

        $class = $signed['class'] ?? null;
        $fields = \is_string($signed['fields'] ?? null) ? explode(',', $signed['fields']) : [];
        $label = \is_string($signed['label'] ?? null) ? $signed['label'] : null;

        if (!\is_string($class) || !class_exists($class) || !$this->manager->getMetadataFactory()->isEntityClass($class) || $fields === []) {
            throw new BadRequestHttpException('The search names no entity or no field.');
        }

        $metadata = $this->manager->getMetadataFactory()->getMetadata($class);
        $identifier = $metadata->identifier ?? throw new BadRequestHttpException(\sprintf('"%s" has no identifier.', $class));
        $query = $request->query->getString('query');
        $qb = $this->manager->getRepository($class)->createQueryBuilder();

        if ($query !== '') {
            $qb->where($qb->expr()->orX(...array_map(fn (string $field) => $qb->expr()->contains($field, $query), $fields)));
        }

        $max = is_numeric($signed['max'] ?? null) ? (int) $signed['max'] : self::DEFAULT_RESULTS;
        $result = $qb->limit(max(1, min($max, self::MAX_RESULTS)))->getQuery()->getResult();
        $results = [];

        foreach (\is_array($result) ? $result : $result->items as $entity) {
            $results[] = [
                'value' => self::text((new \ReflectionProperty($entity, $identifier->property))->getValue($entity)),
                'text' => self::text(match (true) {
                    $label !== null => (new \ReflectionProperty($entity, $metadata->property($label)))->getValue($entity),
                    $entity instanceof \Stringable => (string) $entity,
                    default => (new \ReflectionProperty($entity, $identifier->property))->getValue($entity),
                }),
            ];
        }

        return new JsonResponse(['results' => $results, 'next_page' => null]);
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
