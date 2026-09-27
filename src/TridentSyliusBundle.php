<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius;

use Qoliber\TridentSylius\Admin\AdminMenuListener;
use Qoliber\TridentSylius\Admin\SyliusShopAdapter;
use Qoliber\TridentSylius\Controller\SectionsController;
use Qoliber\TridentSylius\Controller\TridentAdminController;
use Qoliber\TridentSylius\Personal\CartSections;
use Qoliber\TridentSylius\EventSubscriber\LoggedInVoter;
use Qoliber\TridentSylius\EventSubscriber\ShopTaggingSubscriber;
use Qoliber\TridentSylius\EventSubscriber\VisitorMarkerSubscriber;
use Qoliber\TridentSylius\Tags\SyliusEntityTagResolver;
use Qoliber\TridentSylius\Tags\SyliusTags;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\Trident\Admin\ShopAdapter;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Trident for Sylius 2: see README.md. Requires Qoliber\TridentSymfony\TridentSymfonyBundle.
 */
final class TridentSyliusBundle extends AbstractBundle
{
    protected string $extensionAlias = 'trident_sylius';

    /** Shop forms rendered on cacheable pages: validated statelessly (Origin/Referer), so the page needs no session. */
    public const STATELESS_CSRF = ['sylius_shop_add_to_cart'];

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('public_urls')->scalarPrototype()->end()->defaultValue([])
                    ->info('The shop\'s public base URLs as visitors (and Trident) see them, e.g. https://shop.example; default: https://<channel hostname>')->end()
                ->scalarNode('admin_view_role')->defaultValue('ROLE_ADMINISTRATION_ACCESS')->end()
                ->scalarNode('admin_operate_role')->defaultValue('ROLE_TRIDENT_OPERATOR')
                    ->info('Needed for every Trident action (purges, launch, reflect, bans, …). Not the view role by default: grant it to the admins who may operate Trident')->end()
            ->end();
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('trident', [
            'token_info' => 'qoliber/trident-cache-sylius:api-token:v1',
            'cacheable_routes' => ShopTaggingSubscriber::ROUTES,
            'bypass_cookies' => [VisitorMarkerSubscriber::AUTH, ...self::rememberMeCookies($builder)],
            'tags' => ['identity_prefixes' => SyliusTags::IDENTITY_PREFIXES, 'overflow_families' => SyliusTags::FAMILIES],
        ]);
        // Appended (loadFromExtension), not prepended: it must win over the
        // Sylius bundles' own prepended hook configuration.
        $builder->loadFromExtension('framework', ['csrf_protection' => ['stateless_token_ids' => self::STATELESS_CSRF]]);
        // The header and off-canvas carts become static placeholders filled by
        // trident-sections.js (version cookie + localStorage): an anonymous
        // visitor costs no request, a cart holder one when the cart changes.
        $builder->loadFromExtension('sylius_twig_hooks', ['hooks' => [
            'sylius_shop.base.header.content' => [
                'cart' => ['enabled' => false],
                'trident_cart' => ['template' => '@TridentSylius/shop/cart_button.html.twig', 'priority' => 100],
            ],
            'sylius_shop.base.offcanvas' => [
                'cart' => ['enabled' => false],
                'trident_cart' => ['template' => '@TridentSylius/shop/offcanvas_cart.html.twig', 'priority' => 0],
            ],
            'sylius_shop.base#javascripts' => ['trident_sections' => [
                'template' => '@TridentSylius/shop/sections_script.html.twig',
                'priority' => -100,
            ]],
        ]]);
    }

    /**
     * The shop firewalls' remember-me cookie names, from the security config
     * (Sylius's default: APP_SHOP_REMEMBER_ME).
     *
     * @return list<string>
     */
    private static function rememberMeCookies(ContainerBuilder $builder): array
    {
        $names = [];
        foreach ($builder->getExtensionConfig('security') as $config) {
            foreach ((array) ($config['firewalls'] ?? []) as $name => $firewall) {
                if (is_array($firewall) && isset($firewall['remember_me']) && is_array($firewall['remember_me']) && ($name === 'shop' || str_contains((string) ($firewall['context'] ?? ''), 'shop'))) {
                    $names[] = (string) ($firewall['remember_me']['name'] ?? 'REMEMBERME');
                }
            }
        }

        return array_values(array_unique($names !== [] ? $names : ['APP_SHOP_REMEMBER_ME']));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $s = $container->services()->defaults()->autowire(false)->autoconfigure(false);
        $s->set(SyliusEntityTagResolver::class)->tag('trident.entity_tag_resolver');
        $s->set(ShopTaggingSubscriber::class)->args([
            service(\Qoliber\TridentSymfony\Tags\ResponseTags::class), service('request_stack'), service('sylius.repository.taxon'),
        ])->tag('kernel.event_subscriber');
        $s->set(CartSections::class)->args([
            service('sylius_shop.storage.cart_session'), service('sylius.context.channel'), service('security.token_storage'), service('sylius.formatter.money'),
        ]);
        $s->set(SectionsController::class)->args([service(CartSections::class)])->tag('controller.service_arguments')->public();
        $s->set(VisitorMarkerSubscriber::class)->args([service('security.token_storage'), service('sylius.context.channel'), service('sylius.storage.cookie')->nullOnInvalid(), service(CartSections::class)])->tag('kernel.event_subscriber');
        $s->set(LoggedInVoter::class)->args([service('security.token_storage')])->tag('trident.cacheability_voter');
        $s->set(SyliusShopAdapter::class)->args([
            $config['public_urls'], service('sylius.repository.channel'), service('sylius.repository.product'), service('doctrine.dbal.default_connection'),
        ]);
        $s->alias(ShopAdapter::class, SyliusShopAdapter::class);
        $s->set(TridentAdminController::class)
            ->args([service(AdminService::class), service(SettingsProvider::class), $config['admin_view_role'], $config['admin_operate_role']])
            ->autowire(true)
            ->autoconfigure(true)
            ->tag('controller.service_arguments')
            ->public();
        $s->set(AdminMenuListener::class)->tag('kernel.event_listener', ['event' => 'sylius.menu.admin.main']);
    }
}
