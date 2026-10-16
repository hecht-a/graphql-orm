<?php

declare(strict_types=1);

use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Form\GraphqlEntityType;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by the application for the autocomplete GraphqlEntityType fields, see docs/forms.md.
return static function (RoutingConfigurator $routes): void {
    $routes->add('graphql_orm_autocomplete', GraphqlEntityType::AUTOCOMPLETE_PATH)
        ->controller(EntityAutocompleteController::class)
        ->methods(['GET']);
};
