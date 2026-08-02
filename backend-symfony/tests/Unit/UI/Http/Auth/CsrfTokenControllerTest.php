<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Auth;

use App\UI\Http\Auth\CsrfTokenController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class CsrfTokenControllerTest extends TestCase
{
    public function test_returns_csrf_token(): void
    {
        $csrfManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfManager->method('getToken')
            ->with('default')
            ->willReturn(new CsrfToken('default', 'test-token-value'));

        $controller = new CsrfTokenController($csrfManager);
        $response = $controller->__invoke();

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('test-token-value', $data['csrf_token']);
    }
}
