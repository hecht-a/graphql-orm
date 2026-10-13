<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan;

use GraphqlOrm\PHPStan\FieldNameChecker;
use GraphqlOrm\PHPStan\RepositoryFieldNamesRule;
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

    protected function getRule(): Rule
    {
        return new RepositoryFieldNamesRule(new FieldNameChecker($this->reportUnmappedFields));
    }

    #[Test]
    public function itAcceptsPropertyNamesAndReportsUnmappedFields(): void
    {
        $this->analyse([__DIR__ . '/data/repository-field-names.php'], [
            [\sprintf('Field "titel" is not mapped on %s. Did you mean "title"?', Task::class), 19, self::UNMAPPED_TIP],
            [\sprintf('Field "user_id" is not mapped on %s. Did you mean "user"?', Task::class), 22, self::UNMAPPED_TIP],
        ]);
    }

    #[Test]
    public function itReportsNothingWhenUnmappedFieldsAreAllowed(): void
    {
        $this->reportUnmappedFields = false;

        $this->analyse([__DIR__ . '/data/repository-field-names.php'], []);
    }
}
