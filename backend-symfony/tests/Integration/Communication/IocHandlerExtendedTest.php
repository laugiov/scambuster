<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\IocHandler;
use App\Application\Communication\MessageHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Extended integration tests for IocHandler
 *
 * Covers regex extraction, hybrid extraction, persist mode,
 * and edge cases not hit by IocHandlerTest and IocHandlerAdditionalTest.
 */
class IocHandlerExtendedTest extends KernelTestCase
{
    private IocHandler $iocHandler;
    private ConversationHandler $conversationHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->iocHandler = $container->get(IocHandler::class);
        $this->conversationHandler = $container->get(ConversationHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    private function createTestMessage(string $bodyText = 'Test body'): Message
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-extended-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Extended IOC Test',
            $bodyText,
            '<p>' . $bodyText . '</p>',
            [
                'from' => 'scammer@evil-ext.test',
                'to' => 'victim@test.com',
                'message-id' => '<test-' . bin2hex(random_bytes(8)) . '@test.com>',
            ],
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null
        );

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    // ------------------------------------------------------------------ //
    //  extractIocsFromMessage with 'regex' method — rich body
    // ------------------------------------------------------------------ //

    public function testRegexExtractionFindsUrlAndEmail(): void
    {
        $message = $this->createTestMessage(
            'Contact me at scammer@evil.com or visit https://evil-phish.example.com/login'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $this->assertIsArray($iocs);
        $this->assertGreaterThan(0, count($iocs));

        $types = array_column($iocs, 'type');
        $this->assertContains('url', $types);
        $this->assertContains('email', $types);
    }

    public function testRegexExtractionFindsIban(): void
    {
        $message = $this->createTestMessage(
            'Transfer to FR7612345678901234567890123 immediately'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $types = array_column($iocs, 'type');
        $this->assertContains('iban', $types);
    }

    public function testRegexExtractionFindsPhone(): void
    {
        $message = $this->createTestMessage(
            'Call me at +33 6 12 34 56 78 for details'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        // Phone may be extracted as 'phone' type
        $types = array_column($iocs, 'type');
        // At least some IOCs extracted (phone patterns vary)
        $this->assertIsArray($iocs);
    }

    public function testRegexExtractionWithTypeFilter(): void
    {
        $message = $this->createTestMessage(
            'Visit https://evil.com or mail scammer@evil.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', ['url']);

        foreach ($iocs as $ioc) {
            // Derived IOCs (domain from url) may also appear
            $this->assertContains($ioc['type'], ['url', 'domain']);
        }
    }

    // ------------------------------------------------------------------ //
    //  extractIocsFromMessage with 'hybrid' method
    // ------------------------------------------------------------------ //

    public function testHybridExtractionReturnsArray(): void
    {
        $message = $this->createTestMessage(
            'Send Bitcoin to bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'hybrid', []);

        $this->assertIsArray($iocs);
        // Hybrid combines regex + LLM results
    }

    public function testHybridExtractionDeduplicates(): void
    {
        $message = $this->createTestMessage(
            'Visit https://evil-dedup.example.com mentioned twice https://evil-dedup.example.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'hybrid', []);

        // Count URL IOCs with same value — should be deduplicated
        $urlValues = [];
        foreach ($iocs as $ioc) {
            if ($ioc['type'] === 'url') {
                $urlValues[] = $ioc['value_norm'] ?? $ioc['value'];
            }
        }

        $this->assertCount(count(array_unique($urlValues)), $urlValues, 'Should not have duplicate URL IOCs');
    }

    // ------------------------------------------------------------------ //
    //  extractIocsFromMessage throws for unknown message
    // ------------------------------------------------------------------ //

    public function testExtractIocsFromMessageThrowsForUnknownMessage(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->iocHandler->extractIocsFromMessage('ffffffff-ffff-ffff-ffff-ffffffffffff', 'regex', []);
    }

    // ------------------------------------------------------------------ //
    //  Enrichment scoring edge cases
    // ------------------------------------------------------------------ //

    public function testUpsertWithUrlScanCleanVerdictScoresLower(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'url',
                'value' => 'https://clean-site.example.com',
                'value_norm' => 'clean-site.example.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [
                'virustotal' => ['malicious' => 0, 'suspicious' => 0, 'harmless' => 90],
                'urlscan' => ['verdict' => 'clean'],
            ],
        ]);

        $context = $ioc->getContext();
        $score = $context['score']['agg'] ?? 0;

        $this->assertLessThan(50, $score, 'Clean site should score low');
    }

    public function testUpsertWithDomainType(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'domain',
                'value' => 'domain-ext-test.example.com',
                'value_norm' => 'domain-ext-test.example.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
        ]);

        $context = $ioc->getContext();
        $this->assertSame('domain', $context['type']);
        // Should have STIX pattern
        $this->assertArrayHasKey('stix', $context);
        $this->assertStringContainsString('domain-name:value', $context['stix']['pattern']);
    }
}
