<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Mapping\Fetch;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\Expr\FilterExpressionInterface;

/**
 * @internal
 */
final class GraphqlQueryStringBuilder
{
    private string $root;
    /** @var array<string, mixed> */
    private array $arguments = [];
    /** @var string[] */
    private array $fields = [];
    /** @var class-string */
    private string $entityClass;
    private ?bool $manualSelect = false;
    /** @var array<string, bool> */
    private array $visited = [];
    private QueryOptions $options;
    private ?FilterExpressionInterface $filter = null;
    /** @var array<string, mixed>|null */
    private ?array $with = null;

    public function __construct(
        private readonly GraphqlManager $manager,
    ) {
        $this->options = new QueryOptions();
    }

    public function root(string $name): self
    {
        $this->root = $name;

        return $this;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function arguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    /**
     * @param string[] $fields
     */
    public function fields(array $fields, ?bool $manualSelect = false): self
    {
        $this->fields = $fields;
        $this->manualSelect = $manualSelect;

        return $this;
    }

    /**
     * @param class-string $entityClass
     */
    public function entity(string $entityClass): self
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    /**
     * The relations to load in place of max_depth, as a tree of GraphQL names; null to follow max_depth.
     *
     * @param array<string, mixed>|null $with
     */
    public function with(?array $with): self
    {
        $this->with = $with;

        return $this;
    }

    public function options(QueryOptions $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function filter(?FilterExpressionInterface $filter): self
    {
        $this->filter = $filter;

        return $this;
    }

    public function build(): QueryNode
    {
        $query = new QueryNode();

        $dialect = $this->manager->getDialect();

        $options = $dialect->applyQueryOptions($this->arguments, $this->options);
        $filter = $dialect->applyFilter($this->filter);
        $args = [...$options, ...$filter];

        $root = new FieldNode(
            $this->root,
            $args,
            new SelectionSetNode(),
        );

        if ($this->manualSelect) {
            $tree = $this->buildSelectionTree($this->fields);
            $selection = $this->buildFromTreeAst($this->entityClass, $tree);

            if ($this->with !== null) {
                $this->mergeAst($selection, $this->buildWithAst($this->entityClass, $this->with, relationsOnly: true));
            }
        } elseif ($this->with !== null) {
            $selection = $this->buildWithAst($this->entityClass, $this->with);
        } else {
            $selection = $this->buildAllFieldsAst($this->entityClass);
        }

        foreach ($selection->fields as $field) {
            $root->selectionSet?->add($field);
        }

        $query->fields[] = $root;

        return $query;
    }

    /**
     * Relations of an entity at or beyond the configured max depth only get their identifier selected.
     *
     * @param class-string $entityClass
     */
    private function buildAllFieldsAst(string $entityClass, int $depth = 0): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        if (isset($this->visited[$entityClass])) {
            return $selection;
        }

        $this->visited[$entityClass] = true;

        try {
            $metadata = $this
                    ->manager
                    ->getMetadataFactory()
                    ->getMetadata($entityClass);

            foreach ($metadata->fields as $field) {
                if ($field->embedded !== null) {
                    $this->addEmbedded($selection, $field);

                    continue;
                }

                if ($field->relation !== null) {
                    if ($depth >= $this->manager->getMaxDepth() || $field->fetch === Fetch::Lazy) {
                        $selection->add($this->relationFallbackAst($field));

                        continue;
                    }

                    $nested = $this->buildAllFieldsAst($field->relation, $depth + 1);

                    if ($nested->fields === []) {
                        $selection->add($this->relationFallbackAst($field));

                        continue;
                    }

                    $selection->add(new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));

                    continue;
                }

                $selection->add(new FieldNode($field->mappedFrom, isCollection: $field->isCollection));
            }

            return $selection;
        } finally {
            unset($this->visited[$entityClass]);
        }
    }

    /**
     * @param string[] $fields
     *
     * @return array<string, mixed>
     */
    private function buildSelectionTree(array $fields): array
    {
        /** @var array<string, mixed> $tree */
        $tree = [];

        $metadata = $this->manager->getMetadataFactory()->getMetadata($this->entityClass);

        if ($metadata->identifier !== null) {
            $identifier = $metadata->identifier->mappedFrom;
            $tree[$identifier] ??= [];
        }

        foreach ($fields as $field) {
            $parts = explode('.', $field);

            /** @var array<string, mixed> $current */
            $current = &$tree;

            foreach ($parts as $index => $part) {
                if (!isset($current[$part]) || !\is_array($current[$part])) {
                    $current[$part] = [];
                }

                if ($index === \count($parts) - 1 && \count($parts) === 1) {
                    $current[$part]['__explicit'] = true;
                }

                /** @var array<string, mixed> $current */
                $current = &$current[$part];
            }
        }

        return $tree;
    }

    /**
     * @param class-string $entityClass
     * @param array<mixed> $tree
     */
    private function buildFromTreeAst(string $entityClass, array $tree, ): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        $metadata = $this
            ->manager
            ->getMetadataFactory()
            ->getMetadata($entityClass);

