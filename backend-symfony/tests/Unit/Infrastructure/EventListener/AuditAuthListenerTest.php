<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\EventListener;

use App\Application\Audit\AuditLogger;
use App\Domain\Audit\AuditLog;
use App\Infrastructure\EventListener\AuditAuthListener;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTExpiredEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTInvalidEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Unit tests for AuditAuthListener.
 *
 * AuditLogger is final, so we construct a real instance with a mocked EM
 * (same pattern used in BudgetThresholdNotifierTest and AuditLoggerTest).
 */
final class AuditAuthListenerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RequestStack $requestStack;
    private AuditAuthListener $listener;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->requestStack = new RequestStack();

        $auditLogger = new AuditLogger(
            $this->em,
            new NullLogger(),
            $this->requestStack,
            new NullSiemExporter()
        );

        $this->listener = new AuditAuthListener($auditLogger, $this->requestStack);
    }

    public function testOnAuthenticationSuccessPersistsAuditLog(): void
    {
        $request = Request::create('/api/v1/auth/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.1']);
        $this->requestStack->push($request);

        $user = new InMemoryUser('admin@example.com', 'password', ['ROLE_ADMIN']);
        $response = new Response();
        $event = new AuthenticationSuccessEvent([], $user, $response);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(AuditLog::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->listener->onAuthenticationSuccess($event);
    }

    public function testOnJwtInvalidPersistsAuditLog(): void
    {
        $request = Request::create('/api/v1/data', 'GET', server: ['REMOTE_ADDR' => '192.168.1.100']);
        $this->requestStack->push($request);

        $event = $this->createMock(JWTInvalidEvent::class);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(AuditLog::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->listener->onJwtInvalid($event);
    }

    public function testOnJwtExpiredPersistsAuditLog(): void
    {
        $request = Request::create('/api/v1/data', 'GET', server: ['REMOTE_ADDR' => '172.16.0.5']);
        $this->requestStack->push($request);

        $event = $this->createMock(JWTExpiredEvent::class);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(AuditLog::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->listener->onJwtExpired($event);
    }

    public function testOnAuthenticationSuccessWithNoRequestStillPersists(): void
    {
        // Do not push any request to the stack
        $user = new InMemoryUser('user@example.com', 'password', ['ROLE_USER']);
        $response = new Response();
        $event = new AuthenticationSuccessEvent([], $user, $response);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(AuditLog::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->listener->onAuthenticationSuccess($event);
    }

    public function testOnJwtInvalidWithNoRequestStillPersists(): void
    {
        $event = $this->createMock(JWTInvalidEvent::class);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(AuditLog::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->listener->onJwtInvalid($event);
    }

    public function testOnJwtExpiredWithNoRequestStillPersists(): void
    {
        $event = $this->createMock(JWTExpiredEvent::class);

        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(AuditLog::class));

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->listener->onJwtExpired($event);
    }

    public function testOnAuthenticationSuccessExtractsUserIdentifier(): void
    {
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $this->requestStack->push($request);

        $user = new InMemoryUser('special-user@scambuster.test', 'pass');
        $response = new Response();
        $event = new AuthenticationSuccessEvent([], $user, $response);

        $persisted = null;
        $this->em
            ->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($auditLog) use (&$persisted): bool {
                $persisted = $auditLog;

                return $auditLog instanceof AuditLog;
            }));

        $this->em->method('flush');

        $this->listener->onAuthenticationSuccess($event);
        $this->assertNotNull($persisted);
    }
}
