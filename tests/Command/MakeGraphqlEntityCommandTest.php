<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Codegen\SchemaPropertyMapper;
use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
use GraphqlOrm\Converter\TypeConverterRegistry;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Tests\Fixtures\Converter\CentsConverter;
use GraphqlOrm\Tests\Fixtures\Converter\SiretConverter;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class MakeGraphqlEntityCommandTest extends TestCase
{
    private Filesystem $filesystem;

    private string $outputDir;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->outputDir = sys_get_temp_dir() . '/graphql-orm-make-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->outputDir);
    }

    #[Test]
    public function itGeneratesAnEntityWithAValidField(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['yes', 'title', 'title', 'no', 'string', 'no', 'no']);

        $exitCode = $tester->execute(['name' => 'Task']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString("#[GraphqlOrm\\Field(mappedFrom: 'title')]", $this->entityCode());
    }

    #[Test]
    public function itTypesTheGeneratedRepositoryThroughGenericsOnly(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['no']);

        $tester->execute(['name' => 'Task']);

        $repositoryCode = (string) file_get_contents($this->outputDir . '/Repository/TaskRepository.php');
        self::assertStringContainsString('@extends ServiceGraphqlEntityRepository<Task>', $repositoryCode);
        self::assertStringContainsString('parent::__construct($registry, Task::class);', $repositoryCode);
        self::assertStringNotContainsString('@method', $repositoryCode);
    }

    #[Test]
    public function itRejectsAGraphqlFieldNameThatWouldBreakTheGeneratedCode(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['yes', 'title', "ti'tle", 'no']);

        $exitCode = $tester->execute(['name' => 'Task']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString("ti'tle", $this->entityCode());
    }

    #[Test]
    public function itRejectsAPropertyNameThatIsNotAValidPhpIdentifier(): void
    {
        $tester = $this->createTester();
        $tester->setInputs(['yes', 'due-date', 'no']);

        $exitCode = $tester->execute(['name' => 'Task']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringNotContainsString('due-date', $this->entityCode());
    }

    #[Test]
    public function itGeneratesAnEntityFromTheSchemaWithoutQuestions(): void
    {
        $tester = $this->createTester(new FakeGraphqlClient(self::taskSchema()));

        $exitCode = $tester->execute(['name' => 'Task', '--from-schema' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $code = $this->entityCode();
        self::assertStringContainsString("#[GraphqlOrm\\Field(mappedFrom: 'id')]\n    #[GraphqlOrm\\Identifier]\n    private int \$id;", $code);
        self::assertStringContainsString("#[GraphqlOrm\\Field(mappedFrom: 'title')]\n    private ?string \$title = null;", $code);
        self::assertStringContainsString("#[GraphqlOrm\\Relation(mappedFrom: 'owner')]\n    private ?User \$owner = null;", $code);
        self::assertStringContainsString('use GraphqlOrm\\Mapping as GraphqlOrm;', $code);
        self::assertStringContainsString("#[GraphqlOrm\\Entity(name: 'tasks', repositoryClass: TaskRepository::class)]", $code);
        self::assertStringNotContainsString('use GraphqlOrm\\Mapping\\', $code);
        // TOKEN_PARSE throws a ParseError on invalid PHP.
        self::assertNotEmpty(token_get_all($code, \TOKEN_PARSE));
        self::assertStringContainsString('use App\GraphQL\Entity\User;', $code);
        self::assertStringContainsString('User', $tester->getDisplay());
    }

    #[Test]
    public function itTypesTheCustomScalarsOfAConverterWithItsValueObject(): void
    {
        $tester = $this->createTester(new FakeGraphqlClient(self::taskSchema([
            ['name' => 'siret', 'type' => ['kind' => 'SCALAR', 'name' => 'Siret', 'ofType' => null]],
            ['name' => 'amount', 'type' => ['kind' => 'SCALAR', 'name' => 'Int', 'ofType' => null]],
        ])), new TypeConverterRegistry([new SiretConverter(), new CentsConverter()]));

        self::assertSame(Command::SUCCESS, $tester->execute(['name' => 'Task', '--from-schema' => true]));

        $code = $this->entityCode();
        self::assertStringContainsString("#[GraphqlOrm\\Field(mappedFrom: 'siret')]\n    private ?Siret \$siret = null;", $code);
        self::assertStringContainsString('use GraphqlOrm\Tests\Fixtures\Converter\Siret;', $code);
        self::assertStringContainsString("#[GraphqlOrm\\Field(mappedFrom: 'amount')]\n    private ?int \$amount = null;", $code);
    }

    #[Test]
    public function itFailsWhenTheSchemaHasNoTypeForTheRoot(): void
    {
        $tester = $this->createTester(new FakeGraphqlClient(self::taskSchema()));

        $exitCode = $tester->execute(['name' => 'Invoice', '--from-schema' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('invoices', $tester->getDisplay());
        self::assertFileDoesNotExist($this->outputDir . '/Entity/Invoice.php');
    }

    /**
     * @param list<array<string, mixed>> $taskFields fields added to Task
     *
     * @return array<string, mixed>
     */
    private static function taskSchema(array $taskFields = []): array
    {
        $named = fn (string $kind, string $name) => ['kind' => $kind, 'name' => $name, 'ofType' => null];
        $wrapped = fn (string $kind, array $ofType) => ['kind' => $kind, 'name' => null, 'ofType' => $ofType];

        return ['data' => ['__schema' => ['queryType' => ['name' => 'Query'], 'types' => [
            ['name' => 'Query', 'kind' => 'OBJECT', 'fields' => [['name' => 'tasks', 'type' => $named('OBJECT', 'TaskConnection')]]],
            ['name' => 'TaskConnection', 'kind' => 'OBJECT', 'fields' => [
                ['name' => 'items', 'type' => $wrapped('NON_NULL', $wrapped('LIST', $wrapped('NON_NULL', $named('OBJECT', 'Task'))))],
            ]],
            ['name' => 'Task', 'kind' => 'OBJECT', 'fields' => [
                ['name' => 'id', 'type' => $wrapped('NON_NULL', $named('SCALAR', 'Int'))],
                ['name' => 'title', 'type' => $named('SCALAR', 'String')],
                ['name' => 'owner', 'type' => $named('OBJECT', 'User')],
                ...$taskFields,
            ]],
            ['name' => 'User', 'kind' => 'OBJECT', 'fields' => [['name' => 'id', 'type' => $wrapped('NON_NULL', $named('SCALAR', 'Int'))]]],
        ]]]];
    }

    private function createTester(?FakeGraphqlClient $schemaClient = null, TypeConverterRegistry $typeConverters = new TypeConverterRegistry()): CommandTester
    {
        $command = new MakeGraphqlEntityCommand(
            $this->outputDir . '/Entity',
            'App\GraphQL\Entity',
            $this->outputDir . '/Repository',
            'App\GraphQL\Repository',
            $this->filesystem,
            new StubRenderer(\dirname(__DIR__, 2) . '/src/Resources/stubs'),
            new SchemaIntrospector($schemaClient ?? new FakeGraphqlClient()),
            new SchemaPropertyMapper(),
            typeConverters: $typeConverters,
        );

        // Same wrapping as the console DI pass, available since symfony/console 7.3.
        return new CommandTester((new Command('graphqlorm:make:entity'))->setCode($command));
    }

    private function entityCode(): string
    {
        return (string) file_get_contents($this->outputDir . '/Entity/Task.php');
    }
}
