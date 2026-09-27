<?php

declare(strict_types=1);

use Qoliber\TridentSylius\Controller\TridentAdminController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import from the application (config/routes/trident.php or YAML):
 *   trident_sylius_admin:
 *       resource: '@TridentSyliusBundle/config/routes/admin.php'
 *       prefix: '/%sylius_admin.path_name%'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('trident_sylius_admin_action', '/trident/action/{action}')
        ->controller([TridentAdminController::class, 'action'])
        ->methods(['POST'])
        ->requirements(['action' => '[a-z_]+']);
    $routes->add('trident_sylius_admin_settings', '/trident/settings')
        ->controller([TridentAdminController::class, 'settings'])
        ->methods(['POST']);
    $routes->add('trident_sylius_admin_screen', '/trident/{screen}')
        ->controller([TridentAdminController::class, 'screen'])
        ->methods(['GET'])
        ->defaults(['screen' => 'dashboard'])
        ->requirements(['screen' => '[a-z]+']);
};
