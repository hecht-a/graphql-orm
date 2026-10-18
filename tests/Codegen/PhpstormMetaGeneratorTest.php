<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Codegen;

use GraphqlOrm\Codegen\PhpstormMetaGenerator;
use GraphqlOrm\Metadata\GraphqlEntityMetadata;
use GraphqlOrm\Metadata\GraphqlEntityMetadataFactory;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeProject;
use GraphqlOrm\Tests\Fixtures\FakeRepository\FakeProjectRepository;
use GraphqlOrm\Tests\Fixtures\With\Company;
use GraphqlOrm\Tests\Fixtures\With\Label;
use GraphqlOrm\Tests\Fixtures\With\Task;
use GraphqlOrm\Tests\Fixtures\With\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhpstormMetaGeneratorTest extends TestCase
{
    private string $meta;

    protected function setUp(): void
    {
        $factory = new GraphqlEntityMetadataFactory();
        $this->meta = (new PhpstormMetaGenerator())->generate(
            [Task::class, User::class, Company::class, Label::class, FakeProject::class],
            static fn (string $class): GraphqlEntityMetadata => $factory->getMetadata($class),
        );
    }

    #[Test]
    public function itGeneratesValidPhp(): void
    {
        // TOKEN_PARSE throws a ParseError on invalid PHP.
        self::assertNotEmpty(token_get_all($this->meta, \TOKEN_PARSE));
        self::assertStringStartsWith("<?php\n", $this->meta);
        self::assertStringContainsString('namespace PHPSTORM_META {', $this->meta);
    }

    #[Test]
    public function itRegistersTheFieldsOfEveryEntity(): void
    {
        self::assertStringContainsString("registerArgumentsSet('graphql_orm_fields', 'assignee', 'company', 'id', 'label', 'name', 'reviewer', 'title');", $this->meta);
    }

    #[Test]
    public function itRegistersTheFieldsOfTheRelationsAsPaths(): void
    {
        self::assertStringContainsString("'assignee.company', 'assignee.id', 'assignee.name'", $this->meta);
        self::assertStringContainsString("'label.id', 'label.name'", $this->meta);
        self::assertStringContainsString("registerArgumentsSet('graphql_orm_relations', 'assignee', 'assignee.company', 'company', 'label', 'reviewer', 'reviewer.company');", $this->meta);
    }

    #[Test]
    public function itCompletesTheFieldsOfTheQueryBuilderAndOfTheExpressions(): void
    {
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Query\\GraphqlQueryBuilder::orderBy(), 0, argumentsSet('graphql_orm_fields'));", $this->meta);
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Query\\GraphqlQueryBuilder::select(), 0, argumentsSet('graphql_orm_paths'));", $this->meta);
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Query\\GraphqlQueryBuilder::with(), 1, argumentsSet('graphql_orm_relations'));", $this->meta);
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Query\\Expr\\ExpressionBuilder::eq(), 0, argumentsSet('graphql_orm_paths'));", $this->meta);
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Query\\Expr\\ExpressionBuilder::notIn(), 0, argumentsSet('graphql_orm_paths'));", $this->meta);
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Repository\\GraphqlEntityRepository::sum(), 0, argumentsSet('graphql_orm_fields'));", $this->meta);
        self::assertStringContainsString("expectedArguments(\\GraphqlOrm\\Repository\\GraphqlEntityRepository::findBy(), 3, argumentsSet('graphql_orm_fields'));", $this->meta);
    }

    #[Test]
    public function itCompletesTheFieldsOfItsEntityOnACustomRepository(): void
    {
        $set = 'graphql_orm_fields_GraphqlOrm_Tests_Fixtures_FakeEntity_FakeProject';

        self::assertStringContainsString("registerArgumentsSet('" . $set . "', 'id', 'name');", $this->meta);
        self::assertStringContainsString('expectedArguments(\\' . FakeProjectRepository::class . "::max(), 0, argumentsSet('" . $set . "'));", $this->meta);
        self::assertStringContainsString('expectedArguments(\\' . FakeProjectRepository::class . "::findBy(), 3, argumentsSet('" . $set . "'));", $this->meta);
    }
}