        foreach ($tree as $fieldName => $children) {
            if (!\is_array($children)) {
                continue;
            }

            $fieldName = (string) $fieldName;

            $field = $metadata->field($fieldName);

            if ($field === null) {
                $selection->add(new FieldNode($fieldName));

                continue;
            }

            if ($field->embedded !== null) {
                unset($children['__explicit']);
                $this->addEmbedded($selection, $field, $children !== [] ? array_map(strval(...), array_keys($children)) : null);

                continue;
            }

            if ($field->relation === null) {
                $selection->add(new FieldNode($field->mappedFrom, isCollection: $field->isCollection));

                continue;
            }

            $explicit = isset($children['__explicit']);

            unset($children['__explicit']);

            $relationMetadata = $this
                    ->manager
                    ->getMetadataFactory()
                    ->getMetadata($field->relation);

            if ($relationMetadata->identifier !== null) {
                $identifier = $relationMetadata->identifier->mappedFrom;

                $children[$identifier] ??= [];
            }

            if ($explicit) {
                $nested = $this->with !== null
                    ? $this->buildWithAst($field->relation, [])
                    : $this->buildAllFieldsAst($field->relation, depth: 1);

                if ($nested->fields === []) {
                    $selection->add($this->relationFallbackAst($field));

                    continue;
                }

                $selection->add(new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));

                continue;
            }

            if ($this->manualSelect && $children === []) {
                $selection->add($this->relationFallbackAst($field));

                continue;
            }

            $nested = $this->buildFromTreeAst($field->relation, $children);

            if ($nested->fields === []) {
                $selection->add($this->relationFallbackAst($field));

                continue;
            }

            $selection->add(new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));
        }

        return $selection;
    }

    /**
     * The fields of an entity with the relations of with() loaded, whatever max_depth and Fetch::Lazy: the others only
     * get their identifier selected.
     *
     * @param class-string         $entityClass
     * @param array<string, mixed> $with
     * @param bool                 $relationsOnly the relations of with() alone, to add to a selection of fields
     */
    private function buildWithAst(string $entityClass, array $with, bool $relationsOnly = false): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        $metadata = $this
            ->manager
            ->getMetadataFactory()
            ->getMetadata($entityClass);

        foreach ($metadata->fields as $field) {
            if ($field->relation === null) {
                if (!$relationsOnly) {
                    $field->embedded !== null
                        ? $this->addEmbedded($selection, $field)
                        : $selection->add(new FieldNode($field->mappedFrom, isCollection: $field->isCollection));
                }

                continue;
            }

            if (!isset($with[$field->mappedFrom])) {
                if (!$relationsOnly) {
                    $selection->add($this->relationFallbackAst($field));
                }

                continue;
            }

            /** @var array<string, mixed> $relations */
            $relations = $with[$field->mappedFrom];
            $nested = $this->buildWithAst($field->relation, $relations);

            $selection->add($nested->fields === []
                ? $this->relationFallbackAst($field)
                : new FieldNode($field->mappedFrom, [], $nested, $field->isCollection));
        }

        return $selection;
    }

    /**
     * Adds the fields of a selection missing from another, merging the selections of the fields they share.
     */
    private function mergeAst(SelectionSetNode $selection, SelectionSetNode $other): void
    {
        foreach ($other->fields as $field) {
            $same = array_find($selection->fields, static fn (FieldNode $known): bool => $known->name === $field->name);

            if ($same === null) {
                $selection->add($field);

                continue;
            }

            if ($same->selectionSet !== null && $field->selectionSet !== null) {
                $this->mergeAst($same->selectionSet, $field->selectionSet);
            }
        }
    }

    /**
     * The fields of an embedded value object: a GraphQL object, or prefixed fields of the entity.
     *
     * @param list<string>|null $only the fields selected, GraphQL names or PHP properties; every field when null
     */
    private function addEmbedded(SelectionSetNode $selection, GraphqlFieldMetadata $field, ?array $only = null): void
    {
        $embedded = $field->embedded;
        \assert($embedded !== null);
        $fields = array_filter(
            $embedded->fields,
            static fn (GraphqlFieldMetadata $embeddedField): bool => $only === null || \in_array($embeddedField->mappedFrom, $only, true) || \in_array($embeddedField->property, $only, true),
        );

        if ($embedded->prefix !== null) {
            foreach ($fields as $embeddedField) {
                $selection->add(new FieldNode($embedded->graphqlName($embeddedField), isCollection: $embeddedField->isCollection));
            }

            return;
        }

        $object = new SelectionSetNode();

        foreach ($fields as $embeddedField) {
            $object->add(new FieldNode($embeddedField->mappedFrom, isCollection: $embeddedField->isCollection));
        }

        $selection->add(new FieldNode($field->mappedFrom, [], $object));
    }

    private function relationFallbackAst(GraphqlFieldMetadata $field): FieldNode
    {
        if ($field->relation === null) {
            throw new LogicException('Relation metadata requested on non relation field.');
        }

        $relationMetadata = $this
                ->manager
                ->getMetadataFactory()
                ->getMetadata($field->relation);

        $identifier = $relationMetadata->identifier->mappedFrom ?? 'id';

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode($identifier, isCollection: $field->isCollection));

        return new FieldNode($field->mappedFrom, [], $selection, isCollection: $field->isCollection);
    }
}
