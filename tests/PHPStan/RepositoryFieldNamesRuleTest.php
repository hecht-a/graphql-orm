<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan;

use GraphqlOrm\Metadata\Naming\DefaultNamingStrategy;
use GraphqlOrm\Metadata\Naming\NamingStrategy;
use GraphqlOrm\Metadata\Naming\SnakeCaseNamingStrategy;
use GraphqlOrm\PHPStan\FieldNameChecker;
use GraphqlOrm\PHPStan\RepositoryFieldNamesRule;
use GraphqlOrm\Tests\Fixtures\FakeEntity\FakeEntityNamedByStrategy;
use GraphqlOrm\Tests\Integration\Entity\Task;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<RepositoryFieldNamesRule>
 */
final class RepositoryFieldNamesRuleTest extends RuleTestCase
{
    private const UNMAPPED_TIP = 'A field of the API that the entity does not map can be allowed with @phpstan-ignore graphqlOrm.unmappedField, or every such field with the graphqlOrm.reportUnmappedFields parameter.';

    private bool $reportUnmappedFields = true;

    /** @var class-string<NamingStrategy> */
    private string $namingStrategy = DefaultNamingStrategy::class;

    protected function getRule(): Rule
    {
        return new RepositoryFieldNamesRule(new FieldNameChecker($this->reportUnmappedFields, $this->namingStrategy));
    }

    #[Test]
    public function itAcceptsPropertyNamesAndReportsUnmappedFields(): void
    {
        $this->analyse([__DIR__ . '/data/repository-field-names.php'], [
            [\sprintf('Field "titel" is not mapped on %s. Did you mean "title"?', Task::class), 19, self::UNMAPPED_TIP],
            [\sprintf('Field "user_id" is not mapped on %s. Did you mean "user"?', Task::class), 22, self::UNMAPPED_TIP],
            [\sprintf('Field "titel" is not mapped on %s. Did you mean "title"?', Task::class), 26, self::UNMAPPED_TIP],
            [\sprintf('Field "user_id" is not mapped on %s. Did you mean "user"?', Task::class), 26, self::UNMAPPED_TIP],
        ]);
    }

    #[Test]
    public function itReportsNothingWhenUnmappedFieldsAreAllowed(): void
    {
        $this->reportUnmappedFields = false;

        $this->analyse([__DIR__ . '/data/repository-field-names.php'], []);
    }

    #[Test]
    public function itNamesTheFieldsWithTheConfiguredStrategy(): void
    {
        $this->namingStrategy = SnakeCaseNamingStrategy::class;

        $this->analyse([__DIR__ . '/data/repository-field-names-snake-case.php'], [
            [\sprintf('Field "due_dat" is not mapped on %s. Did you mean "due_date"?', FakeEntityNamedByStrategy::class), 16, self::UNMAPPED_TIP],
        ]);
    }

    #[Test]
    public function itAcceptsTheFieldsOfAValueObjectOfPrefixedFields(): void
    {
        $this->analyse([__DIR__ . '/data/embedded-field-names.php'], []);
    }
}
