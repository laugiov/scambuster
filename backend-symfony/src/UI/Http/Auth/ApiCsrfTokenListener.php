<?php

declare(strict_types=1);

namespace App\UI\Http\Auth;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AsEventListener(event: 'kernel.request', priority: 8)]
final class ApiCsrfTokenListener
{
    public function __construct(private readonly CsrfTokenManagerInterface $csrfTokenManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$request->isMethodSafe()
            && str_starts_with($request->getPathInfo(), '/api/')
            && $request->cookies->has('XSRF-TOKEN')
        ) {
            $headerToken = $request->headers->get('X-CSRF-Token');
            $cookieToken = $request->cookies->get('XSRF-TOKEN');

            if (!$headerToken || $headerToken !== $cookieToken ||
                !$this->csrfTokenManager->isTokenValid(new CsrfToken('default', $headerToken))) {
                $event->setResponse(new Response('CSRF token invalid', 403));
            }
        }
    }
}
