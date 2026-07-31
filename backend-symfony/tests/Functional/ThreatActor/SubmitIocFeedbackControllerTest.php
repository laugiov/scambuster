<?php

declare(strict_types=1);

namespace App\Tests\Functional\ThreatActor;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SubmitIocFeedbackControllerTest extends WebTestCase
{
    private const INDICATOR = 'ffffffff-0002-4000-8000-000000000001';
    private const UNKNOWN = 'ffffffff-0002-4000-8000-000000000099';

    private KernelBrowser $client;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->conn = static::getContainer()->get(Connection::class);

        $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        $this->conn->executeStatement(
            "INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, occurrences, tlp, created_at, updated_at)
             VALUES (:id, 'domain', 'fb-ctrl.com', 'fb-ctrl.com', NOW(), NOW(), 1, 'AMBER', NOW(), NOW())",
            ['id' => self::INDICATOR],
        );
    }

    protected function tearDown(): void
    {
        $this->conn->executeStatement('DELETE FROM indicator WHERE indicator_id = :id', ['id' => self::INDICATOR]);
        parent::tearDown();
    }

    private function post(string $indicatorId, ?array $body, ?string $token): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $this->client->request('POST', "/api/v1/iocs/{$indicatorId}/feedback", [], [], $server, $body === null ? '' : (string) json_encode($body));
    }

    public function testUnauthenticatedIsRejected(): void
    {
        $this->post(self::INDICATOR, ['verdict' => 'confirmed'], null);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    // NB: the ioc:feedback permission gate (#[IsGranted]) is enforced by
    // PermissionVoter via App\User::hasPermission() in production. It is not
    // functionally exercisable here: the test harness authenticates InMemoryUsers,
    // which PermissionVoter grants ALL permissions to (ROLE_USER convenience).

    public function testUnknownIndicatorReturns404(): void
    {
        $this->post(self::UNKNOWN, ['verdict' => 'confirmed'], 'fake-admin-jwt');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testInvalidVerdictReturns422(): void
    {
        $this->post(self::INDICATOR, ['verdict' => 'maybe'], 'fake-admin-jwt');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testValidVerdictIsRecorded(): void
    {
        $this->post(self::INDICATOR, ['verdict' => 'false_positive', 'note' => 'known good domain'], 'fake-admin-jwt');

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('false_positive', $data['verdict']);

        $stored = $this->conn->fetchOne(
            'SELECT verdict FROM ioc_analyst_feedback WHERE indicator_id = :id',
            ['id' => self::INDICATOR],
        );
        self::assertSame('false_positive', $stored);
    }
}
