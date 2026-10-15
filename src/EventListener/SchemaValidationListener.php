<?php

declare(strict_types=1);

namespace GraphqlOrm\EventListener;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\Exception\SchemaValidationException;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidationMode;
use GraphqlOrm\Schema\SchemaValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 255)]
final class SchemaValidationListener
{
    /**
     * The listener is a shared service: the schema is validated once per container, i.e. once per worker in worker mode.
     */
    private bool $validated = false;

    /** @var list<string>|null the violations found, null until the schema is validated */
    private ?array $violations = null;

    private ?string $failure = null;

    /**
     * @param class-string[] $entityClasses
     */
    public function __construct(
        private readonly SchemaIntrospector $introspector,
        private readonly SchemaValidator $validator,
        private readonly SchemaValidationMode $mode,
        private readonly array $entityClasses,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?GraphqlOrmDataCollector $collector = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        try {
            $this->validate();
        } finally {
            // Reported on every request: the profile of each one tells the outcome of the validation.
            $this->collector?->setSchemaValidation($this->mode->value, $this->violations, $this->failure);
        }
    }

    /**
     * @throws SchemaValidationException in exception mode, when the schema cannot be introspected or does not match
     */
    private function validate(): void
    {
        if ($this->mode === SchemaValidationMode::Disabled || $this->validated) {
            return;
        }

        $this->validated = true;

        if ($this->entityClasses === []) {
            return;
        }

        try {
            $schema = $this->introspector->introspectSchema();
        } catch (\Throwable $e) {
            $message = 'GraphQL schema introspection failed: ' . $e->getMessage();
            $this->failure = $message;

            if ($this->mode === SchemaValidationMode::Exception) {
                throw new SchemaValidationException([$message]);
            }

            $this->logger?->warning($message);

            return;
        }

        $violations = $this->validator->validate($this->entityClasses, $schema['types'], $schema['queryType']);
        $this->violations = array_values($violations);

        if ($violations === []) {
            return;
        }

        if ($this->mode === SchemaValidationMode::Exception) {
            throw SchemaValidationException::withViolations($violations);
        }

        foreach ($violations as $violation) {
            $this->logger?->warning('[GraphQL ORM] Schema violation: ' . $violation);
        }
    }
}
