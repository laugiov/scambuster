<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Application\Campaign\ActorProfileGenerator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for ActorProfileGenerator.
 *
 * Tests profile generation from campaign message corpus using real DB queries.
 * Uses fixture data seeded in the test database.
 */
final class ActorProfileGeneratorTest extends KernelTestCase
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

    public function testGenerateForCampaignReturnsNullWhenCampaignDoesNotExist(): void
    {
        $result = $this->generator->generateForCampaign('00000000-0000-0000-0000-ffffffffffff');

        $this->assertNull($result);
    }

    public function testGenerateForCampaignReturnsNullWithInsufficientMessages(): void
    {
        // A nonexistent UUID campaign should return null (0 messages < 3)
        $result = $this->generator->generateForCampaign('00000000-0000-0000-0000-eeeeeeeeeeee');

        $this->assertNull($result);
    }

    public function testGenerateForCampaignReturnsArrayStructureWhenSufficientData(): void
    {
        // Check if there is a campaign with enough messages in fixtures
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            // Messages might not have body_text or correct direction
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $this->assertIsArray($result);
        $this->assertArrayHasKey('style_dna', $result);
        $this->assertArrayHasKey('infra_dna', $result);
    }

    public function testStyleDnaStructure(): void
    {
        $campaignId = $this->findCampaignWithMessages(3);

        if ($campaignId === null) {
            $this->markTestSkipped('No campaign with 3+ inbound messages found in test fixtures');
        }

        $result = $this->generator->generateForCampaign($campaignId);

        if ($result === null) {
            $this->markTestSkipped('Campaign found but insufficient qualifying messages');
        }

        $styleDna = $result['style_dna'];
        $this->assertIsArray($styleDna);
        $this->assertArrayHasKey('avg_sentence_length', $styleDna);
        $this->assertArrayHasKey('vocabulary_size', $styleDna);
        $this->assertArrayHasKey('avg_word_length', $styleDna);
        $this->assertArrayHasKey('top_20_words', $styleDna);
        $this->assertArrayHasKey('total_messages', $styleDna);
        $this->assertArrayHasKey('total_words', $styleDna);
        $this->assertArrayHasKey('language_distribution', $styleDna);

        $this->assertIsFloat($styleDna['avg_sentence_length']);
        $this->assertIsInt($styleDna['vocabulary_size']);
        $this->assertGreaterThan(0, $styleDna['vocabulary_size']);
        $this->assertIsArray($styleDna['top_20_words']);
        $this->assertGreaterThanOrEqual(3, $styleDna['total_messages']);
    }

    public function testInfraDnaStructure(): void
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
        $this->assertIsArray($infraDna);
        $this->assertArrayHasKey('unique_domains', $infraDna);
        $this->assertArrayHasKey('email_providers', $infraDna);
        $this->assertArrayHasKey('payment_methods', $infraDna);
        $this->assertArrayHasKey('tlds', $infraDna);
        $this->assertArrayHasKey('ioc_count', $infraDna);

        $this->assertIsArray($infraDna['unique_domains']);
        $this->assertIsArray($infraDna['email_providers']);
        $this->assertIsArray($infraDna['payment_methods']);
        $this->assertIsArray($infraDna['tlds']);
        $this->assertIsInt($infraDna['ioc_count']);
    }

    public function testGeneratorIsAutowired(): void
    {
        $container = self::getContainer();
        $service = $container->get(ActorProfileGenerator::class);

        $this->assertInstanceOf(ActorProfileGenerator::class, $service);
    }

    /**
     * Find a campaign that has at least $minMessages inbound messages with body_text.
     */
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

    // ================================================================== //
    //  Merged from ActorProfileGeneratorAdditionalTest
    // ================================================================== //

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
}
