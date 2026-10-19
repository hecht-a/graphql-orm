<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\PHPStan;

use PHPStan\Rules\Properties\MissingReadOnlyPropertyAssignRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @extends RuleTestCase<MissingReadOnlyPropertyAssignRule>
 */
final class MappedPropertiesExtensionTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        // The rule the extension acts upon, which PHPStan does not cover by its compatibility promise.
        return self::getContainer()->getByType(MissingReadOnlyPropertyAssignRule::class); // @phpstan-ignore phpstanApi.classConstant
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [\dirname(__DIR__, 2) . '/extension.neon'];
    }

    #[Test]
    public function itTellsTheMappedPropertiesAreInitializedByTheBundle(): void
    {
        $this->analyse([__DIR__ . '/data/mapped-properties.php'], [
            ['Class GraphqlOrm\Tests\PHPStan\Data\MappedNote has an uninitialized readonly property $notMapped. Assign it in the constructor.', 19],
        ]);
    }
}
