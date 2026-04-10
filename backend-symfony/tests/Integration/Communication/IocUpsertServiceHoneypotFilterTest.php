<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\HeaderIocExtractor;
use App\Application\Communication\IocCategorizer;
use App\Application\Communication\IocExportMapper;
use App\Application\Communication\IocUpsertService;
use App\Application\Communication\RiskScorer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 061 — Sprint 1 — Task 1.5
 *
 * Even when extraction runs legitimately on an incoming message
 * (direction='in'), if a scammer quotes our reply, our honeypot address would
 * be re-extracted as a "scammer IOC". This test asserts that the upsert path
 * rejects any email IOC whose normalised value matches the configured
 * honeypot addresses.
 *
 * Source of canonical addresses: env var HONEYPOT_EMAIL_ADDRESSES, exposed
 * via Symfony parameter and injected into IocUpsertService constructor.
 */
final class IocUpsertServiceHoneypotFilterTest extends KernelTestCase
{
    private const HONEYPOT_ADDRESS = 'honeypot-test-spec061@example.com';
    private const INCOMING_MSG_ID = '00000000-0000-0000-0000-000000000001';

    private function buildServiceWithHoneypot(array $honeypotAddresses): IocUpsertService
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        return new IocUpsertService(
            $em,
            $container->get(RiskScorer::class),
            $container->get(IocCategorizer::class),
            $container->get(IocExportMapper::class),
            $container->get(HeaderIocExtractor::class),
            $honeypotAddresses,
            null,
            null,
        );
    }

    public function testUpsertHoneypotEmailIsRejected(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => self::HONEYPOT_ADDRESS,
                'value_norm' => self::HONEYPOT_ADDRESS,
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertHoneypotEmailIsCaseInsensitive(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => 'Honeypot-TEST-Spec061@Example.COM',
                'value_norm' => 'Honeypot-TEST-Spec061@Example.COM',
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/honeypot/i');
        $service->upsertEnrichedIoc($payload);
    }

    public function testUpsertNonHoneypotEmailIsAccepted(): void
    {
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => 'real-scammer-spec061@example.org',
                'value_norm' => 'real-scammer-spec061@example.org',
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        // Cleanup so the test is idempotent
        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM observed_ioc WHERE obs_id = :id',
            ['id' => $observed->getObsId()]
        );
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'email' AND value_norm = :v",
            ['v' => 'real-scammer-spec061@example.org']
        );
    }

    public function testNonEmailIocsBypassHoneypotFilter(): void
    {
        // A phone or iban with a value that LITERALLY equals a honeypot email string
        // (theoretical, but we want the filter strictly scoped to type='email').
        $service = $this->buildServiceWithHoneypot([self::HONEYPOT_ADDRESS]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'iban',
                'value' => 'GB29NWBK60161331926000',
                'value_norm' => 'GB29NWBK60161331926000',
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM observed_ioc WHERE obs_id = :id',
            ['id' => $observed->getObsId()]
        );
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'iban' AND value_norm = :v",
            ['v' => 'GB29NWBK60161331926000']
        );
    }

    public function testEmptyHoneypotListBehavesAsNoOp(): void
    {
        // Safe default for fresh deployments: empty array → filter never matches.
        $service = $this->buildServiceWithHoneypot([]);

        $payload = [
            'msg_id' => self::INCOMING_MSG_ID,
            'ioc' => [
                'type' => 'email',
                'value' => self::HONEYPOT_ADDRESS,
                'value_norm' => self::HONEYPOT_ADDRESS,
                'source' => 'body',
                'first_seen' => '2026-04-10T00:00:00Z',
            ],
        ];

        $observed = $service->upsertEnrichedIoc($payload);
        $this->assertNotNull($observed->getObsId());

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);
        $conn->executeStatement(
            'DELETE FROM observed_ioc WHERE obs_id = :id',
            ['id' => $observed->getObsId()]
        );
        $conn->executeStatement(
            "DELETE FROM indicator WHERE type = 'email' AND value_norm = :v",
            ['v' => self::HONEYPOT_ADDRESS]
        );
    }
}
