<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 061 — Sprint 2 — Permanent anti-regression test.
 *
 * Asserts that no observed_ioc row references a message with direction='out'.
 *
 * If this test ever fails, it means a regression has reintroduced the bug
 * spec 061 fixed: somewhere in the codebase IOCs are being extracted out of
 * outgoing messages, polluting the indicator table with the honeypot's own
 * data + LLM-generated content (555 phones, etc.).
 *
 * To fix a failure:
 *   1. Run `bin/console app:indicator:cleanup-platform-contamination --dry-run`
 *   2. Inspect var/audit/061-cleanup-*.csv to confirm what was leaked
 *   3. Find the new entry point that bypassed Message::canExtractIocs() guard
 *   4. Add a guard there + a per-entry-point regression test
 *   5. Run the cleanup command for real
 */
final class NoIocFromOutgoingMessageTest extends KernelTestCase
{
    public function testNoObservedIocReferencesOutgoingMessage(): void
    {
        self::bootKernel();

        /** @var Connection $conn */
        $conn = self::getContainer()->get(Connection::class);

        $count = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM observed_ioc oi
             JOIN message m ON oi.msg_id = m.msg_id
             JOIN lkp_direction d ON m.direction = d.dir_id
             WHERE d.code = :code',
            ['code' => 'out']
        );

        $this->assertSame(
            0,
            $count,
            sprintf(
                'Found %d observed_ioc rows linked to outgoing messages — spec 061 regression. '
                . 'Run `bin/console app:indicator:cleanup-platform-contamination --dry-run` '
                . 'to inspect, then fix the entry point that bypassed the direction guard.',
                $count,
            )
        );
    }
}
