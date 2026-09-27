# qoliber/trident-cache-sylius

Trident HTTP cache for **Sylius 2.2** (Symfony 7.4, PHP 8.2+), on
`qoliber/trident-symfony`.

## Install

```bash
composer require qoliber/trident-cache-sylius
```

```php
// config/bundles.php (Flex adds these)
Qoliber\TridentSymfony\TridentSymfonyBundle::class => ['all' => true],
Qoliber\TridentSylius\TridentSyliusBundle::class => ['all' => true],
```

```yaml
# config/routes/trident.yaml
trident_sylius_admin:
    resource: '@TridentSyliusBundle/config/routes/admin.php'
    prefix: '/%sylius_admin.path_name%'
trident_sylius_shop:                          # /trident-sections(.js): the cart for shared pages
    resource: '@TridentSyliusBundle/config/routes/shop.php'

# config/packages/trident.yaml
trident_sylius:
    public_urls: ['https://shop.example.com']    # as visitors (and Trident) see the shop
    admin_view_role: ROLE_ADMINISTRATION_ACCESS   # default
    admin_operate_role: ROLE_TRIDENT_OPERATOR      # default; grant it to who may purge
```

Viewing the screens needs `admin_view_role`; every action (purge, ban, warm,
settings) needs `admin_operate_role`, which no Sylius admin has until you grant
it — deliberately a different role, so read-only admins stay read-only.

Then run `bin/console doctrine:migrations:migrate` and configure the instances
(`TRIDENT_INSTANCES`, see the Symfony bundle's README). Put Trident in front of
the shop with `integrations/ecommerce/sylius/trident.toml`.

## What changes in the shop

- **Product pages, taxon listings and the home page are shared.**
  - The add-to-cart form uses Symfony's **stateless CSRF** (Origin/Referer
    check), so a product page starts no session.
  - **The cart comes from the browser, not the page.** The header and
    off-canvas cart are rendered empty into the shared page and filled by
    `/trident-sections.js` from `localStorage`, keyed by the `trident_cart`
    cookie — a version (hash of the cart and the customer) the shop sets
    whenever either changes:
    - no cookie (no cart, not logged in): **no extra request at all**;
    - an unchanged cookie: the stored copy, no request;
    - a changed cookie: one request to `/trident-sections` (JSON, private).

    The script also watches the cookie (every second), so a cart changed in
    another tab shows up without a reload.
- **Tags:**
  - a product page carries its product and taxons;
  - a listing carries its taxon and the products shown;
  - the home page carries `sy_home`;
  - every page carries `sy_menu`.
- **Purges:**
  - saving a product (name, price, images, attributes, taxons, associations)
    purges its page, its taxons' listings and home;
  - a stock change purges only when it flips availability (in stock ↔ out of
    stock), and then only the product's tag — orders do not flush the
    catalogue;
  - exchange rates, product options and attributes purge the shop (`all`);
  - saving a taxon purges its listing and the menu on every page;
  - saving a channel purges the shop.
  - Purges are durable (outbox in the save's transaction), and each is
    delivered a **second time** `redeliver_after` seconds later (default 10):
    a page rendered from the old data while the save was still committing
    cannot outlive it.
  - Admin forms, the API Platform endpoints and Messenger handlers (catalog
    promotions) all go through Doctrine, so all of them purge.
- **Logged-in customers** get a `trident_auth` marker cookie; Trident bypasses the
  cache for them and for the shop firewall's remember-me cookie (its name is
  read from `security.yaml`; Sylius's default is `APP_SHOP_REMEMBER_ME`). A
  logged-in render is private at the origin too, even without the marker.
- **Visitors in another currency** get `trident_currency=<code>`; Trident keeps
  their pages apart and shares them among that currency's visitors.
- **Admin:** Configuration → **Trident Cache** has these screens:
  - dashboard, purge (URLs, tags, products/taxons by id, pattern, host, all),
    cached pages, entry detail, tags, coverage;
  - warmer (with "warmer queue shop": home, listings, products), launch,
    reflect, denoisers, bans, backends, discovery, live events;
  - settings.

  Viewing needs `admin_view_role`, every action `admin_operate_role`, a CSRF
  token, and — for what changes every visitor's pages — a confirmation.

## Known limits

- **A cart holder's own cold render isn't stored:** Sylius's layout reads the
  visitor's session (flash messages). They are served the pages anonymous
  visitors fill.
- **No ESI:** pages are shared whole; a taxon change refreshes every page. The
  shipped `trident.toml` keeps ESI off.
- **Keep `sylius_shop.locale_switcher: url`** (Sylius's default). With
  `storage` the locale lives in the session and the URL no longer names it, so
  a page cannot be shared per language.
- **Staleness is bounded, not zero:** a soft purge serves the old page once
  while the new one renders, and the cart in another tab catches up within a
  second.
- **Sylius has no sitemap** by default: warm from the admin (Warmer → warmer
  queue shop), or add a sitemap plugin and point Trident's warmer at it.
- **The WAF export on the Denoisers screen covers every host** of a shared
  Trident.

## Versioning

Versions follow Trident: this bundle 1.8.x works with Trident 1.8. MAJOR.MINOR moves
with the engine (every Trident X.Y.0 release is also a release of this package,
changed or not); the PATCH number is this package's own. The
admin screens warn when a connected Trident runs another release line.

## This repository is a mirror

`qoliber/trident-cache-sylius` is developed in the Trident repository together with the
shared library [`qoliber/trident-php`](https://github.com/qoliber/trident-php)
and the live end-to-end test stacks, and published to
[github.com/qoliber/trident-cache-sylius](https://github.com/qoliber/trident-cache-sylius) automatically:
every commit there is a "Sync from trident-cache@…" snapshot. **Please open
issues there**; pull requests against the mirror cannot be merged, because the
next sync would overwrite them. Releases are the tags of that repository.
