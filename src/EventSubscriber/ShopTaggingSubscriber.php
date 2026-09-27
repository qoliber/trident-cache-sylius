<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\EventSubscriber;

use Qoliber\TridentSylius\Tags\SyliusTags;
use Qoliber\TridentSymfony\Tags\ResponseTags;
use Sylius\Bundle\ResourceBundle\Event\ResourceControllerEvent;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Taxonomy\Repository\TaxonRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tags the shop's cacheable pages while they render:
 * - a product page: its product and its taxons;
 * - a taxon listing: the taxon and every product on the page (bounded —
 *   beyond the header budget the page carries `sy_overflow_product`);
 * - the home page: `sy_home`;
 * - every one of them: `sy_menu` (the taxon menu in the header).
 */
final class ShopTaggingSubscriber implements EventSubscriberInterface
{
    public const ROUTES = ['sylius_shop_homepage', 'sylius_shop_product_show', 'sylius_shop_product_index'];

    /**
     * @param TaxonRepositoryInterface<\Sylius\Component\Taxonomy\Model\TaxonInterface> $taxons
     */
    public function __construct(
        private readonly ResponseTags $tags,
        private readonly RequestStack $requests,
        private readonly TaxonRepositoryInterface $taxons,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.product.show' => 'onProductShow',
            'sylius.product.index' => 'onProductIndex',
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onProductShow(ResourceControllerEvent $event): void
    {
        $product = $event->getSubject();
        if ($product instanceof ProductInterface && $this->isShop()) {
            $this->tagProduct($product, true);
        }
    }

    public function onProductIndex(ResourceControllerEvent $event): void
    {
        if (!$this->isShop()) {
            return;
        }
        $request = $this->requests->getMainRequest();
        $slug = $request?->attributes->get('slug');
        if (is_string($slug) && $slug !== '') {
            $taxon = $this->taxons->findOneBySlug($slug, (string) $request->getLocale());
            if ($taxon?->getId() !== null) {
                $this->tags->add(SyliusTags::taxon($taxon->getId()));
            }
        }
        $subject = $event->getSubject();
        $items = is_object($subject) && method_exists($subject, 'getData') ? $subject->getData() : $subject;
        if (is_iterable($items)) {
            foreach ($items as $item) {
                if ($item instanceof ProductInterface) {
                    $this->tagProduct($item, false);
                }
            }
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = (string) $event->getRequest()->attributes->get('_route');
        if (!\in_array($route, self::ROUTES, true)) {
            return;
        }
        $this->tags->add(SyliusTags::MENU);
        if ($route === 'sylius_shop_homepage') {
            $this->tags->add(SyliusTags::HOME);
        }
    }

    private function tagProduct(ProductInterface $product, bool $withTaxons): void
    {
        if ($product->getId() !== null) {
            $this->tags->add(SyliusTags::product($product->getId()));
        }
        if (!$withTaxons) {
            return;
        }
        foreach ($product->getTaxons() as $taxon) {
            if ($taxon->getId() !== null) {
                $this->tags->add(SyliusTags::taxon($taxon->getId()));
            }
        }
    }

    private function isShop(): bool
    {
        return \in_array((string) $this->requests->getMainRequest()?->attributes->get('_route'), self::ROUTES, true);
    }
}
