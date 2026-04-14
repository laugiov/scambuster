<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Audit;

use App\Application\Audit\AuditEventQueryService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditEventQueryServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private AuditEventQueryService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->connection);
        $this->service = new AuditEventQueryService($em);
    }

    public function test_parseSince_relative_hours(): void
    {
        $result = $this->service->parseSince('24h');
        $expected = new \DateTimeImmutable('-24 hours');
        // Within 2 seconds tolerance
        $this->assertEqualsWithDelta($expected->getTimestamp(), $result->getTimestamp(), 2);
    }

    public function test_parseSince_relative_days(): void
    {
        $result = $this->service->parseSince('7d');
        $expected = new \DateTimeImmutable('-7 days');
        $this->assertEqualsWithDelta($expected->getTimestamp(), $result->getTimestamp(), 2);
    }

    public function test_parseSince_relative_minutes(): void
    {
        $result = $this->service->parseSince('30m');
        $expected = new \DateTimeImmutable('-30 minutes');
        $this->assertEqualsWithDelta($expected->getTimestamp(), $result->getTimestamp(), 2);
    }

    public function test_parseSince_absolute_date(): void
    {
        $result = $this->service->parseSince('2026-01-15');
        $this->assertSame('2026-01-15', $result->format('Y-m-d'));
        $this->assertSame('00:00:00', $result->format('H:i:s'));
    }

    public function test_parseSince_fallback_on_invalid(): void
    {
        $result = $this->service->parseSince('invalid');
        $expected = new \DateTimeImmutable('-24 hours');
        $this->assertEqualsWithDelta($expected->getTimestamp(), $result->getTimestamp(), 2);
    }

    public function test_fetchEventsSince_returns_siem_events(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'event_type' => 'AUTH_SUCCESS',
                'created_at' => '2026-01-15 10:00:00',
                'actor_type' => 'user',
                'actor_id' => 'user@test.com',
                'action' => 'login',
                'outcome' => 'success',
                'details' => '{"ip":"127.0.0.1"}',
                'resource_type' => null,
                'resource_id' => null,
                'ip_address' => '127.0.0.1',
                'trace_id' => 'trace-1',
            ],
        ]);

        $events = $this->service->fetchEventsSince(new \DateTimeImmutable('2026-01-01'));

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertSame('user@test.com', $event->actorId);
        $this->assertSame('login', $event->action);
        $this->assertSame('success', $event->outcome);
    }

    public function test_fetchEventsSince_returns_empty_when_no_rows(): void
    {
        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $events = $this->service->fetchEventsSince(new \DateTimeImmutable('2026-01-01'));
        $this->assertEmpty($events);
    }
}
