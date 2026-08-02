<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationScamTaxonomyProvider;
use App\Application\Communication\ScamTaxonomyMapper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration test for the multi-label scam-taxonomy tag provider.
 *
 * Fixture conversation 0001 has primary type UNKNOWN. We attach two secondary
 * types (ROMANCE + TECH_SUPPORT) that deliberately SHARE one RSIT class
 * (rsit:fraud="scam") and one ATT&CK technique (T1656), so the test proves the
 * dedup: shared RSIT/galaxy tags collapse to one while the distinct scam-type
 * codes are all preserved.
 */
class ConversationScamTaxonomyProviderTest extends KernelTestCase
{
    private const CONV_ID = '00000000-0000-0000-0000-000000000001';

    private ConversationScamTaxonomyProvider $provider;
    private Connection $conn;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->conn = $em->getConnection();
        // Construct directly — the provider is a private autowired service (inlined
        // until a controller references it), and its only deps are the EM + the pure mapper.
        $this->provider = new ConversationScamTaxonomyProvider($em, new ScamTaxonomyMapper());
    }

    public function testTagsIncludePrimaryAndSecondariesWithDedup(): void
    {
        // Control the FULL input — pin both primary and secondary — so the test is
        // deterministic and order-independent (an earlier integration test may have
        // committed a different primary scam type onto this fixture conversation).
        $orig = $this->conn->fetchAssociative(
            'SELECT scam_type_id, secondary_scam_types FROM conversation WHERE conv_id = ?',
            [self::CONV_ID],
        );
        self::assertIsArray($orig);
        $unknownId = $this->conn->fetchOne("SELECT scam_type_id FROM lkp_scam_type WHERE code = 'UNKNOWN'");

        try {
            $this->conn->executeStatement(
                'UPDATE conversation SET scam_type_id = :pid, secondary_scam_types = :sec::jsonb WHERE conv_id = :cid',
                [
                    'pid' => $unknownId,
                    'sec' => json_encode([
                        ['code' => 'ROMANCE', 'confidence' => 0.6],
                        ['code' => 'TECH_SUPPORT', 'confidence' => 0.4],
                    ]),
                    'cid' => self::CONV_ID,
                ],
            );

            $tags = $this->provider->tagsForConversation(self::CONV_ID);

            // Primary (UNKNOWN) — RSIT + scam-type, and NO galaxy (UNKNOWN has no technique).
            self::assertContains('scambuster:scam-type="UNKNOWN"', $tags);
            self::assertContains('rsit:fraud="other"', $tags);

            // Both secondaries preserved as distinct scam-type codes.
            self::assertContains('scambuster:scam-type="ROMANCE"', $tags);
            self::assertContains('scambuster:scam-type="TECH_SUPPORT"', $tags);

            // Shared RSIT class + shared ATT&CK galaxy each appear exactly once.
            self::assertContains('rsit:fraud="scam"', $tags);
            self::assertContains('misp-galaxy:mitre-attack-pattern="Impersonation - T1656"', $tags);
            self::assertSame(1, $this->countTag('rsit:fraud="scam"', $tags), 'shared RSIT tag must be deduped');
            self::assertSame(1, $this->countTag('misp-galaxy:mitre-attack-pattern="Impersonation - T1656"', $tags), 'shared galaxy tag must be deduped');

            // No fabricated galaxy for the technique-less UNKNOWN primary.
            self::assertNotContains('misp-galaxy:mitre-attack-pattern="Phishing - T1566"', $tags);

            // Exactly the six expected unique tags.
            self::assertCount(6, $tags, 'expected 6 deduped tags, got: ' . implode(', ', $tags));
        } finally {
            $this->conn->executeStatement(
                'UPDATE conversation SET scam_type_id = :pid, secondary_scam_types = :sec WHERE conv_id = :cid',
                [
                    'pid' => $orig['scam_type_id'],
                    'sec' => \is_string($orig['secondary_scam_types'] ?? null) ? $orig['secondary_scam_types'] : null,
                    'cid' => self::CONV_ID,
                ],
            );
        }
    }

    public function testUnknownConversationReturnsEmpty(): void
    {
        self::assertSame([], $this->provider->tagsForConversation('ffffffff-ffff-ffff-ffff-ffffffffffff'));
    }

    /**
     * @param list<string> $tags
     */
    private function countTag(string $needle, array $tags): int
    {
        return \count(array_filter($tags, static fn (string $t): bool => $t === $needle));
    }
}
