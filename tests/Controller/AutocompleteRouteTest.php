<?php

declare(strict_types=1);

namespace GraphqlOrm\Tests\Controller;

use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Form\GraphqlEntityType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The path of the search of the autocomplete fields is the controller's: the routes of the bundle are loaded without
 * symfony/form.
 */
final class AutocompleteRouteTest extends TestCase
{
    #[Test]
    public function itSignsTheUrlOfTheRouteOfTheController(): void
    {
        self::assertSame(EntityAutocompleteController::PATH, GraphqlEntityType::AUTOCOMPLETE_PATH);
    }
}
