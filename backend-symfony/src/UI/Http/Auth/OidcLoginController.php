<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Auth\Oidc\OidcConfig;
use App\Application\Auth\Oidc\OidcService;
use App\Application\Auth\Oidc\OidcStateManager;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Starts the optional OIDC SSO login: mints per-flow secrets, stores them in a
 * short-lived signed cookie, and redirects the browser to the IdP. Returns 404
 * when SSO is disabled (the default) — password login is unaffected.
 */
#[Route('/api/v1/auth/oidc/login', name: 'api_auth_oidc_login', methods: ['GET'])]
final readonly class OidcLoginController
{
    public const STATE_COOKIE = 'sb_oidc_state';
    public const COOKIE_PATH = '/api/v1/auth/oidc';

    public function __construct(
        private OidcConfig $config,
        private OidcStateManager $stateManager,
        private OidcService $service,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->config->enabled) {
            throw new NotFoundHttpException();
        }

        $flow = $this->stateManager->issue();
        $authorizationUrl = $this->service->buildAuthorizationUrl($flow);

        $response = new RedirectResponse($authorizationUrl);
        $response->headers->setCookie(Cookie::create(
            self::STATE_COOKIE,
            $this->stateManager->serialize($flow),
            time() + OidcStateManager::cookieMaxAge(),
            self::COOKIE_PATH,
            null,
            true,
            true,
            false,
            Cookie::SAMESITE_LAX,
        ));

        return $response;
    }
}
