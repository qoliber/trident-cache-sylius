<?php

declare(strict_types=1);

use Qoliber\TridentSylius\Controller\SectionsController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/*
 * Import from the application (no prefix):
 *   trident_sylius_shop:
 *       resource: '@TridentSyliusBundle/config/routes/shop.php'
 */
return static function (RoutingConfigurator $routes): void {
    $routes->add('trident_sylius_sections', '/trident-sections')
        ->controller([SectionsController::class, 'data'])
        ->methods(['GET']);
    $routes->add('trident_sylius_sections_script', '/trident-sections.js')
        ->controller([SectionsController::class, 'script'])
        ->methods(['GET']);
};
