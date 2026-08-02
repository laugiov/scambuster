<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Monitoring;

use App\Application\Monitoring\AuditQueryService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AuditQueryServiceTest extends TestCase
{
    private Connection&MockObject $connection;
    private AuditQueryService $service;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->service = new AuditQueryService($this->connection);
    }

    public function test_query_without_filters(): void
    {
        $this->connection->method('fetchOne')->willReturn(42);
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id' => 1,
                'event_type' => 'auth_success',
                'actor_type' => 'user',
                'actor_id' => 'user-1',
                'resource_type' => 'session',
                'resource_id' => 'sess-1',
                'action' => 'login',
                'outcome' => 'success',
                'details' => '{"ip":"127.0.0.1"}',
                'ip_address' => '127.0.0.1',
                'trace_id' => 'trace-1',
                'created_at' => '2026-01-01 00:00:00',
            ],
        ]);

        $result = $this->service->query(null, null, 10, 0);

        $this->assertSame(42, $result['total']);
        $this->assertCount(1, $result['events']);

        $event = $result['events'][0];
        $this->assertSame(1, $event['id']);
        $this->assertSame('auth_success', $event['event_type']);
        $this->assertSame('login', $event['action']);
        $this->assertSame(['ip' => '127.0.0.1'], $event['details']);
    }

    public function test_query_with_event_type_filter(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('event_type = :event_type'),
                $this->callback(fn (array $p) => $p['event_type'] === 'auth_failure'),
            )
            ->willReturn(5);

        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->query('auth_failure', null, 10, 0);
        $this->assertSame(5, $result['total']);
    }

    public function test_query_with_actor_id_filter(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->stringContains('actor_id = :actor_id'),
                $this->callback(fn (array $p) => $p['actor_id'] === 'user-42'),
            )
            ->willReturn(3);

        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->query(null, 'user-42', 10, 0);
        $this->assertSame(3, $result['total']);
    }

    public function test_query_with_both_filters(): void
    {
        $this->connection->expects($this->once())
            ->method('fetchOne')
            ->with(
                $this->logicalAnd(
                    $this->stringContains('event_type = :event_type'),
                    $this->stringContains('actor_id = :actor_id'),
                ),
                $this->callback(fn (array $p) => isset($p['event_type']) && isset($p['actor_id'])),
            )
            ->willReturn(1);

        $this->connection->method('fetchAllAssociative')->willReturn([]);

        $result = $this->service->query('auth_success', 'admin', 10, 0);
        $this->assertSame(1, $result['total']);
    }

    public function test_query_handles_invalid_json_details(): void
    {
        $this->connection->method('fetchOne')->willReturn(1);
        $this->connection->method('fetchAllAssociative')->willReturn([
            [
                'id' => 1,
                'event_type' => 'test',
                'actor_type' => 'system',
                'actor_id' => 's1',
                'resource_type' => null,
                'resource_id' => null,
                'action' => 'test',
                'outcome' => 'success',
                'details' => 'not-json',
                'ip_address' => null,
                'trace_id' => null,
                'created_at' => '2026-01-01',
            ],
        ]);

        $result = $this->service->query(null, null, 10, 0);
        // json_decode returns null for invalid JSON
        $this->assertNull($result['events'][0]['details']);
    }
}
