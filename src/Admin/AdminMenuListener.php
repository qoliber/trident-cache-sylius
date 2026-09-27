<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Admin;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

/**
 * "Trident Cache" under Configuration in the Sylius admin menu.
 */
final class AdminMenuListener
{
    public function __invoke(MenuBuilderEvent $event): void
    {
        $parent = $event->getMenu()->getChild('configuration') ?? $event->getMenu();
        $parent->addChild('trident_cache', ['route' => 'trident_sylius_admin_screen', 'routeParameters' => ['screen' => 'dashboard']])
            ->setLabel('Trident Cache')
            ->setLabelAttribute('icon', 'tabler:bolt');
    }
}
