<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\ActorProfileGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Additional integration tests for ActorProfileGenerator.
 *
 * Covers:
 * - style_dna numeric value types (avg_sentence_length, avg_word_length)
 * - top_20_words array size
 * - language_distribution contains at least one entry
 * - infra_dna arrays are indexed (not associative)
 * - Total words > 0 when data exists
 * - Empty campaign ID string
 */
final class ActorProfileGeneratorAdditionalTest extends KernelTestCase
{
    private ActorProfileGenerator $generator;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->generator = $container->get(ActorProfileGenerator::class);
        $this->connection = $container->get('doctrine.dbal.default_connection');
    }

    public function testRandomUuidCampaignIdReturnsNull(): void
    {
        // UUID that does not exist in any fixture
        $result = $this->generator->generateForCampaign('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $this->assertNull($result);
    }

    public function testStyleDnaAvgSentenceLengthIsNonNegative(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertGreaterThanOrEqual(0, $result['style_dna']['avg_sentence_length']);
    }

    public function testStyleDnaAvgWordLengthIsNonNegative(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertGreaterThanOrEqual(0, $result['style_dna']['avg_word_length']);
    }

    public function testStyleDnaTop20WordsMaxSize(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertLessThanOrEqual(20, count($result['style_dna']['top_20_words']));
    }

    public function testStyleDnaLanguageDistributionNotEmpty(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertNotEmpty($result['style_dna']['language_distribution']);
    }

    public function testStyleDnaTotalWordsPositive(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertGreaterThan(0, $result['style_dna']['total_words']);
    }

    public function testInfraDnaIocCountIsNonNegative(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertGreaterThanOrEqual(0, $result['infra_dna']['ioc_count']);
    }

    public function testInfraDnaArraysAreIndexed(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $infraDna = $result['infra_dna'];

        // Verify arrays are sequential (array_values == itself)
        $this->assertSame(array_values($infraDna['unique_domains']), $infraDna['unique_domains']);
        $this->assertSame(array_values($infraDna['email_providers']), $infraDna['email_providers']);
        $this->assertSame(array_values($infraDna['payment_methods']), $infraDna['payment_methods']);
        $this->assertSame(array_values($infraDna['tlds']), $infraDna['tlds']);
    }

    private function findCampaignWithMessages(int $minMessages): ?string
    {
        $result = $this->connection->fetchOne(
            'SELECT mc.campaign_id
             FROM message_campaign mc
             JOIN message m ON mc.msg_id = m.msg_id
             WHERE m.direction = (SELECT dir_id FROM lkp_direction WHERE code = \'in\')
               AND m.body_text IS NOT NULL
             GROUP BY mc.campaign_id
             HAVING COUNT(*) >= :min
             LIMIT 1',
            ['min' => $minMessages]
        );

        return $result !== false ? (string) $result : null;
    }
}
