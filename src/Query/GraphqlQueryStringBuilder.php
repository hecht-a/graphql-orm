<?php

declare(strict_types=1);

namespace GraphqlOrm\Query;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlFieldMetadata;

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

    private function buildArguments(): string
    {
        if (!$this->arguments) {
            return '';
        }

        $args = [];

        foreach ($this->arguments as $name => $value) {
            $args[] = $name . ': ' . $this->formatValue($value);
        }

        return '(' . implode(', ', $args) . ')';
    }

    public function build(): string
    {
        if ($this->manualSelect) {
            $tree = $this->buildSelectionTree($this->fields);
            $selection = $this->buildFromTree($this->entityClass, $tree, 2);
        } else {
            $selection = $this->buildAllFields($this->entityClass, 2) ?? '';
        }

        $args = $this->buildArguments();
        $dialect = $this->manager->getDialect();
        $selection = $dialect->wrapCollection($selection, 2);

        return <<<GRAPHQL
query {
  {$this->root}{$args} {
{$selection}
  }
}
GRAPHQL;
    }

    /**
     * @param class-string $entityClass
     */
    private function buildAllFields(string $entityClass, int $level): ?string
    {
        if (isset($this->visited[$entityClass])) {
            return null;
        }

        $this->visited[$entityClass] = true;

        try {
            $metadata = $this->manager->metadataFactory->getMetadata($entityClass);
            $dialect = $this->manager->getDialect();
            $lines = [];

            foreach ($metadata->fields as $field) {
                $indent = str_repeat('  ', $level);

                if ($field->relation !== null) {
                    $nested = $this->buildAllFields($field->relation, $level + 1);

                    if ($nested === null || $nested === '') {
                        $lines[] = $indent . $this->relationFallbackSelection($field, $level);
                        continue;
                    }

                    if ($field->isCollection) {
                        $lines[] = $indent . $field->mappedFrom . " {\n" . $dialect->wrapCollection($nested, $level) . "\n" . $indent . '}';
                    } else {
                        $lines[] = $indent . $field->mappedFrom . " {\n" . $nested . "\n" . $indent . '}';
                    }

                    continue;
                }

                $lines[] = $indent . $field->mappedFrom;
            }

            return implode("\n", $lines);
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
    private function buildFromTree(string $entityClass, array $tree, int $level): string
    {
        $metadata = $this->manager->metadataFactory->getMetadata($entityClass);

        $lines = [];

        foreach ($tree as $fieldName => $children) {
            if (!\is_array($children)) {
                continue;
            }

            $indent = str_repeat('  ', $level);
            $field = $this->findFieldMetadata($metadata, $fieldName);

            if ($field === null) {
                $lines[] = $indent . $fieldName;
                continue;
            }

            if ($field->relation !== null) {
                $explicit = isset($children['__explicit']);
                unset($children['__explicit']);

                $relationMetadata = $this->manager->metadataFactory->getMetadata($field->relation);

                if ($relationMetadata->identifier !== null) {
                    $identifier = $relationMetadata->identifier->mappedFrom;

                    $children[$identifier] ??= [];
                }

                if ($explicit) {
                    $nested = $this->buildAllFields($field->relation, $level + 1);

                    if (!$nested) {
                        $lines[] = $indent . $this->relationFallbackSelection($field, $level);

                        continue;
                    }

                    $lines[] = $indent . $field->mappedFrom . " {\n" . $nested . "\n" . $indent . '}';

                    continue;
                }

                if ($this->manualSelect && !$children) {
                    $lines[] = $indent . $this->relationFallbackSelection($field, $level);

                    continue;
                }

                $nested = $this->buildFromTree($field->relation, $children, $level + 1);

                if ($nested === '') {
                    $lines[] = $indent . $this->relationFallbackSelection($field, $level);

                    continue;
                }

                $lines[] = $indent . $field->mappedFrom . " {\n" . $nested . "\n" . $indent . '}';

                continue;
            }

            $lines[] = $indent . $field->mappedFrom;
        }

        return implode("\n", $lines);
    }

    private function relationFallbackSelection(GraphqlFieldMetadata $field, int $level): string
    {
        if ($field->relation === null) {
            return $field->mappedFrom;
        }
        $dialect = $this->manager->getDialect();

        $relationMetadata = $this->manager->metadataFactory->getMetadata($field->relation);

        $identifier = $relationMetadata->identifier?->mappedFrom ?? 'id';

        $indent = str_repeat('  ', $level);

        $inner = str_repeat('  ', $level + 1);

        if ($field->isCollection) {
            return $field->mappedFrom . " {\n" . $inner . $dialect->wrapCollection($identifier, $level) . "\n" . $indent . '}';
        }

        return $field->mappedFrom . " {\n" . $inner . $identifier . "\n" . $indent . '}';
    }

    private function findFieldMetadata(GraphqlEntityMetadata $metadata, string $name): ?GraphqlFieldMetadata
    {
        foreach ($metadata->fields as $field) {
            if ($field->mappedFrom === $name || $field->property === $name) {
                return $field;
            }
        }

        return null;
    }

    private function formatValue(mixed $value): string
    {
        if (\is_string($value)) {
            return '"' . addslashes($value) . '"';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (\is_array($value)) {
            return '[' . implode(
                ', ',
                array_map(
                    fn ($v) => $this->formatValue($v),
                    $value
                )
            ) . ']';
        }

        if (\is_scalar($value)) {
            return (string) $value;
        }

        throw new InvalidArgumentException(\sprintf('Invalid GraphQL argument value of type "%s".', get_debug_type($value)));
    }
}
