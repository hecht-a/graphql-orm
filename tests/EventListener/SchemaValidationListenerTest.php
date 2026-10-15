<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\EventListener;

use GraphqlOrm\DataCollector\GraphqlOrmDataCollector;
use GraphqlOrm\EventListener\SchemaValidationListener;
use GraphqlOrm\Exception\SchemaValidationException;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidationMode;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeValidEntity;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\FakeLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SchemaValidationListenerTest extends TestCase
{
    private FakeLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new FakeLogger();
    }

    #[Test]
    public function itReportsTheViolationsToTheProfilerOnEveryRequest(): void
    {
        $collector = new GraphqlOrmDataCollector();
        $listener = $this->listener(new FakeGraphqlClient(self::schema(withPrice: false)), SchemaValidationMode::Warning, collector: $collector);
        $listener($this->event());
        $collector->reset();

        $listener($this->event());

        $validation = $collector->getSchemaValidation();
        self::assertSame('warning', $validation['mode'] ?? null);
        self::assertCount(1, $validation['violations'] ?? []);
        self::assertStringContainsString('price', ($validation['violations'] ?? [''])[0]);
    }

    #[Test]
    public function itReportsTheModeOnlyWhenTheValidationIsDisabled(): void
    {
        $collector = new GraphqlOrmDataCollector();

        $this->listener(new FakeGraphqlClient(), SchemaValidationMode::Disabled, collector: $collector)($this->event());

        self::assertSame(['mode' => 'disabled', 'violations' => null, 'failure' => null], $collector->getSchemaValidation());
    }

    #[Test]
    public function itAcceptsAMappingMatchingTheSchema(): void
    {
        $client = new FakeGraphqlClient(self::schema(withPrice: true));
        $listener = $this->listener($client, SchemaValidationMode::Exception);

        $listener($this->event());

        self::assertSame(1, $client->callCount);
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function itThrowsOnViolationsInExceptionMode(): void
    {
        $listener = $this->listener(new FakeGraphqlClient(self::schema(withPrice: false)), SchemaValidationMode::Exception);

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('price');
        $listener($this->event());
    }

    #[Test]
    public function itLogsViolationsInWarningMode(): void
    {
        $listener = $this->listener(new FakeGraphqlClient(self::schema(withPrice: false)), SchemaValidationMode::Warning);

        $listener($this->event());

        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertStringContainsString('price', $this->logger->records[0]['message']);
    }

    #[Test]
    public function itThrowsWhenTheSchemaCannotBeIntrospectedInExceptionMode(): void
    {
        $listener = $this->listener(new FakeGraphqlClient(['data' => null]), SchemaValidationMode::Exception);

        $this->expectException(SchemaValidationException::class);
        $this->expectExceptionMessage('introspection failed');
        $listener($this->event());
    }

    #[Test]
    public function itLogsAFailedIntrospectionInWarningMode(): void
    {
        $listener = $this->listener(new FakeGraphqlClient(['data' => null]), SchemaValidationMode::Warning);

        $listener($this->event());

        self::assertStringContainsString('introspection failed', $this->logger->records[0]['message'] ?? '');
    }

    #[Test]
    public function itDoesNothingWhenDisabled(): void
    {
        $client = new FakeGraphqlClient(self::schema(withPrice: false));

        $this->listener($client, SchemaValidationMode::Disabled)($this->event());

        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itIgnoresSubRequests(): void
    {
        $client = new FakeGraphqlClient(self::schema(withPrice: false));

        $this->listener($client, SchemaValidationMode::Exception)($this->event(HttpKernelInterface::SUB_REQUEST));

        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itDoesNotIntrospectWithoutEntities(): void
    {
        $client = new FakeGraphqlClient(self::schema(withPrice: true));

        $this->listener($client, SchemaValidationMode::Exception, entityClasses: [])($this->event());

        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itValidatesTheSchemaOnlyOnce(): void
    {
        $client = new FakeGraphqlClient(self::schema(withPrice: true), self::schema(withPrice: true));
        $listener = $this->listener($client, SchemaValidationMode::Exception);

        $listener($this->event());
        $listener($this->event());

        self::assertSame(1, $client->callCount);
    }

    /**
     * @param class-string[] $entityClasses
     */
    private function listener(FakeGraphqlClient $client, SchemaValidationMode $mode, array $entityClasses = [FakeValidEntity::class], ?GraphqlOrmDataCollector $collector = null): SchemaValidationListener
    {
        return new SchemaValidationListener(
            new SchemaIntrospector($client),
            new SchemaValidator(new GraphqlEntityMetadataFactory()),
            $mode,
            $entityClasses,
            $this->logger,
            $collector,
        );
    }

    private function event(int $requestType = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        return new RequestEvent($kernel, new Request(), $requestType);
    }

    /**
     * @return array<string, mixed>
     */
    private static function schema(bool $withPrice): array
    {
        $scalar = fn (string $name) => ['kind' => 'NON_NULL', 'name' => null, 'ofType' => ['kind' => 'SCALAR', 'name' => $name, 'ofType' => null]];
        $fields = [
            ['name' => 'id', 'type' => $scalar('Int')],
            ['name' => 'name', 'type' => $scalar('String')],
            ['name' => 'active', 'type' => $scalar('Boolean')],
        ];

        if ($withPrice) {
            $fields[] = ['name' => 'price', 'type' => $scalar('Float')];
        }

        return ['data' => ['__schema' => ['queryType' => ['name' => 'Query'], 'types' => [
            ['name' => 'Query', 'kind' => 'OBJECT', 'fields' => [['name' => 'products', 'type' => ['kind' => 'OBJECT', 'name' => 'Product', 'ofType' => null]]]],
            ['name' => 'Product', 'kind' => 'OBJECT', 'fields' => $fields],
        ]]]];
    }
}
