<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Personal;

use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Storage\CartStorageInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The visitor's personal bits for the shared pages — the cart summary in the
 * header and the off-canvas cart, the customer's name — and their VERSION.
 *
 * The version is what the `trident_cart` cookie carries: a hash of the cart's
 * state and the logged-in customer. `trident-sections.js` (qoliber/trident-php)
 * keeps the sections in localStorage under that version, so:
 * - no cookie (no cart, not logged in): no request at all;
 * - an unchanged cookie: the stored copy, no request;
 * - a changed cookie (the cart changed, a login): one request to
 *   {@see \Qoliber\TridentSylius\Controller\SectionsController}.
 *
 * The cart is read only when the request carries the visitor's session cookie:
 * a first-time visitor's session is never started for it.
 */
class CartSections
{
    public const COOKIE = 'trident_cart';

    public function __construct(
        private readonly CartStorageInterface $carts,
        private readonly ChannelContextInterface $channels,
        private readonly TokenStorageInterface $tokens,
        private readonly MoneyFormatterInterface $money,
    ) {
    }

    public function version(Request $request): ?string
    {
        $cart = $this->cart($request);
        $user = $this->user();
        if ($cart === null && $user === null) {
            return null;
        }

        return substr(hash('sha256', implode('|', [
            $cart?->getId(), $cart?->getTotalQuantity(), $cart?->getItemsTotal(), $cart?->getCurrencyCode(), $user?->getId(),
        ])), 0, 16);
    }

    /**
     * @return array<string, scalar>
     */
    public function sections(Request $request): array
    {
        $cart = $this->cart($request);
        $user = $this->user();
        $count = (int) ($cart?->getTotalQuantity() ?? 0);
        $sections = [
            'logged_in' => $user !== null,
            'customer_name' => (string) ($user?->getCustomer()?->getFirstName() ?? ''),
            'has_cart' => $count > 0,
            'cart_count' => $count,
            'cart_total' => '',
            'cart_summary' => '',
        ];
        if ($cart !== null && $count > 0) {
            $total = $this->money->format($cart->getItemsTotal(), (string) $cart->getCurrencyCode(), $request->getLocale());
            $sections['cart_total'] = $total;
            $sections['cart_summary'] = sprintf('%s, %d %s', $total, $count, $count === 1 ? 'item' : 'items');
        }

        return $sections;
    }

    private function cart(Request $request): ?OrderInterface
    {
        if (!$request->hasSession(true) || !$request->cookies->has($request->getSession()->getName())) {
            return null;
        }
        try {
            $channel = $this->channels->getChannel();
        } catch (\Throwable) {
            return null;
        }
        if (!$channel instanceof ChannelInterface || !$this->carts->hasForChannel($channel)) {
            return null;
        }
        $cart = $this->carts->getForChannel($channel);

        return $cart instanceof OrderInterface && !$cart->isEmpty() ? $cart : null;
    }

    private function user(): ?ShopUserInterface
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof ShopUserInterface ? $user : null;
    }
}
