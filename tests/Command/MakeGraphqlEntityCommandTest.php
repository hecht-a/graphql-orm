<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Codegen\StubRenderer;
use GraphqlOrm\Command\MakeGraphqlEntityCommand;
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
        self::assertStringContainsString("#[GraphqlField(mappedFrom: 'title')]", $this->entityCode());
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

    private function createTester(): CommandTester
    {
        $command = new MakeGraphqlEntityCommand(
            $this->outputDir . '/Entity',
            'App\GraphQL\Entity',
            $this->outputDir . '/Repository',
            'App\GraphQL\Repository',
            $this->filesystem,
            new StubRenderer(\dirname(__DIR__, 2) . '/src/Resources/stubs'),
        );

        // Same wrapping as the console DI pass, available since symfony/console 7.3.
        return new CommandTester((new Command('graphqlorm:make:entity'))->setCode($command));
    }

    private function entityCode(): string
    {
        return (string) file_get_contents($this->outputDir . '/Entity/Task.php');
    }
}
