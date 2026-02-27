<?php

declare(strict_types=1);

namespace GraphqlOrm\Exception;

final class SchemaValidationException extends \RuntimeException implements GraphqlOrmException
{
    /** @var string[] */
    private array $violations;

    /**
     * @param string[] $violations
     */
    public function __construct(array $violations)
    {
        $this->violations = $violations;

        $list = implode("\n  - ", $violations);

        parent::__construct("GraphQL schema validation failed:\n  - " . $list);
    }

    /**
     * @return string[]
     */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /**
     * @param string[] $violations
     */
    public static function withViolations(array $violations): self
    {
        return new self($violations);
    }
}
