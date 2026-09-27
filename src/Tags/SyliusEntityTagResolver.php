<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Tags;

use Qoliber\TridentSymfony\Tags\EntityTagResolver;
use Qoliber\Trident\Tags\TagPolicy;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\ProductImageInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTaxonInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Product\Model\ProductAttributeValueInterface;
use Sylius\Component\Product\Model\ProductTranslationInterface;
use Sylius\Component\Product\Model\ProductVariantTranslationInterface;
use Sylius\Component\Taxonomy\Model\TaxonTranslationInterface;
use Sylius\Component\Currency\Model\ExchangeRateInterface;
use Sylius\Component\Product\Model\ProductAssociationInterface;
use Sylius\Component\Product\Model\ProductAttributeInterface;
use Sylius\Component\Product\Model\ProductAttributeTranslationInterface;
use Sylius\Component\Product\Model\ProductOptionInterface;
use Sylius\Component\Product\Model\ProductOptionTranslationInterface;
use Sylius\Component\Product\Model\ProductOptionValueInterface;
use Sylius\Component\Product\Model\ProductOptionValueTranslationInterface;

/**
 * What a Sylius catalogue change purges:
 * - a product (or its translation, variant, price, stock, image, attribute or
 *   taxon assignment): its page, the listings of its taxons and the home page;
 * - a taxon (or its translation): its listing and the menu on every page;
 * - a channel, an exchange rate, a product option/option value/attribute
 *   (names shown on many pages): the whole shop;
 * - an association: its owner product;
 * - stock only (onHand/onHold, e.g. an order): nothing, unless the variant's
 *   availability flips — then the product's tag (its page and every listing
 *   showing it).
 */
final class SyliusEntityTagResolver implements EntityTagResolver
{
    /** Fields that only move stock: an order changes them on every checkout. */
    private const STOCK_FIELDS = ['onHand', 'onHold', 'updatedAt', 'version'];

    public function tagsFor(object $entity, string $change, array $changeSet = []): iterable
    {
        // Stock-only changes (every order holds and then takes stock) purge
        // only when the variant's availability flips, and then only the
        // product's own tag — the listings that show it carry that tag too.
        if ($entity instanceof ProductVariantInterface && $change === self::UPDATE && $changeSet !== []
            && array_diff(array_keys($changeSet), self::STOCK_FIELDS) === []) {
            if (self::availabilityFlipped($entity, $changeSet) && $entity->getProduct()?->getId() !== null) {
                yield SyliusTags::product($entity->getProduct()->getId());
            }

            return;
        }

        $product = match (true) {
            $entity instanceof ProductInterface => $entity,
            $entity instanceof ProductTranslationInterface => self::translatable($entity),
            $entity instanceof ProductVariantInterface => $entity->getProduct(),
            $entity instanceof ProductVariantTranslationInterface => self::translatable($entity)?->getProduct(),
            $entity instanceof ChannelPricingInterface => $entity->getProductVariant()?->getProduct(),
            $entity instanceof ProductImageInterface => $entity->getOwner(),
            $entity instanceof ProductTaxonInterface => $entity->getProduct(),
            $entity instanceof ProductAttributeValueInterface => $entity->getProduct(),
            $entity instanceof ProductAssociationInterface => $entity->getOwner(),
            default => null,
        };
        if ($product instanceof ProductInterface) {
            yield from $this->productTags($product);
            if ($entity instanceof ProductTaxonInterface && $entity->getTaxon() !== null && $entity->getTaxon()->getId() !== null) {
                yield SyliusTags::taxon($entity->getTaxon()->getId());
            }

            return;
        }
        $taxon = match (true) {
            $entity instanceof TaxonInterface => $entity,
            $entity instanceof TaxonTranslationInterface => self::translatable($entity),
            default => null,
        };
        if ($taxon === null && $entity instanceof TaxonTranslationInterface) {
            // Its owner is not known yet, but a taxon's name is in the menu.
            yield SyliusTags::MENU;

            return;
        }
        if ($taxon instanceof TaxonInterface) {
            if ($taxon->getId() !== null) {
                yield SyliusTags::taxon($taxon->getId());
            }
            yield SyliusTags::MENU;

            return;
        }
        if ($entity instanceof ChannelInterface && $change !== self::INSERT) {
            yield TagPolicy::ALL;
        }
        // Shown on many pages and not tagged per page: an exchange rate (every
        // converted price), an option or attribute's name or values. Rare
        // admin changes — the whole shop is refreshed.
        if ($entity instanceof ExchangeRateInterface
            || $entity instanceof ProductOptionInterface || $entity instanceof ProductOptionTranslationInterface
            || $entity instanceof ProductOptionValueInterface || $entity instanceof ProductOptionValueTranslationInterface
            || $entity instanceof ProductAttributeInterface || $entity instanceof ProductAttributeTranslationInterface) {
            if ($change !== self::INSERT) {
                yield TagPolicy::ALL;
            }
        }
    }

    /**
     * A translation's owner — or null: Sylius's getTranslatable() asserts it is
     * set, and a translation being built or replaced (the API's denormaliser
     * does that inside the flush) has none yet. A save must never fail on it.
     */
    private static function translatable(object $translation): ?object
    {
        try {
            return $translation->getTranslatable();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private static function availabilityFlipped(ProductVariantInterface $variant, array $changeSet): bool
    {
        if (!$variant->isTracked()) {
            return false;
        }
        $onHand = (int) $variant->getOnHand();
        $onHold = (int) $variant->getOnHold();
        $oldHand = array_key_exists('onHand', $changeSet) ? (int) $changeSet['onHand'][0] : $onHand;
        $oldHold = array_key_exists('onHold', $changeSet) ? (int) $changeSet['onHold'][0] : $onHold;

        return ($oldHand - $oldHold > 0) !== ($onHand - $onHold > 0);
    }

    /**
     * @return iterable<string>
     */
    private function productTags(ProductInterface $product): iterable
    {
        if ($product->getId() !== null) {
            yield SyliusTags::product($product->getId());
        }
        foreach ($product->getTaxons() as $taxon) {
            if ($taxon->getId() !== null) {
                yield SyliusTags::taxon($taxon->getId());
            }
        }
        if ($product->getMainTaxon()?->getId() !== null) {
            yield SyliusTags::taxon($product->getMainTaxon()->getId());
        }
        yield SyliusTags::HOME;
    }
}
