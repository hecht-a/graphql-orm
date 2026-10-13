<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\ValueResolver;

use GraphqlOrm\Attribute\MapGraphqlEntity;
use GraphqlOrm\Dialect\DataApiBuilderDialect;
use GraphqlOrm\Exception\InvalidArgumentException;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeTask;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\ValueResolver\GraphqlEntityValueResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestAttributeValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class GraphqlEntityValueResolverTest extends TestCase
{
    private const TASK = ['data' => ['tasks' => ['items' => [['id' => 3, 'title' => 'A']]]]];

    private const NO_TASK = ['data' => ['tasks' => ['items' => []]]];

    #[Test]
    public function itFindsTheEntityByTheRouteParameterNamedAfterTheArgument(): void
    {
        $client = new FakeGraphqlClient(self::TASK);

        $resolved = $this->resolver($client)->resolve(new Request(attributes: ['task' => '3']), self::argument());

        self::assertCount(1, $resolved);
        self::assertInstanceOf(FakeTask::class, $resolved[0]);
        self::assertSame(3, $resolved[0]->id);
        self::assertStringContainsString('filter: { id: { eq: 3 } }', $client->lastQuery);
    }

    #[Test]
    public function itFindsTheEntityByTheIdRouteParameter(): void
    {
        $client = new FakeGraphqlClient(self::TASK);

        $resolved = $this->resolver($client)->resolve(new Request(attributes: ['id' => '3']), self::argument());

        self::assertInstanceOf(FakeTask::class, $resolved[0] ?? null);
    }

    #[Test]
    public function itRespondsNotFoundWhenTheEntityDoesNotExist(): void
    {
        $resolver = $this->resolver(new FakeGraphqlClient(self::NO_TASK));

        $this->expectException(NotFoundHttpException::class);

        $resolver->resolve(new Request(attributes: ['task' => '3']), self::argument());
    }

    #[Test]
    public function itGivesNullToANullableArgumentWhenTheEntityDoesNotExist(): void
    {
        $resolver = $this->resolver(new FakeGraphqlClient(self::NO_TASK));

        self::assertSame([null], $resolver->resolve(new Request(attributes: ['task' => '3']), self::argument(nullable: true)));
    }

    #[Test]
    public function itRespondsNotFoundWithoutQueryForAnIdentifierOfTheWrongType(): void
    {
        $client = new FakeGraphqlClient(self::TASK);

        try {
            $this->resolver($client)->resolve(new Request(attributes: ['task' => 'abc']), self::argument());
            self::fail('A non-numeric identifier must not match an integer one.');
        } catch (NotFoundHttpException) {
            self::assertSame(0, $client->callCount);
        }
    }

    #[Test]
    public function itLeavesTheArgumentsThatAreNotEntitiesToTheOtherResolvers(): void
    {
        $client = new FakeGraphqlClient();

        $resolved = $this->resolver($client)->resolve(new Request(attributes: ['task' => '3']), new ArgumentMetadata('task', \stdClass::class, false, false, null));

        self::assertSame([], $resolved);
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itLeavesTheArgumentToTheOtherResolversWithoutIdentifierInTheRoute(): void
    {
        $client = new FakeGraphqlClient();

        self::assertSame([], $this->resolver($client)->resolve(new Request(), self::argument()));
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itReadsTheIdentifierFromTheRouteParameterGivenByTheAttribute(): void
    {
        $client = new FakeGraphqlClient(self::TASK);
        $request = new Request(attributes: ['id' => '1', 'taskId' => '3']);

        $resolved = $this->resolver($client)->resolve($request, self::argument(attribute: new MapGraphqlEntity(id: 'taskId')));

        self::assertInstanceOf(FakeTask::class, $resolved[0] ?? null);
        self::assertStringContainsString('filter: { id: { eq: 3 } }', $client->lastQuery);
    }

    #[Test]
    public function itFindsTheEntityByTheFieldsMappedFromTheRoute(): void
    {
        $client = new FakeGraphqlClient(self::TASK);
        $request = new Request(attributes: ['task' => '9', 'name' => 'A']);

        $resolved = $this->resolver($client)->resolve($request, self::argument(attribute: new MapGraphqlEntity(mapping: ['name' => 'title'])));

        self::assertInstanceOf(FakeTask::class, $resolved[0] ?? null);
        self::assertStringContainsString('filter: { title: { eq: "A" } }', $client->lastQuery);
    }

    #[Test]
    public function itMapsTheRouteParametersOnPropertyNames(): void
    {
        $client = new FakeGraphqlClient(self::TASK);
        $argument = new ArgumentMetadata('task', Task::class, false, false, null, true, [new MapGraphqlEntity(mapping: ['due' => 'dueDate'])]);

        $this->resolver($client)->resolve(new Request(attributes: ['due' => '2026-01-15']), $argument);

        self::assertStringContainsString('filter: { due_date: { eq: "2026-01-15" } }', $client->lastQuery);
    }

    #[Test]
    public function itConvertsTheIntegerFieldsMappedFromTheRoute(): void
    {
        $client = new FakeGraphqlClient(self::TASK);

        $this->resolver($client)->resolve(new Request(attributes: ['number' => '3']), self::argument(attribute: new MapGraphqlEntity(mapping: ['number' => 'id'])));

        self::assertStringContainsString('filter: { id: { eq: 3 } }', $client->lastQuery);
    }

    #[Test]
    public function itDoesNotResolveAnArgumentWhoseAttributeDisablesIt(): void
    {
        $client = new FakeGraphqlClient(self::TASK);

        $resolved = $this->resolver($client)->resolve(new Request(attributes: ['task' => '3']), self::argument(attribute: new MapGraphqlEntity(disabled: true)));

        self::assertSame([], $resolved);
        self::assertSame(0, $client->callCount);
    }

    #[Test]
    public function itRejectsAMappingOnAFieldTheEntityDoesNotMap(): void
    {
        $resolver = $this->resolver(new FakeGraphqlClient(self::TASK));

        $this->expectException(InvalidArgumentException::class);

        $resolver->resolve(new Request(attributes: ['name' => 'A']), self::argument(attribute: new MapGraphqlEntity(mapping: ['name' => 'label'])));
    }

    #[Test]
    public function itTargetsTheResolverOfTheBundle(): void
    {
        self::assertSame(GraphqlEntityValueResolver::class, (new MapGraphqlEntity())->resolver);
    }

    #[Test]
    public function itIsTargetedByTheAttributeInTheArgumentResolverOfSymfony(): void
    {
        $client = new FakeGraphqlClient(self::TASK);
        $argumentResolver = $this->argumentResolver($client);

        $arguments = $argumentResolver->getArguments(
            new Request(attributes: ['taskId' => '3']),
            fn (#[MapGraphqlEntity(id: 'taskId')] FakeTask $task) => $task,
        );

        self::assertInstanceOf(FakeTask::class, $arguments[0] ?? null);
    }

    #[Test]
    public function itLeavesADisabledArgumentToTheOtherResolversOfSymfony(): void
    {
        $client = new FakeGraphqlClient(self::TASK);
        $argumentResolver = $this->argumentResolver($client);

        $arguments = $argumentResolver->getArguments(
            new Request(attributes: ['task' => '3']),
            fn (#[MapGraphqlEntity(disabled: true)] FakeTask $task) => $task,
        );

        self::assertSame(['3'], $arguments);
        self::assertSame(0, $client->callCount);
    }

    private function argumentResolver(FakeGraphqlClient $client): ArgumentResolver
    {
        $resolver = $this->resolver($client);

        return new ArgumentResolver(
            null,
            [$resolver, new RequestAttributeValueResolver()],
            new ServiceLocator([GraphqlEntityValueResolver::class => fn () => $resolver]),
        );
    }

    private static function argument(bool $nullable = false, ?MapGraphqlEntity $attribute = null): ArgumentMetadata
    {
        return new ArgumentMetadata('task', FakeTask::class, false, false, null, $nullable, $attribute !== null ? [$attribute] : []);
    }

    private function resolver(FakeGraphqlClient $client): GraphqlEntityValueResolver
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlEntityValueResolver(new GraphqlManager(
            $metadataFactory,
            $client,
            new EntityHydrator($metadataFactory),
            null,
            2,
            new DataApiBuilderDialect(),
        ));
    }
}
