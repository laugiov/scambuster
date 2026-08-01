<?php

declare(strict_types=1);

namespace App\Tests\Unit\EventListener;

use App\Infrastructure\EventListener\Auth\AccessDeniedExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class AccessDeniedExceptionListenerTest extends TestCase
{
    private AccessDeniedExceptionListener $listener;

    protected function setUp(): void
    {
        $this->listener = new AccessDeniedExceptionListener();
    }

    public function testSetsJsonResponseForAccessDeniedException(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new AccessDeniedException('Forbidden');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->listener->onKernelException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Access Denied', $data['message']);
    }

    public function testIgnoresNonAccessDeniedException(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Some other error');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);

        $this->listener->onKernelException($event);

        // Response should NOT be set
        $this->assertNull($event->getResponse());
    }
}
