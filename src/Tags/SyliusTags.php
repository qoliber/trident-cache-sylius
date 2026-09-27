<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Tags;

/**
 * The tag names, in one place for both sides (the page header and the purge).
 */
final class SyliusTags
{
    public const HOME = 'sy_home';
    public const MENU = 'sy_menu';
    public const OVERFLOW_PRODUCT = 'sy_overflow_product';
    public const IDENTITY_PREFIXES = ['sy_product_', 'sy_taxon_', 'sy_home'];
    public const FAMILIES = ['/^sy_product_\d+$/' => self::OVERFLOW_PRODUCT];

    public static function product(int|string $id): string
    {
        return 'sy_product_' . $id;
    }

    public static function taxon(int|string $id): string
    {
        return 'sy_taxon_' . $id;
    }
}
