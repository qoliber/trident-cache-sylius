<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Qoliber\TridentSylius\Tags\SyliusEntityTagResolver;
use Qoliber\TridentSymfony\Tags\EntityTagResolver;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelPricing;
use Sylius\Component\Core\Model\Product;
use Sylius\Component\Core\Model\ProductTaxon;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Taxon;

final class EntityTagResolverTest extends TestCase
{
    private static function withId(object $o, int $id): object
    {
        (new \ReflectionProperty($o, 'id'))->setValue($o, $id);

        return $o;
    }

    private static function tags(object $entity, string $change = EntityTagResolver::UPDATE, array $changeSet = []): array
    {
        $t = iterator_to_array((new SyliusEntityTagResolver())->tagsFor($entity, $change, $changeSet), false);
        sort($t);

        return array_values(array_unique($t));
    }

    public function testAProductPurgesItsPageItsTaxonsAndHome(): void
    {
        $product = self::withId(new Product(), 1);
        $taxon = self::withId(new Taxon(), 2);
        $pt = new ProductTaxon();
        $pt->setProduct($product);
        $pt->setTaxon($taxon);
        $product->addProductTaxon($pt);
        $product->setMainTaxon(self::withId(new Taxon(), 4));

        self::assertSame(['sy_home', 'sy_product_1', 'sy_taxon_2', 'sy_taxon_4'], self::tags($product));

        $variant = new ProductVariant();
        $product->addVariant($variant);
        $price = new ChannelPricing();
        $price->setProductVariant($variant);
        self::assertSame(['sy_home', 'sy_product_1', 'sy_taxon_2', 'sy_taxon_4'], self::tags($price), 'a price change purges the product');
        self::assertSame(['sy_home', 'sy_product_1', 'sy_taxon_2', 'sy_taxon_4'], self::tags($variant), 'a stock change purges the product');
    }

    public function testATaxonPurgesItsListingAndTheMenu(): void
    {
        self::assertSame(['sy_menu', 'sy_taxon_7'], self::tags(self::withId(new Taxon(), 7)));
    }

    public function testAChannelChangePurgesTheShopButANewChannelDoesNot(): void
    {
        self::assertSame(['all'], self::tags(new Channel()));
        self::assertSame([], self::tags(new Channel(), EntityTagResolver::INSERT));
    }

    public function testOtherEntitiesPurgeNothing(): void
    {
        self::assertSame([], self::tags(new \stdClass()));
    }

    public function testAnOrderThatDoesNotFlipAvailabilityPurgesNothing(): void
    {
        $product = self::withId(new Product(), 1);
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $variant->setTracked(true);
        $variant->setOnHand(9);
        $variant->setOnHold(1);
        self::assertSame([], self::tags($variant, EntityTagResolver::UPDATE, ['onHold' => [0, 1]]), 'a hold that leaves stock: nothing');
        self::assertSame([], self::tags($variant, EntityTagResolver::UPDATE, ['onHand' => [10, 9], 'onHold' => [2, 1], 'updatedAt' => [null, null]]));
    }

    public function testAnAvailabilityFlipPurgesOnlyTheProduct(): void
    {
        $product = self::withId(new Product(), 1);
        $product->setMainTaxon(self::withId(new Taxon(), 4));
        $variant = new ProductVariant();
        $product->addVariant($variant);
        $variant->setTracked(true);
        $variant->setOnHand(1);
        $variant->setOnHold(1);
        self::assertSame(['sy_product_1'], self::tags($variant, EntityTagResolver::UPDATE, ['onHold' => [0, 1]]), 'the last one held: out of stock');
    }

    public function testAnUntrackedVariantsStockPurgesNothingButAPriceStillDoes(): void
    {
        $product = self::withId(new Product(), 1);
        $variant = new ProductVariant();
        $product->addVariant($variant);
        self::assertSame([], self::tags($variant, EntityTagResolver::UPDATE, ['onHand' => [1, 0]]));
        self::assertSame(['sy_home', 'sy_product_1'], self::tags($variant, EntityTagResolver::UPDATE, ['code' => ['a', 'b']]));
    }

    public function testNamesShownOnManyPagesRefreshTheShop(): void
    {
        self::assertSame(['all'], self::tags(new \Sylius\Component\Currency\Model\ExchangeRate()));
        self::assertSame(['all'], self::tags(new \Sylius\Component\Product\Model\ProductOptionValueTranslation()));
        self::assertSame(['all'], self::tags(new \Sylius\Component\Product\Model\ProductAttributeTranslation()));
        self::assertSame([], self::tags(new \Sylius\Component\Currency\Model\ExchangeRate(), EntityTagResolver::INSERT));
    }

    public function testAnAssociationPurgesItsOwner(): void
    {
        $product = self::withId(new Product(), 5);
        $a = new \Sylius\Component\Product\Model\ProductAssociation();
        $a->setOwner($product);
        self::assertSame(['sy_home', 'sy_product_5'], self::tags($a));
    }

    /**
     * The API's denormaliser flushes a translation that has no owner yet:
     * Sylius's getTranslatable() asserts, and the save returned 500.
     */
    public function testATranslationWithoutItsOwnerNeverFailsTheSave(): void
    {
        self::assertSame([], self::tags(new \Sylius\Component\Product\Model\ProductTranslation()));
        self::assertSame([], self::tags(new \Sylius\Component\Product\Model\ProductVariantTranslation()));
        self::assertSame(['sy_menu'], self::tags(new \Sylius\Component\Taxonomy\Model\TaxonTranslation()));
    }
}
