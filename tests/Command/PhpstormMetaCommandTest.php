<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Command\PhpstormMetaCommand;
use GraphqlOrm\GraphqlManager;
use GraphqlOrm\Hydrator\EntityHydrator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Registry\GraphqlManagerRegistry;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use GraphqlOrm\Tests\Fixtures\With\Company;
use GraphqlOrm\Tests\Fixtures\With\Label;
use GraphqlOrm\Tests\Fixtures\With\Task;
use GraphqlOrm\Tests\Fixtures\With\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class PhpstormMetaCommandTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/graphql-orm-phpstorm-meta-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectDir);
    }

    #[Test]
    public function itWritesTheMetadataInTheDirectoryOfPhpstorm(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $file = $this->projectDir . '/.phpstorm.meta.php/graphql_orm.php';
        self::assertFileExists($file);
        self::assertStringContainsString("registerArgumentsSet('graphql_orm_fields'", (string) file_get_contents($file));
        self::assertStringContainsString('4 entities', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));
    }

    #[Test]
    public function itWritesTheMetadataWhereItIsAsked(): void
    {
        $this->tester()->execute(['--output' => $this->projectDir . '/meta/fields.meta.php']);

        self::assertFileExists($this->projectDir . '/meta/fields.meta.php');
    }

    #[Test]
    public function itRefusesToReplaceAPhpstormMetaFile(): void
    {
        file_put_contents($this->projectDir . '/.phpstorm.meta.php', "<?php\n");
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertSame("<?php\n", file_get_contents($this->projectDir . '/.phpstorm.meta.php'));
        self::assertStringContainsString('--output', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $metadataFactory = new GraphqlEntityMetadataFactory();
        $manager = new GraphqlManager($metadataFactory, new FakeGraphqlClient(), new EntityHydrator($metadataFactory), null, 1);
        $command = new PhpstormMetaCommand(
            GraphqlManagerRegistry::forManager($manager),
            ['default' => [Task::class, User::class, Company::class, Label::class]],
            $this->projectDir,
        );

        return new CommandTester((new Command('graphqlorm:phpstorm-meta'))->setCode($command));
    }
}
