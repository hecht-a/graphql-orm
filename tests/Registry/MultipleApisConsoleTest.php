<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Registry;

use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\DebugEntityCommand;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\Command\SchemaValidateCommand;
use GraphqlOrm\Dialect\DefaultDialect;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Registry\ManagerRouter;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The commands work with every API: the schema of each one, the mapping of each one.
 */
final class MultipleApisConsoleTest extends TestCase
{
    private const EMPTY_SCHEMA = ['data' => ['__schema' => ['queryType' => ['name' => 'Query'], 'types' => [['name' => 'Query', 'kind' => 'OBJECT', 'fields' => []]]]]];

    private FakeGraphqlClient $mainClient;

    private FakeGraphqlClient $crmClient;

    private GraphqlManagerRegistry $registry;

    private string $outputDir;

    protected function setUp(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/graphql-orm-apis-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->outputDir);
    }

    #[Test]
    public function itValidatesTheMappingOfEveryApiAgainstItsSchema(): void
    {
        $this->managers(self::EMPTY_SCHEMA, self::EMPTY_SCHEMA);

        $tester = $this->schemaValidate();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([1, 1], [$this->mainClient->callCount, $this->crmClient->callCount]);
        self::assertStringContainsString('crm', $tester->getDisplay());
    }

    #[Test]
    public function itValidatesTheMappingOfTheApiGivenByItsManager(): void
    {
        $this->managers(self::EMPTY_SCHEMA, self::EMPTY_SCHEMA);

        $this->schemaValidate()->execute(['--manager' => 'crm']);

        self::assertSame([0, 1], [$this->mainClient->callCount, $this->crmClient->callCount]);
    }

    #[Test]
    public function itGeneratesAnEntityInTheMappingOfItsManagerFromTheSchemaOfItsApi(): void
    {
        $this->managers(self::EMPTY_SCHEMA, $this->contactsSchema());

        $tester = $this->makeEntity();
        $exitCode = $tester->execute(['name' => 'Contact', '--manager' => 'crm', '--from-schema' => true]);

        self::assertSame(Command::SUCCESS, $exitCode, $tester->getDisplay());
        self::assertSame([0, 1], [$this->mainClient->callCount, $this->crmClient->callCount]);
        self::assertStringContainsString('namespace App\\Crm\\Entity;', (string) file_get_contents($this->outputDir . '/Crm/Entity/Contact.php'));
        self::assertFileExists($this->outputDir . '/Crm/Repository/ContactRepository.php');
    }

    #[Test]
    public function itTellsTheManagerOfAnEntity(): void
    {
        $this->managers(self::EMPTY_SCHEMA, self::EMPTY_SCHEMA);
        $locator = new EntityClassLocator('', '', [['dir' => \dirname(__DIR__) . '/Fixtures/Crm', 'namespace' => 'GraphqlOrm\\Tests\\Fixtures\\Crm']]);
        $tester = new CommandTester((new Command('graphqlorm:debug:entity'))->setCode(new DebugEntityCommand(new GraphqlEntityMetadataFactory(), $locator, $this->registry)));

        $tester->execute(['entity' => 'Contact']);

        self::assertMatchesRegularExpression('/Manager\s+crm/', $tester->getDisplay());
    }

    /**
     * @param array<string, mixed> $mainSchema
     * @param array<string, mixed> $crmSchema
     */
    private function managers(array $mainSchema, array $crmSchema): void
    {
        $router = new ManagerRouter(['main' => 'GraphqlOrm\\Tests\\Fixtures\\FakeEntity', 'crm' => 'GraphqlOrm\\Tests\\Fixtures\\Crm'], 'main');
        $this->mainClient = new FakeGraphqlClient($mainSchema);
        $this->crmClient = new FakeGraphqlClient($crmSchema);
        $main = $this->manager('main', $this->mainClient, $router);
        $crm = $this->manager('crm', $this->crmClient, $router);
        $this->registry = new GraphqlManagerRegistry(new ServiceLocator(['main' => static fn () => $main, 'crm' => static fn () => $crm]), $router);
    }

    private function schemaValidate(): CommandTester
    {
        $command = new SchemaValidateCommand(
            new SchemaIntrospector($this->mainClient),
            new SchemaValidator(new GraphqlEntityMetadataFactory()),
            [],
            $this->registry,
            ['main' => [], 'crm' => []],
        );

        return new CommandTester((new Command('graphqlorm:schema:validate'))->setCode($command));
    }

    private function makeEntity(): CommandTester
    {
        $command = new MakeGraphqlEntityCommand(
            $this->outputDir . '/Main/Entity',
            'App\\Main\\Entity',
            $this->outputDir . '/Main/Repository',
            'App\\Main\\Repository',
            new Filesystem(),
            new StubRenderer(\dirname(__DIR__, 2) . '/src/Resources/stubs'),
            new SchemaIntrospector($this->mainClient),
            new SchemaPropertyMapper(),
            $this->registry,
            [
                'crm' => [
                    'entity' => ['dir' => $this->outputDir . '/Crm/Entity', 'namespace' => 'App\\Crm\\Entity'],
                    'repository' => ['dir' => $this->outputDir . '/Crm/Repository', 'namespace' => 'App\\Crm\\Repository'],
                ],
            ],
        );

        return new CommandTester((new Command('graphqlorm:make:entity'))->setCode($command));
    }

    /**
     * @return array<string, mixed>
     */
    private function contactsSchema(): array
    {
        $named = static fn (string $kind, string $name): array => ['kind' => $kind, 'name' => $name, 'ofType' => null];
        $wrapped = static fn (string $kind, array $ofType): array => ['kind' => $kind, 'name' => null, 'ofType' => $ofType];

        return ['data' => ['__schema' => ['queryType' => ['name' => 'Query'], 'types' => [
            ['name' => 'Query', 'kind' => 'OBJECT', 'fields' => [['name' => 'contacts', 'type' => $named('OBJECT', 'ContactConnection')]]],
            ['name' => 'ContactConnection', 'kind' => 'OBJECT', 'fields' => [
                ['name' => 'items', 'type' => $wrapped('NON_NULL', $wrapped('LIST', $wrapped('NON_NULL', $named('OBJECT', 'Contact'))))],
            ]],
            ['name' => 'Contact', 'kind' => 'OBJECT', 'fields' => [
                ['name' => 'id', 'type' => $wrapped('NON_NULL', $named('SCALAR', 'Int'))],
                ['name' => 'name', 'type' => $named('SCALAR', 'String')],
            ]],
        ]]]];
    }

    private function manager(string $name, FakeGraphqlClient $client, ManagerRouter $router): GraphqlManager
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();

        return new GraphqlManager($metadataFactory, $client, new EntityHydrator($metadataFactory), null, 1, new DefaultDialect(), name: $name, router: $router);
    }
}
