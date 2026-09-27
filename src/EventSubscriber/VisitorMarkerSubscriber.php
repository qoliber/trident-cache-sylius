<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\EventSubscriber;

use Qoliber\TridentSylius\Personal\CartSections;
use Sylius\Component\Channel\Context\ChannelContextInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Two cookies that tell Trident, BEFORE it looks up a page, what the page must
 * not be shared with:
 * - `trident_auth=1` while a customer is logged in (Trident bypasses the
 *   cache for it: the header shows their account);
 * - `trident_currency=<code>` while the visitor chose a currency other than
 *   the channel's base one (Trident varies on it: prices differ; the EUR page
 *   is then shared by every EUR visitor).
 * - `trident_cart=<version>` (readable by the page's script) while the visitor
 *   has a cart or is logged in: `trident-sections.js` keeps the header cart in
 *   localStorage under it and asks the server only when it changes.
 * Anonymous visitors in the base currency carry none and share one page.
 */
final class VisitorMarkerSubscriber implements EventSubscriberInterface
{
    public const AUTH = 'trident_auth';
    public const CURRENCY = 'trident_currency';

    /**
     * @param object|null $cookies Sylius's cookie storage (`sylius.storage.cookie`):
     *                             `get()` returns the value this response is about
     *                             to set, or the request's.
     */
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly ChannelContextInterface $channels,
        private readonly ?object $cookies = null,
        private readonly ?CartSections $sections = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the response policy (-900): a Set-Cookie here keeps THIS render
        // from being stored (and the policy's final check at -4096 catches any
        // cookie added later, e.g. by Sylius's cookie storage at -1024).
        return [KernelEvents::RESPONSE => ['onResponse', -512]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || self::notShop($request)) {
            return;
        }
        $response = $event->getResponse();
        $this->mark($request, $response, self::AUTH, $this->tokens->getToken()?->getUser() instanceof ShopUserInterface ? '1' : null);
        $this->mark($request, $response, self::CURRENCY, $this->chosenCurrency($request, $response));
        if ($this->sections !== null) {
            $this->mark($request, $response, CartSections::COOKIE, $this->sections->version($request), false);
        }
    }

    /**
     * The currency this visitor chose, when it is not the channel's base one.
     * Sylius 2 keeps the choice in a cookie `_currency_<channel code>` (on the
     * switch-currency response it is still pending in Sylius's cookie storage); an older
     * setup may keep it in the session — read only if already started.
     */
    private function chosenCurrency(Request $request, Response $response): ?string
    {
        try {
            $channel = $this->channels->getChannel();
        } catch (\Throwable) {
            return null;
        }
        if (!$channel instanceof ChannelInterface) {
            return null;
        }
        $key = '_currency_' . $channel->getCode();
        // Sylius writes its cookies late (kernel.response -1024): ask its
        // storage, which knows the value this response is about to set.
        $chosen = $this->cookies !== null && method_exists($this->cookies, 'get') ? $this->cookies->get($key) : null;
        $chosen ??= $request->cookies->get($key);
        if ($chosen === null && $request->hasSession(true) && $request->getSession()->isStarted()) {
            $chosen = $request->getSession()->get($key);
        }
        $base = $channel->getBaseCurrency()?->getCode();

        return is_string($chosen) && $chosen !== '' && $chosen !== $base ? $chosen : null;
    }

    private function mark(Request $request, Response $response, string $name, ?string $value, bool $httpOnly = true): void
    {
        $current = $request->cookies->get($name);
        if ($value === null && $current !== null) {
            $response->headers->clearCookie($name, '/', null, false, $httpOnly, 'lax');
        } elseif ($value !== null && $current !== $value) {
            $response->headers->setCookie(Cookie::create($name, $value, 0, '/', null, null, $httpOnly, false, Cookie::SAMESITE_LAX));
        }
    }

    private static function notShop(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/admin') || str_starts_with($path, '/api') || str_starts_with($path, '/_');
    }
}
