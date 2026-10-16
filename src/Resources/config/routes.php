<?php

declare(strict_types=1);

use GraphqlOrm\Controller\EntityAutocompleteController;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by the application for the autocomplete GraphqlEntityType fields, see docs/forms.md. Without symfony/form,
// there are neither such fields nor their controller: nothing to route, and nothing of symfony/form to load.
return static function (RoutingConfigurator $routes): void {
    if (!interface_exists(FormTypeInterface::class)) {
        return;
    }

    $routes->add('graphql_orm_autocomplete', EntityAutocompleteController::PATH)
        ->controller(EntityAutocompleteController::class)
        ->methods(['GET']);
};
