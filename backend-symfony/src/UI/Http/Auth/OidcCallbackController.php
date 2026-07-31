<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use App\Application\Audit\AuditLogger;
use App\Application\Auth\AuthServiceInterface;
use App\Application\Auth\Dto\LoginResponseDto;
use App\Application\Auth\Oidc\OidcConfig;
use App\Application\Auth\Oidc\OidcException;
use App\Application\Auth\Oidc\OidcService;
use App\Application\Auth\Oidc\OidcStateManager;
use App\Application\Auth\Oidc\OidcUserProvisioner;
use App\Domain\Audit\AuditEventType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Completes the OIDC round-trip: verifies state, exchanges the code, resolves the
 * local user, and mints the SAME local session (JWT + refresh) that password login
 * issues — so everything downstream is identical. Returns 404 when SSO is disabled.
 */
#[Route('/api/v1/auth/oidc/callback', name: 'api_auth_oidc_callback', methods: ['GET'])]
final readonly class OidcCallbackController
{
    public function __construct(
        private OidcConfig $config,
        private OidcStateManager $stateManager,
        private OidcService $service,
        private OidcUserProvisioner $provisioner,
        private AuthServiceInterface $auth,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->config->enabled) {
            throw new NotFoundHttpException();
        }

        if ($request->query->has('error')) {
            return $this->fail('Identity provider returned an error.', $request);
        }

        $cookie = $request->cookies->get(OidcLoginController::STATE_COOKIE);
        $code = (string) $request->query->get('code', '');
        $returnedState = (string) $request->query->get('state', '');

        if ($cookie === null || $cookie === '' || $code === '' || $returnedState === '') {
            return $this->fail('Missing OIDC callback parameters.', $request);
        }

        try {
            $flow = $this->stateManager->parse($cookie);

            if (!hash_equals($flow->state, $returnedState)) {
                throw new OidcException('OIDC state mismatch.');
            }

            $identity = $this->service->exchangeAndVerify($code, $flow);
            $user = $this->provisioner->resolve($identity);
            $session = $this->auth->issueSessionFor($user);
        } catch (OidcException $e) {
            $this->auditLogger->log(
                eventType: AuditEventType::AUTH_FAILURE,
                actorId: 'oidc',
                action: 'oidc_login',
                outcome: 'failure',
                details: ['reason' => $e->getMessage()],
                ipAddress: $request->getClientIp(),
            );

            return $this->fail('SSO authentication failed.', $request);
        }

        $this->auditLogger->log(
            eventType: AuditEventType::AUTH_SUCCESS,
            actorId: $identity->email,
            action: 'oidc_login',
            outcome: 'success',
            details: ['method' => 'oidc'],
            ipAddress: $request->getClientIp(),
        );

        $response = $this->success($session);
        $response->headers->clearCookie(OidcLoginController::STATE_COOKIE, OidcLoginController::COOKIE_PATH);

        return $response;
    }

    private function success(LoginResponseDto $session): Response
    {
        if ($this->config->successRedirect !== '') {
            $fragment = http_build_query([
                'access_token'  => $session->accessToken,
                'refresh_token' => $session->refreshToken,
                'expires_in'    => $session->expiresIn,
            ]);

            return new RedirectResponse($this->config->successRedirect . '#' . $fragment);
        }

        return new JsonResponse([
            'access_token'  => $session->accessToken,
            'refresh_token' => $session->refreshToken,
            'expires_in'    => $session->expiresIn,
        ], Response::HTTP_OK);
    }

    private function fail(string $message, Request $request): Response
    {
        if ($this->config->successRedirect !== '') {
            return new RedirectResponse($this->config->successRedirect . '#' . http_build_query(['error' => $message]));
        }

        return new JsonResponse(['message' => $message], Response::HTTP_UNAUTHORIZED);
    }
}
