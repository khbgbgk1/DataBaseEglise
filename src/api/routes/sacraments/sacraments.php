<?php

use Slim\Routing\RouteCollectorProxy;

use EcclesiaCRM\APIControllers\SacramentController;

$app->group('/sacraments', function (RouteCollectorProxy $group) {
    $group->get('/person/{personId:[0-9]+}', SacramentController::class . ':getPersonSacraments' );
    $group->post('/person/{personId:[0-9]+}', SacramentController::class . ':savePersonSacrament' );
    $group->delete('/{sacramentId:[0-9]+}', SacramentController::class . ':deletePersonSacrament' );
});
