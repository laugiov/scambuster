<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Campaign;

use App\Application\Campaign\ActorProfileGenerator;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ActorProfileGeneratorTest extends TestCase
{
    private Connection&MockObject $connection;
    private ActorProfileGenerator $generator;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->generator = new ActorProfileGenerator($this->connection, new NullLogger());
    }

    public function test_generateForCampaign_returns_null_with_insufficient_messages(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturn([
                ['body_text' => 'Hello', 'lang_detect' => 'en', 'headers' => null],
                ['body_text' => 'World', 'lang_detect' => 'en', 'headers' => null],
            ]);

        $result = $this->generator->generateForCampaign('campaign-123');
        $this->assertNull($result);
    }

    public function test_generateForCampaign_returns_profile_with_sufficient_messages(): void
    {
        // First call: messages query
        // Second call: IOC infra query
        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                // Messages
                [
                    ['body_text' => 'Hello dear friend, I have an important business opportunity for you.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'Please send me your bank details as soon as possible.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'This is very urgent and confidential. Please reply immediately.', 'lang_detect' => 'en', 'headers' => null],
                ],
                // IOCs
                [
                    ['type' => 'email', 'value_norm' => 'scammer@gmail.com'],
                    ['type' => 'domain', 'value_norm' => 'scam-site.com'],
                    ['type' => 'url', 'value_norm' => 'https://scam-site.com/pay'],
                    ['type' => 'iban', 'value_norm' => 'GB82WEST12345698765432'],
                ]
            );

        $result = $this->generator->generateForCampaign('campaign-123');

        $this->assertNotNull($result);
        $this->assertArrayHasKey('style_dna', $result);
        $this->assertArrayHasKey('infra_dna', $result);

        // Style DNA checks
        $style = $result['style_dna'];
        $this->assertSame(3, $style['total_messages']);
        $this->assertGreaterThan(0, $style['vocabulary_size']);
        $this->assertGreaterThan(0, $style['avg_word_length']);
        $this->assertGreaterThan(0, $style['avg_sentence_length']);
        $this->assertArrayHasKey('top_20_words', $style);
        $this->assertArrayHasKey('language_distribution', $style);
        $this->assertSame(3, $style['language_distribution']['en']);

        // Infra DNA checks
        $infra = $result['infra_dna'];
        $this->assertContains('scam-site.com', $infra['unique_domains']);
        $this->assertContains('gmail.com', $infra['email_providers']);
        $this->assertContains('iban', $infra['payment_methods']);
        $this->assertSame(4, $infra['ioc_count']);
    }

    public function test_generateForCampaign_handles_mixed_languages(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    ['body_text' => 'Bonjour cher ami, voici une opportunite.', 'lang_detect' => 'fr', 'headers' => null],
                    ['body_text' => 'Hello dear friend, here is an opportunity.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'Hola querido amigo, aqui hay una oportunidad.', 'lang_detect' => null, 'headers' => null],
                ],
                [] // empty IOCs
            );

        $result = $this->generator->generateForCampaign('campaign-123');

        $this->assertNotNull($result);
        $langs = $result['style_dna']['language_distribution'];
        $this->assertSame(1, $langs['fr']);
        $this->assertSame(1, $langs['en']);
        $this->assertSame(1, $langs['unknown']);
    }

    public function test_generateForCampaign_handles_url_with_tld_extraction(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    ['body_text' => 'Message one for analysis test data.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'Message two for analysis test data.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'Message three for analysis test data.', 'lang_detect' => 'en', 'headers' => null],
                ],
                [
                    ['type' => 'url', 'value_norm' => 'https://evil.ru/phish'],
                    ['type' => 'domain', 'value_norm' => 'scam.ng'],
                    ['type' => 'bic', 'value_norm' => 'DEUTDEFF'],
                    ['type' => 'crypto_wallet', 'value_norm' => '1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2'],
                ]
            );

        $result = $this->generator->generateForCampaign('campaign-123');

        $infra = $result['infra_dna'];
        $this->assertContains('bic', $infra['payment_methods']);
        $this->assertContains('crypto_wallet', $infra['payment_methods']);
        $this->assertNotEmpty($infra['tlds']);
    }

    public function test_generateForCampaign_handles_no_iocs(): void
    {
        $this->connection->method('fetchAllAssociative')
            ->willReturnOnConsecutiveCalls(
                [
                    ['body_text' => 'First message text body content here.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'Second message text body content here.', 'lang_detect' => 'en', 'headers' => null],
                    ['body_text' => 'Third message text body content here.', 'lang_detect' => 'en', 'headers' => null],
                ],
                [] // no IOCs
            );

        $result = $this->generator->generateForCampaign('campaign-123');

        $this->assertNotNull($result);
        $infra = $result['infra_dna'];
        $this->assertEmpty($infra['unique_domains']);
        $this->assertEmpty($infra['email_providers']);
        $this->assertEmpty($infra['payment_methods']);
        $this->assertSame(0, $infra['ioc_count']);
    }
}
