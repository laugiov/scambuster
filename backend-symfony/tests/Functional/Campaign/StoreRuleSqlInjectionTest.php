<?php

declare(strict_types=1);

namespace Tests\Functional\Campaign;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Regression test against arbitrary SQL execution via a stored campaign rule.
 *
 * POST /api/v1/campaign/rule accepted a client-supplied `compiled_sql.{sql,params}`,
 * stored it verbatim, and CampaignHunter later executed it against the database on
 * the hourly cron — full attacker control of the executed SQL. The fix transpiles
 * the rule DSL server-side and NEVER trusts client SQL. This test proves the
 * client SQL is discarded: the stored rule carries the server-generated SELECT,
 * not the attacker's DELETE.
 */
final class StoreRuleSqlInjectionTest extends WebTestCase
{
    private const CAMPAIGN_ID = 'cafe0001-0000-4000-8000-000000000001';
    private const MALICIOUS_SQL = 'DELETE FROM app_users WHERE 1=1; --';
    private const VALID_DSL = 'RULE sqli_probe { WHERE subject.simhash≈"urgent payment" ±15% ACTION tag="x" }';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // dama transaction: a kernel reboot would open a second connection that
        // cannot see the seeded campaign.
        $this->client->disableReboot();
        $this->seedCampaign();
    }

    protected function tearDown(): void
    {
        $conn = $this->connection();
        $conn->executeStatement('DELETE FROM campaign_rule WHERE campaign_id = ?', [self::CAMPAIGN_ID]);
        $conn->executeStatement('DELETE FROM campaign WHERE campaign_id = ?', [self::CAMPAIGN_ID]);

        parent::tearDown();
    }

    public function testClientSuppliedSqlIsNeverStoredOrExecutable(): void
    {
        $this->client->request('POST', '/api/v1/campaign/rule', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer fake-admin-jwt',
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode([
            'campaign_id' => self::CAMPAIGN_ID,
            'dsl' => self::VALID_DSL,
            // The attack: a destructive statement smuggled through the field the
            // endpoint used to trust.
            'compiled_sql' => ['sql' => self::MALICIOUS_SQL, 'params' => []],
        ]));

        self::assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        // Read back the SQL that CampaignHunter would execute.
        $storedSql = $this->connection()->fetchOne(
            "SELECT compiled_sql->>'sql' FROM campaign_rule WHERE campaign_id = ? ORDER BY created_at DESC LIMIT 1",
            [self::CAMPAIGN_ID]
        );

        self::assertIsString($storedSql);
        self::assertStringNotContainsStringIgnoringCase('DELETE', $storedSql, 'attacker SQL must never be stored');
        self::assertStringNotContainsString('app_users', $storedSql);
        // What IS stored is the server transpilation of the DSL: a read-only SELECT.
        self::assertStringContainsStringIgnoringCase('SELECT', $storedSql);
        self::assertStringContainsStringIgnoringCase('FROM message', $storedSql);
    }

    private function seedCampaign(): void
    {
        $conn = $this->connection();
        $conn->executeStatement('DELETE FROM campaign_rule WHERE campaign_id = ?', [self::CAMPAIGN_ID]);
        $conn->executeStatement('DELETE FROM campaign WHERE campaign_id = ?', [self::CAMPAIGN_ID]);

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn->executeStatement(
            'INSERT INTO campaign (campaign_id, first_seen, status, tlp, severity, dsl_hash, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::CAMPAIGN_ID, $now, 'shadow', 'AMBER', 3, 'sqli-test-hash', 'sqli-test', $now, $now]
        );
    }

    private function connection(): Connection
    {
        /** @var Connection $conn */
        $conn = static::getContainer()->get('doctrine.dbal.default_connection');

        return $conn;
    }
}
