<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Command;

use GraphqlOrm\Command\SchemaValidateCommand;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Schema\SchemaIntrospector;
use GraphqlOrm\Schema\SchemaValidator;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeAddress;
use GraphqlOrm\Tests\Fixtures\FakeGraphqlClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SchemaValidateCommandTest extends TestCase
{
    #[Test]
    public function itSucceedsWhenTheMappingMatchesTheSchema(): void
    {
        $tester = $this->createTester(new FakeGraphqlClient($this->schemaResponse(['id', 'city'])));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
    }

    #[Test]
    public function itFailsAndListsTheViolations(): void
    {
        $tester = $this->createTester(new FakeGraphqlClient($this->schemaResponse(['id'])));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('city', $tester->getDisplay());
    }

    #[Test]
    public function itFailsWhenTheSchemaCannotBeIntrospected(): void
    {
        $tester = $this->createTester(new FakeGraphqlClient(['data' => null]));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('introspection', strtolower($tester->getDisplay()));
    }

    private function createTester(FakeGraphqlClient $client): CommandTester
    {
        $command = new SchemaValidateCommand(
            new SchemaIntrospector($client),
            new SchemaValidator(new GraphqlEntityMetadataFactory()),
            [FakeAddress::class],
        );

        // Same wrapping as the console DI pass, available since symfony/console 7.3.
        return new CommandTester((new Command('graphqlorm:schema:validate'))->setCode($command));
    }

    /**
     * @param string[] $addressFields
     *
     * @return array<string, mixed>
     */
    private function schemaResponse(array $addressFields): array
    {
        $scalar = fn (string $name, string $type) => ['name' => $name, 'type' => ['name' => $type, 'kind' => 'SCALAR']];
        $fieldTypes = ['id' => 'Int', 'city' => 'String'];

        return ['data' => ['__schema' => [
            'queryType' => ['name' => 'Query'],
            'types' => [
                [
                    'name' => 'Query',
                    'kind' => 'OBJECT',
                    'fields' => [['name' => 'addresses', 'type' => ['name' => 'PostalAddress', 'kind' => 'OBJECT']]],
                ],
                [
                    'name' => 'PostalAddress',
                    'kind' => 'OBJECT',
                    'fields' => array_map(fn (string $field) => $scalar($field, $fieldTypes[$field]), $addressFields),
                ],
            ],
        ]]];
    }
}
