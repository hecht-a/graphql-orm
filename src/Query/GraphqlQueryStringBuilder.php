<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\LogicException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;

/**
 * @template T of object
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

    /**
     * @param GraphqlManager<T> $manager
     */
    public function __construct(
        private readonly GraphqlManager $manager,
    ) {
    }

    /**
     * @return GraphqlQueryStringBuilder<T>
     */
    public function root(string $name): self
    {
        $this->root = $name;

        return $this;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return GraphqlQueryStringBuilder<T>
     */
    public function arguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    /**
     * @param string[] $fields
     *
     * @return GraphqlQueryStringBuilder<T>
     */
    public function fields(array $fields, ?bool $manualSelect = false): self
    {
        $this->fields = $fields;
        $this->manualSelect = $manualSelect;

        return $this;
    }

    /**
     * @param class-string $entityClass
     *
     * @return GraphqlQueryStringBuilder<T>
     */
    public function entity(string $entityClass): self
    {
        $this->entityClass = $entityClass;

        return $this;
    }

    public function build(): QueryNode
    {
        $query = new QueryNode();

        $root = new FieldNode(
            $this->root,
            $this->arguments,
            new SelectionSetNode()
        );

        if ($this->manualSelect) {
            $tree = $this->buildSelectionTree($this->fields);
            $selection = $this->buildFromTreeAst($this->entityClass, $tree);
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
     * @param class-string $entityClass
     */
    private function buildAllFieldsAst(string $entityClass): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        if (isset($this->visited[$entityClass])) {
            return $selection;
        }

        $this->visited[$entityClass] = true;

        try {
            $metadata = $this
                    ->manager
                    ->metadataFactory
                    ->getMetadata($entityClass);

            foreach ($metadata->fields as $field) {
                if ($field->relation !== null) {
                    $nested = $this->buildAllFieldsAst($field->relation);

                    if ($nested->fields === []) {
                        $selection->add($this->relationFallbackAst($field));

                        continue;
                    }

                    $selection->add(new FieldNode($field->mappedFrom, [], $nested));

                    continue;
                }

                $selection->add(new FieldNode($field->mappedFrom));
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

        $metadata = $this->manager->metadataFactory->getMetadata($this->entityClass);

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
     * @param class-string         $entityClass
     * @param array<string, mixed> $tree
     */
    private function buildFromTreeAst(string $entityClass, array $tree, ): SelectionSetNode
    {
        $selection = new SelectionSetNode();

        $metadata = $this
            ->manager
            ->metadataFactory
            ->getMetadata($entityClass);

        foreach ($tree as $fieldName => $children) {
            if (!\is_array($children)) {
                continue;
            }

            $field = $this->findFieldMetadata($metadata, $fieldName);

            if ($field === null) {
                $selection->add(new FieldNode($fieldName));

                continue;
            }

            if ($field->relation === null) {
                $selection->add(new FieldNode($field->mappedFrom));

                continue;
            }

            $explicit = isset($children['__explicit']);

            unset($children['__explicit']);

            $relationMetadata = $this
                    ->manager
                    ->metadataFactory
                    ->getMetadata($field->relation);

            if ($relationMetadata->identifier !== null) {
                $identifier = $relationMetadata->identifier->mappedFrom;

                $children[$identifier] ??= [];
            }

            if ($explicit) {
                $nested = $this->buildAllFieldsAst($field->relation);

                if ($nested->fields === []) {
                    $selection->add($this->relationFallbackAst($field));

                    continue;
                }

                $selection->add(new FieldNode($field->mappedFrom, [], $nested));

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

            $selection->add(new FieldNode($field->mappedFrom, [], $nested));
        }

        return $selection;
    }

    private function relationFallbackAst(GraphqlFieldMetadata $field): FieldNode
    {
        if ($field->relation === null) {
            throw new LogicException('Relation metadata requested on non relation field.');
        }

        $relationMetadata = $this
                ->manager
                ->metadataFactory
                ->getMetadata($field->relation);

        $identifier = $relationMetadata->identifier?->mappedFrom ?? 'id';

        $selection = new SelectionSetNode();
        $selection->add(new FieldNode($identifier));

        return new FieldNode($field->mappedFrom, [], $selection);
    }

    private function findFieldMetadata(GraphqlEntityMetadata $metadata, string $name): ?GraphqlFieldMetadata
    {
        foreach ($metadata->fields as $field) {
            if ($field->mappedFrom === $name) {
                return $field;
            }

            if ($field->property === $name) {
                return $field;
            }
        }

        return null;
    }
}
