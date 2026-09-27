<?php

declare(strict_types=1);

namespace Qoliber\TridentSylius\Controller;

use Qoliber\Trident\Admin\AdminException;
use Qoliber\Trident\Admin\AdminService;
use Qoliber\TridentSymfony\Config\SettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Trident screens in the Sylius admin, over the shared admin logic
 * ({@see AdminService}): every screen shows each instance separately.
 *
 * Viewing needs the view role, every action the operate role (both
 * configurable; see `trident_sylius`). Every action is a POST with a CSRF
 * token; the actions that change what every visitor is served also need an
 * explicit confirmation, checked on the server. `Accept: application/json`
 * gets the result as JSON (tests, scripts).
 */
final class TridentAdminController extends AbstractController
{
    public const CSRF = 'trident_admin';
    public const SCREENS = ['dashboard', 'purge', 'entries', 'entry', 'tags', 'coverage', 'warmer', 'launch', 'reflect', 'denoisers', 'bans', 'backends', 'discovery', 'events', 'settings'];

    public function __construct(
        private readonly AdminService $admin,
        private readonly SettingsProvider $settings,
        private readonly string $viewRole,
        private readonly string $operateRole,
    ) {
    }

    public function screen(Request $request, string $screen): Response
    {
        $this->denyAccessUnlessGranted($this->viewRole);
        if (!\in_array($screen, self::SCREENS, true)) {
            throw $this->createNotFoundException();
        }
        $data = null;
        $error = null;
        try {
            if ($screen === 'settings') {
                $data = ['stored' => $this->settings->adminView()];
            } elseif ($screen !== 'entry' || $request->query->has('url')) {
                $data = $this->admin->screen($screen, $request->query->all());
            }
        } catch (AdminException $e) {
            $error = $e->getMessage();
        }
        $overview = $this->admin->overview();
        if ($this->wantsJson($request)) {
            return new JsonResponse(['screen' => $screen, 'overview' => $overview, 'data' => $data, 'error' => $error], $error === null ? 200 : 400);
        }

        return $this->render('@TridentSylius/admin/screen.html.twig', [
            'screen' => $screen,
            'screens' => self::SCREENS,
            'overview' => $overview,
            'data' => $data,
            'error' => $error,
            'forms' => AdminForms::forScreen($screen),
            'confirm' => AdminService::CONFIRM,
            'can_operate' => $this->isGranted($this->operateRole),
            'query' => $request->query->all(),
        ]);
    }

    public function action(Request $request, string $action): Response
    {
        $this->denyAccessUnlessGranted($this->operateRole);
        $screen = (string) $request->request->get('screen', 'dashboard');
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->fail($request, 'Invalid CSRF token: reload the page', $screen, 403);
        }
        $body = self::body($request);
        try {
            $result = $this->admin->action($action, $body);
        } catch (AdminException $e) {
            return $this->fail($request, $e->getMessage(), $screen, 400);
        }
        if ($this->wantsJson($request)) {
            return new JsonResponse(['action' => $action, 'result' => $result]);
        }
        $this->addFlash('success', sprintf('%s: %s', $action, self::summary($result)));

        return $this->redirectToRoute('trident_sylius_admin_screen', ['screen' => \in_array($screen, self::SCREENS, true) ? $screen : 'dashboard']);
    }

    public function settings(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->operateRole);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->fail($request, 'Invalid CSRF token: reload the page', 'settings', 403);
        }
        $url = $request->request->has('api_url') ? (string) $request->request->get('api_url') : null;
        $token = (string) $request->request->get('api_token', '');
        $mode = $request->request->has('purge_mode') ? (string) $request->request->get('purge_mode') : null;
        $this->settings->save($url, $token === '' ? null : $token, $mode);
        $view = $this->settings->adminView();
        if ($this->wantsJson($request)) {
            return new JsonResponse(['stored' => $view, 'settings' => $this->admin->overview()['settings'] ?? null]);
        }
        $this->addFlash('success', 'Settings saved' . ($view['token_error'] !== null ? ' — ' . $view['token_error'] : ''));

        return $this->redirectToRoute('trident_sylius_admin_screen', ['screen' => 'settings']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(Request $request): array
    {
        $body = $request->request->all();
        unset($body['_token'], $body['screen']);
        $body['confirm'] = \in_array($body['confirm'] ?? null, ['1', 'on', 'true', true], true);
        foreach (['urls', 'tags', 'ids'] as $list) {
            if (isset($body[$list]) && is_string($body[$list])) {
                $body[$list] = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $body[$list]) ?: [])));
            }
        }
        if (($body['instance'] ?? '') === '') {
            unset($body['instance']);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function summary(array $result): string
    {
        if (isset($result['results']) && is_array($result['results'])) {
            $parts = [];
            foreach ($result['results'] as $r) {
                $parts[] = sprintf('%s %s', $r['instance'] ?? '?', ($r['ok'] ?? false) ? 'ok' : 'failed (' . ($r['error'] ?? '?') . ')');
            }

            return implode(', ', $parts);
        }
        if (isset($result['delivered'])) {
            return sprintf('delivered %d, failed %d, %d entries purged', $result['delivered'], $result['failed'] ?? 0, $result['purged'] ?? 0);
        }

        return 'done';
    }

    private function fail(Request $request, string $message, string $screen, int $status): Response
    {
        if ($this->wantsJson($request)) {
            return new JsonResponse(['error' => $message], $status);
        }
        $this->addFlash('error', $message);

        return $this->redirectToRoute('trident_sylius_admin_screen', ['screen' => \in_array($screen, self::SCREENS, true) ? $screen : 'dashboard'], $status === 403 ? 303 : 302);
    }

    private function wantsJson(Request $request): bool
    {
        return str_contains((string) $request->headers->get('Accept'), 'application/json');
    }
}
