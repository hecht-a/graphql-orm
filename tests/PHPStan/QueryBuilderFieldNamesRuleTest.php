<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan;

use GraphqlOrm\PHPStan\FieldNameChecker;
use GraphqlOrm\PHPStan\QueryBuilderFieldNamesRule;
use GraphqlOrm\Tests\Fixtures\Embedded\Money;
use GraphqlOrm\Tests\Fixtures\Embedded\Order;
use GraphqlOrm\Tests\Integration\Entity\Task;
use GraphqlOrm\Tests\Integration\Entity\User;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<QueryBuilderFieldNamesRule>
 */
final class QueryBuilderFieldNamesRuleTest extends RuleTestCase
{
    private const UNMAPPED_TIP = 'A field of the API that the entity does not map can be allowed with @phpstan-ignore graphqlOrm.unmappedField, or every such field with the graphqlOrm.reportUnmappedFields parameter.';

    private bool $reportUnmappedFields = true;

    protected function getRule(): Rule
    {
        return new QueryBuilderFieldNamesRule(new FieldNameChecker($this->reportUnmappedFields));
    }

    #[Test]
    public function itChecksSelectedPathsAndOrdering(): void
    {
        $this->analyse([__DIR__ . '/data/query-builder-field-names.php'], [
            [\sprintf('Field "nmae" is not mapped on %s. Did you mean "name"?', User::class), 17, self::UNMAPPED_TIP],
            [\sprintf('Field "title" of %s is not a relation: "title.length" cannot be selected.', Task::class), 18],
        ]);
    }

    #[Test]
    public function itStillReportsWhatIsCertainlyWrongWhenUnmappedFieldsAreAllowed(): void
    {
        $this->reportUnmappedFields = false;

        $this->analyse([__DIR__ . '/data/query-builder-field-names.php'], [
            [\sprintf('Field "title" of %s is not a relation: "title.length" cannot be selected.', Task::class), 18],
        ]);
    }

    #[Test]
    public function itAcceptsTheFieldsOfTheEmbeddedValueObjects(): void
    {
        $this->analyse([__DIR__ . '/data/embedded-field-names.php'], [
            [\sprintf('Field "amont" is not mapped on %s, embedded in %s::$total. Did you mean "amount"?', Money::class, Order::class), 18, self::UNMAPPED_TIP],
        ]);
    }
}
