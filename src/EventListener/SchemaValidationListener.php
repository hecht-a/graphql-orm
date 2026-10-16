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
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @internal
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 255)]
final class SchemaValidationListener
{
    private const PROFILER_NAME = 'schema_validation';

    private const CACHE_KEY_PREFIX = 'graphql_orm.schema_validation.';

    private const DEFAULT_TTL = 3600;

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
        private readonly ?CacheInterface $cache = null,
        private readonly int $ttl = self::DEFAULT_TTL,
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

        [$violations, $this->failure] = $this->outcome();

        if ($this->failure !== null) {
            if ($this->mode === SchemaValidationMode::Exception) {
                throw new SchemaValidationException([$this->failure]);
            }

            $this->logger?->warning($this->failure);

            return;
        }

        $this->violations = $violations;

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

    /**
     * The violations, or why the schema could not be introspected. Cached when a cache is given: in a dev environment
     * running a container per request, the schema is not introspected on every request. Editing an entity changes the
     * key, so that a fixed mapping is checked again at once.
     *
     * @return array{list<string>, string|null}
     */
    private function outcome(): array
    {
        if ($this->cache === null) {
            return $this->introspectAndValidate();
        }

        $startedAt = hrtime(true);
        $introspected = false;
        /** @var array{list<string>, string|null} $outcome */
        $outcome = $this->cache->get(self::CACHE_KEY_PREFIX . $this->fingerprint(), function (ItemInterface $item) use (&$introspected): array {
            $item->expiresAfter($this->ttl);
            $introspected = true;

            return $this->introspectAndValidate();
        });
        $this->collector?->introspectionCacheLookup(self::PROFILER_NAME, $introspected, (hrtime(true) - $startedAt) / 1e6);

        return $outcome;
    }

    /**
     * @return array{list<string>, string|null}
     */
    private function introspectAndValidate(): array
    {
        try {
            $schema = $this->introspector->introspectSchema();
        } catch (\Throwable $e) {
            return [[], 'GraphQL schema introspection failed: ' . $e->getMessage()];
        }

        return [array_values($this->validator->validate($this->entityClasses, $schema['types'], $schema['queryType'])), null];
    }

    /**
     * The entity classes and the last change of their files.
     */
    private function fingerprint(): string
    {
        $files = array_map(
            static function (string $class): string {
                $file = class_exists($class) ? (new \ReflectionClass($class))->getFileName() : false;

                return $class . '@' . ($file !== false ? (string) filemtime($file) : '');
            },
            $this->entityClasses,
        );

        return hash('xxh128', implode('|', $files));
    }
}
