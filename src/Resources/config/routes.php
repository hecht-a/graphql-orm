<?php

declare(strict_types=1);

use GraphqlOrm\Controller\EntityAutocompleteController;
use GraphqlOrm\Controller\ProfilerReplayController;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// Imported by the application for the autocomplete GraphqlEntityType fields, see docs/forms.md, and the Replay button
// of the profiler, see docs/profiler.md. Without symfony/form, there are neither such fields nor their controller:
// nothing to route, and nothing of symfony/form to load.
return static function (RoutingConfigurator $routes): void {
    // Answers while developing only, see ProfilerReplayController.
    $routes->add('graphql_orm_profiler_replay', ProfilerReplayController::PATH . '/{token}/{number}')
        ->controller(ProfilerReplayController::class)
        ->requirements(['token' => '[0-9a-zA-Z]+', 'number' => '\d+'])
        ->methods(['POST']);

    if (!interface_exists(FormTypeInterface::class)) {
        return;
    }

    $routes->add('graphql_orm_autocomplete', EntityAutocompleteController::PATH)
        ->controller(EntityAutocompleteController::class)
        ->methods(['GET']);
};
