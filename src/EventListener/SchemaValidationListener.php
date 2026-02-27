<?php

declare(strict_types=1);

namespace GraphqlOrm\EventListener;

use GraphqlOrm\Exception\SchemaValidationException;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidationMode;
use GraphqlOrm\Schema\SchemaValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @template T of object
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 255)]
final class SchemaValidationListener
{
    private static bool $validated = false;

    /**
     * @param class-string[]     $entityClasses
     * @param SchemaValidator<T> $validator
     */
    public function __construct(
        private readonly SchemaIntrospector $introspector,
        private readonly SchemaValidator $validator,
        private readonly SchemaValidationMode $mode,
        private readonly array $entityClasses,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($this->mode === SchemaValidationMode::Disabled) {
            return;
        }

        if (self::$validated) {
            return;
        }

        self::$validated = true;

        if ($this->entityClasses === []) {
            return;
        }

        try {
            $schemaTypes = $this->introspector->introspect();
        } catch (\Throwable $e) {
            $message = 'GraphQL schema introspection failed: ' . $e->getMessage();

            if ($this->mode === SchemaValidationMode::Exception) {
                throw new SchemaValidationException([$message]);
            }

            $this->logger?->warning($message);

            return;
        }

        $violations = $this->validator->validate($this->entityClasses, $schemaTypes);

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
