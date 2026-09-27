<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Controller;

use Composer\InstalledVersions;
use Qoliber\TridentSylius\Personal\CartSections;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * The personal sections endpoint (never cached) and the shared script,
 * served from qoliber/trident-php (one copy, the library's).
 */
final class SectionsController
{
    public function __construct(private readonly CartSections $sections)
    {
    }

    public function data(Request $request): JsonResponse
    {
        $response = new JsonResponse(['version' => implode('|', [$this->sections->version($request) ?? '']), 'sections' => $this->sections->sections($request)]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    public function script(): Response
    {
        $path = (InstalledVersions::getInstallPath('qoliber/trident-php') ?? '') . '/assets/js/trident-sections.js';
        if (!is_file($path)) {
            return new Response('/* trident-sections.js not found */', 404, ['Content-Type' => 'application/javascript']);
        }
        $response = new BinaryFileResponse($path, 200, ['Content-Type' => 'application/javascript; charset=utf-8']);
        $response->setPublic();
        $response->setMaxAge(86400);
        $response->headers->addCacheControlDirective('s-maxage', '86400');
        // The same file for everyone: Symfony's session listener must not turn
        // it private because the shop firewall opened a session.
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }
}
