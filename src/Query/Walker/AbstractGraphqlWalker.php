<?php

declare(strict_types=1);

namespace GraphqlOrm\Query\Walker;

use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\Query\Ast\FieldNode;
use GraphqlOrm\Query\Ast\QueryNode;
use GraphqlOrm\Query\Ast\SelectionSetNode;
use GraphqlOrm\Query\CompiledQuery;
use GraphqlOrm\Query\EnumLiteral;
use GraphqlOrm\Query\GraphqlName;
use GraphqlOrm\Query\Printer\GraphqlPrinter;
use GraphqlOrm\Query\QueryOptions;

abstract class AbstractGraphqlWalker implements VariableAwareWalkerInterface
{
    protected GraphqlPrinter $printer;

    protected QueryOptions $options;

    private bool $useVariables = false;

    /** @var array<string, string> */
    private array $argumentTypes = [];

    /** @var array<string, string> */
    private array $variableDefinitions = [];

    /** @var array<string, mixed> */
    private array $variables = [];

    /** @var list<string> */
    private array $untypedArguments = [];

    public function walk(QueryNode $query, QueryOptions $options): string
    {
        $this->options = $options;
        $this->variableDefinitions = [];
        $this->variables = [];
        $this->untypedArguments = [];

        $this->printer = new GraphqlPrinter();
        $this->printer->indent();

        foreach ($query->fields as $field) {
            // The fields of a mutation answer with one entity, without the envelope of a collection; their values are
            // inline, the input types the API names them with being unknown.
            if ($query->operation === 'mutation') {
                $this->walkField($field);
            } else {
                $this->walkRootField($field);
            }
        }

        return $this->operationHeader($query->operation, $query->name) . " {\n" . $this->printer->get() . "\n}";
    }

    public function walkWithVariables(QueryNode $query, QueryOptions $options, array $argumentTypes): CompiledQuery
    {
        $this->useVariables = true;
        $this->argumentTypes = $argumentTypes;

        try {
            $graphql = $this->walk($query, $options);

            return new CompiledQuery($graphql, $this->variables, $this->untypedArguments);
        } finally {
            $this->useVariables = false;
            $this->argumentTypes = [];
        }
    }

    abstract protected function walkRootField(FieldNode $field): void;

    /**
     * @param array<string, mixed> $arguments
     */
    protected function formatRootArguments(array $arguments): string
    {
        if (!$this->useVariables || $arguments === []) {
            return $this->formatArguments($arguments);
        }

        $untyped = array_values(array_diff(array_keys($arguments), array_keys($this->argumentTypes)));

        if ($untyped !== []) {
            $this->untypedArguments = $untyped;

            return $this->formatArguments($arguments);
        }

        $pairs = [];

        foreach ($arguments as $name => $value) {
            $name = $this->assertValidName($name);
            $this->variableDefinitions[$name] = $this->argumentTypes[$name];
            $this->variables[$name] = $this->toVariableValue($value);
            $pairs[] = \sprintf('%s: $%s', $name, $name);
        }

        return '(' . implode(', ', $pairs) . ')';
    }

    protected function walkField(FieldNode $field, bool $isRoot = false): void
    {
        $args = $isRoot ? $this->formatRootArguments($field->arguments) : $this->formatArguments($field->arguments);
        $name = ($field->alias !== null ? $this->assertValidName($field->alias) . ': ' : '') . $field->name;

        if ($field->selectionSet === null) {
            $this->printer->line($name . $args);

            return;
        }

        $this->printer->line($name . $args . ' {');

        $this->printer->indent();

        if ($field->isCollection) {
            $this->walkCollection($field->selectionSet);
        } else {
            $this->walkSelectionSet($field->selectionSet);
        }

        $this->printer->outdent();

        $this->printer->line('}');
    }

