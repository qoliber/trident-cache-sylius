<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Admin;

use Doctrine\DBAL\Connection;
use Qoliber\TridentSylius\Tags\SyliusTags;
use Qoliber\Trident\Admin\ShopAdapter;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;

/**
 * The shop as the admin screens see it.
 *
 * Hosts: the public base URLs from `trident.shop.hosts` when set (Trident keys
 * pages by the Host the visitor sent, with a port when not 80/443), otherwise
 * `https://<channel hostname>` of every enabled channel.
 */
final class SyliusShopAdapter implements ShopAdapter
{
    /**
     * @param list<string>                                   $publicUrls
     * @param ChannelRepositoryInterface<ChannelInterface>   $channels
     * @param ProductRepositoryInterface<ProductInterface>   $products
     */
    public function __construct(
        private readonly array $publicUrls,
        private readonly ChannelRepositoryInterface $channels,
        private readonly ProductRepositoryInterface $products,
        private readonly Connection $connection,
    ) {
    }

    public function hosts(): array
    {
        $out = [];
        foreach ($this->baseUrls() as $url) {
            $p = parse_url($url);
            if (isset($p['host'])) {
                $out[] = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
            }
        }

        return array_values(array_unique($out));
    }

    public function scheme(string $host): string
    {
        foreach ($this->baseUrls() as $url) {
            $p = parse_url($url);
            if (isset($p['host']) && strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') === $host) {
                return strtolower($p['scheme'] ?? 'https');
            }
        }

        return 'https';
    }

    public function catalogUrls(int $limit): array
    {
        $base = $this->baseUrls()[0] ?? null;
        if ($base === null) {
            return [];
        }
        $locale = (string) ($this->connection->fetchOne(
            'SELECT l.code FROM sylius_channel c JOIN sylius_locale l ON l.id = c.default_locale_id WHERE c.enabled = 1 ORDER BY c.id LIMIT 1'
        ) ?: 'en_US');
        $urls = [rtrim($base, '/') . '/' . $locale . '/'];
        $n = max(1, $limit);
        foreach ($this->connection->fetchFirstColumn(
            'SELECT t.slug FROM sylius_taxon_translation t WHERE t.locale = ? AND t.slug IS NOT NULL ORDER BY t.id LIMIT ' . $n,
            [$locale]
        ) as $slug) {
            $urls[] = rtrim($base, '/') . '/' . $locale . '/taxons/' . $slug;
        }
        foreach ($this->connection->fetchFirstColumn(
            'SELECT t.slug FROM sylius_product p JOIN sylius_product_translation t ON t.translatable_id = p.id AND t.locale = ? WHERE p.enabled = 1 ORDER BY p.id LIMIT ' . $n,
            [$locale]
        ) as $slug) {
            $urls[] = rtrim($base, '/') . '/' . $locale . '/products/' . $slug;
        }

        return \array_slice($urls, 0, $n);
    }

    public function entityKinds(): array
    {
        return ['product' => 'Products (by id)', 'taxon' => 'Taxons (by id)'];
    }

    public function entityTags(string $kind, array $ids): array
    {
        $tags = [];
        foreach ($ids as $id) {
            if (preg_match('/^[1-9][0-9]{0,18}$/', $id) !== 1) {
                throw new \InvalidArgumentException(sprintf('"%s" is not an id', $id));
            }
            if ($kind === 'taxon') {
                $tags[] = SyliusTags::taxon($id);
                continue;
            }
            if ($kind !== 'product') {
                throw new \InvalidArgumentException(sprintf('Unknown kind "%s"', $kind));
            }
            $tags[] = SyliusTags::product($id);
            $product = $this->products->find((int) $id);
            if ($product instanceof ProductInterface) {
                foreach ($product->getTaxons() as $taxon) {
                    $tags[] = SyliusTags::taxon((string) $taxon->getId());
                }
            }
            $tags[] = SyliusTags::HOME;
        }

        return array_values(array_unique($tags));
    }

    /**
     * @return list<string>
     */
    private function baseUrls(): array
    {
        if ($this->publicUrls !== []) {
            return $this->publicUrls;
        }
        $urls = [];
        foreach ($this->channels->findBy(['enabled' => true]) as $channel) {
            if ($channel instanceof ChannelInterface && $channel->getHostname() !== null && $channel->getHostname() !== '') {
                $urls[] = 'https://' . $channel->getHostname();
            }
        }

        return $urls;
    }
}
