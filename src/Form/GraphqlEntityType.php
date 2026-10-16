<?php

declare(strict_types=1);

namespace GraphqlOrm\Form;

use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A choice among the entities of a class, as Doctrine EntityType: the choices are loaded once the form needs them, with
 * findAll() or the "query_builder" option, and the identifiers are the submitted values.
 */
final class GraphqlEntityType extends AbstractType
{
    /** The endpoint answering the search of an autocomplete field, see Resources/config/routes.php. */
    public const AUTOCOMPLETE_PATH = EntityAutocompleteController::PATH;

    /**
     * @param UriSigner|null $uriSigner signs the search of the autocomplete fields, so that the endpoint queries nothing else
     */
    public function __construct(
        private readonly GraphqlManager $manager,
        private readonly ?UriSigner $uriSigner = null,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('class');
        $resolver->setAllowedTypes('class', 'string');
        $resolver->setDefault('query_builder', null);
        $resolver->setAllowedTypes('query_builder', ['null', 'callable']);

        $resolver->setDefault('choice_loader', fn (Options $options) => new GraphqlEntityChoiceLoader($this->manager, self::entityClass($options), $options['query_builder']));

        // Symfony UX Autocomplete: the field searches the endpoint of the bundle, and loads only the entity submitted.
        $resolver->setDefault('autocomplete', false);
        $resolver->setAllowedTypes('autocomplete', 'bool');
        $resolver->setDefault('search_fields', null);
        $resolver->setAllowedTypes('search_fields', ['null', 'string[]']);
        $resolver->setDefault('choice_lazy', fn (Options $options) => $options['autocomplete'] === true);
        $resolver->setDefault('autocomplete_url', fn (Options $options) => $options['autocomplete'] === true ? $this->searchUrl($options) : null);
        $resolver->setDefault('choice_value', fn (Options $options) => fn (?object $entity) => $this->identifierValue(self::entityClass($options), $entity));
        $resolver->setDefault('choice_label', fn (Options $options) => fn (object $entity) => $entity instanceof \Stringable
            ? (string) $entity
            : $this->identifierValue(self::entityClass($options), $entity));
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    /**
     * @return class-string
     *
     * @throws InvalidArgumentException when the "class" option is not a class
     */
    private static function entityClass(Options $options): string
    {
        $class = $options['class'];

        return \is_string($class) && class_exists($class)
            ? $class
            : throw new InvalidArgumentException(\sprintf('The "class" option of "%s" must be the class of a GraphQL entity.', self::class));
    }

    /**
     * The signed URL of the search: the endpoint only queries the entity and the fields a form asked for.
     *
     * @throws LogicException when the fields to search are not given, or the UriSigner of FrameworkBundle is missing
     */
    private function searchUrl(Options $options): string
    {
        $fields = $options['search_fields'];

        if (!\is_array($fields) || $fields === []) {
            throw new LogicException(\sprintf('An autocomplete "%s" needs the "search_fields" option: the fields whose value contains the search.', self::class));
        }

        if ($this->uriSigner === null) {
            throw new LogicException(\sprintf('An autocomplete "%s" needs the UriSigner of FrameworkBundle to sign its search.', self::class));
        }

        $parameters = ['class' => self::entityClass($options), 'fields' => implode(',', array_filter($fields, 'is_string'))];

        if (\is_string($options['choice_label'])) {
            $parameters['label'] = $options['choice_label'];
        }

        // The max_results option of Symfony UX Autocomplete, when it is installed.
        if ($options->offsetExists('max_results') && \is_int($options['max_results'])) {
            $parameters['max'] = $options['max_results'];
        }

        return $this->uriSigner->sign(self::AUTOCOMPLETE_PATH . '?' . http_build_query($parameters));
    }

    /**
     * @param class-string $class
     *
     * @throws LogicException when the entity has no identifier
     */
    private function identifierValue(string $class, ?object $entity): ?string
    {
        if ($entity === null) {
            return null;
        }

        $identifier = $this->manager->getMetadataFactory()->getMetadata($class)->identifier
            ?? throw new LogicException(\sprintf('"%s" has no identifier to tell its choices apart: mark its identifier field with #[Identifier].', $class));
        $property = new \ReflectionProperty($entity, $identifier->property);
        $value = $property->isInitialized($entity) ? $property->getValue($entity) : null;

        return \is_scalar($value) ? (string) $value : null;
    }
}