    protected function formatValue(mixed $value): string
    {
        if ($value instanceof EnumLiteral) {
            return $value->name;
        }

        if (\is_string($value)) {
            return $this->formatString($value);
        }

        if (\is_int($value) || \is_float($value)) {
            return $this->formatNumber($value);
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (\is_array($value)) {
            if (array_is_list($value)) {
                $items = array_map(fn ($v) => $this->formatValue($v), $value);

                return '[' . implode(', ', $items) . ']';
            }

            $fields = [];
            foreach ($value as $key => $item) {
                $fields[] = $this->assertValidName($key) . ': ' . $this->formatValue($item);
            }

            return '{ ' . implode(', ', $fields) . ' }';
        }

        if ($value instanceof \BackedEnum) {
            return $this->formatEnum($value);
        }

        if ($value instanceof \DateTimeInterface) {
            return $this->formatString($value->format(DATE_ATOM));
        }

        throw new InvalidArgumentException(\sprintf('Unsupported GraphQL argument type "%s".', get_debug_type($value)));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    protected function formatArguments(array $arguments): string
    {
        if ($arguments === []) {
            return '';
        }

        $pairs = [];

        foreach ($arguments as $key => $value) {
            if ($this->omitsArgument($value)) {
                continue;
            }

            $pairs[] = \sprintf('%s: %s', $this->assertValidName($key), $this->formatValue($value));
        }

        return '(' . implode(', ', $pairs) . ')';
    }

    /**
     * A backed enum sent as an argument, as a string literal by default: a dialect whose schema declares the enum
     * prints its value as an enum literal.
     */
    protected function formatEnum(\BackedEnum $value): string
    {
        return $this->formatString((string) $value->value);
    }

    /**
     * Whether an argument is left out of the query, as a null some APIs refuse: none by default.
     */
    protected function omitsArgument(mixed $value): bool
    {
        return false;
    }

    /**
     * GraphQL string escapes are a subset of JSON ones, so a JSON-encoded string is a valid GraphQL string literal.
     */
    protected function formatString(string $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws InvalidArgumentException when the value has no GraphQL literal (INF, NAN)
     */
    protected function formatNumber(int|float $value): string
    {
        if (\is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Non-finite floats (INF, NAN) cannot be sent as GraphQL arguments.');
        }

        return (string) $value;
    }

    /**
     * @throws InvalidArgumentException when the name could break out of the generated query
     */
    protected function assertValidName(string|int $name): string
    {
        if (!\is_string($name) || !GraphqlName::isValid($name)) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid GraphQL name.', $name));
        }

        return $name;
    }

    private function operationHeader(string $operation, ?string $name): string
    {
        $operation .= $name !== null ? ' ' . $this->assertValidName($name) : '';

        if ($this->variableDefinitions === []) {
            return $operation;
        }

        $definitions = array_map(
            fn (string $name, string $type) => \sprintf('$%s: %s', $name, $type),
            array_keys($this->variableDefinitions),
            $this->variableDefinitions,
        );

        return $operation . '(' . implode(', ', $definitions) . ')';
    }

    /**
     * Variables are sent as JSON: enums and dates become their scalar representation.
     */
    private function toVariableValue(mixed $value): mixed
    {
        return match (true) {
            \is_array($value) => array_map(fn (mixed $item) => $this->toVariableValue($item), $value),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof EnumLiteral => $value->name,
            $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
            \is_float($value) => is_finite($value) ? $value : throw new InvalidArgumentException('Non-finite floats (INF, NAN) cannot be sent as GraphQL arguments.'),
            default => $value,
        };
    }

    /**
     * Prints the selection of the entities of a nested collection, inside the dialect envelope.
     */
    protected function walkCollection(SelectionSetNode $selectionSet): void
    {
        $this->printer->line('items {');
        $this->printer->indent();
        $this->walkSelectionSet($selectionSet);
        $this->printer->outdent();
        $this->printer->line('}');
    }

    protected function walkSelectionSet(SelectionSetNode $selectionSet): void
    {
        foreach ($selectionSet->fields as $child) {
            $this->walkField($child);
        }
    }
}
