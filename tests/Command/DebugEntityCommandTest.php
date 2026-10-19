<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Command\DebugEntityCommand;
use GraphqlOrm\Command\EntityClassLocator;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DebugEntityCommandTest extends TestCase
{
    #[Test]
    public function itDescribesTheFieldsAndRelationsInJson(): void
    {
        $tester = $this->tester();

        $tester->execute(['entity' => Task::class, '--format' => 'json']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'class' => Task::class,
            'graphqlRoot' => 'tasks',
            'repository' => null,
            'identifier' => 'id',
            'scalarFields' => [
                ['property' => 'id', 'mappedFrom' => 'id', 'type' => 'int', 'isIdentifier' => true],
                ['property' => 'title', 'mappedFrom' => 'title', 'type' => 'string', 'isIdentifier' => false],
                ['property' => 'done', 'mappedFrom' => 'done', 'type' => 'bool', 'isIdentifier' => false],
                ['property' => 'dueDate', 'mappedFrom' => 'due_date', 'type' => '?DateTimeImmutable', 'isIdentifier' => false],
            ],
            'relationFields' => [
                ['property' => 'user', 'mappedFrom' => 'user', 'targetEntity' => User::class, 'isCollection' => false, 'isIdentifier' => false],
            ],
        ], json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itDescribesTheFieldsAndRelationsInTables(): void
    {
        $tester = $this->tester();

        $tester->execute(['entity' => Task::class]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('GraphQL ORM — Entity "Task"', $display);
        self::assertMatchesRegularExpression('/Manager\s+\(default\)/', $display);
        self::assertMatchesRegularExpression('/id ★\s+id\s+int/', $display);
        self::assertMatchesRegularExpression('/dueDate\s+due_date\s+\?DateTimeImmutable/', $display);
        self::assertMatchesRegularExpression('/user\s+user\s+User\s+object/', $display);
    }

    #[Test]
    public function itNamesTheAvailableEntitiesOfAnUnknownOne(): void
    {
        $tester = $this->tester();

        $tester->execute(['entity' => 'Unknown']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Entity "Unknown" not found.', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        $command = new DebugEntityCommand(new GraphqlEntityMetadataFactory(), new EntityClassLocator(\dirname(__DIR__) . '/Integration/Entity', 'GraphqlOrm\\Tests\\Integration\\Entity'));

        return new CommandTester((new Command('graphqlorm:debug:entity'))->setCode($command));
    }
}
