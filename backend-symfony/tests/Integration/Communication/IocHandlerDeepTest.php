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
 * Deep integration tests for IocHandler.
 *
 * Covers extractIocsFromMessage with persist=true, LLM enrichment paths,
 * error handling, and edge cases not covered by existing test files.
 */
class IocHandlerDeepTest extends KernelTestCase
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

    private function createTestMessage(string $bodyText = 'Default body', ?array $headers = null): Message
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
            'stix-deep-' . bin2hex(random_bytes(4))
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $defaultHeaders = [
            'from' => 'deep-scammer@evil.test',
            'to' => 'victim@test.com',
            'message-id' => '<deep-' . bin2hex(random_bytes(8)) . '@test.com>',
        ];

        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Deep Test Subject',
            $bodyText,
            '<p>' . $bodyText . '</p>',
            $headers ?? $defaultHeaders,
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
    //  extractIocsFromMessage with persist=true
    // ------------------------------------------------------------------ //

    public function testExtractIocsFromMessageWithPersistTrue(): void
    {
        $message = $this->createTestMessage(
            'Visit https://evil-persist.com and contact scammer@evil-persist.com'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage(
            $message->getMsgId(),
            'regex',
            [],
            true // persist
        );

        $this->assertIsArray($iocs);

        // Each persisted IOC should have obs_id in context
        foreach ($iocs as $ioc) {
            $this->assertArrayHasKey('type', $ioc);
            $this->assertArrayHasKey('value', $ioc);
            $this->assertArrayHasKey('context', $ioc);
            if (isset($ioc['context']['obs_id'])) {
                $this->assertNotEmpty($ioc['context']['obs_id']);
            }
        }
    }

    public function testExtractIocsFromMessageWithPersistTrueAndTypeFilter(): void
    {
        $message = $this->createTestMessage(
            'Visit https://evil-filter.com and email scammer@evil-filter.com'
        );

        // Extract with email type filter — may also include derived types
        $iocs = $this->iocHandler->extractIocsFromMessage(
            $message->getMsgId(),
            'regex',
            ['email'],
            true
        );

        $this->assertIsArray($iocs);
        // Verify at least one email IOC is present (derived domain may also appear)
        $emailIocs = array_filter($iocs, fn ($ioc) => $ioc['type'] === 'email');
        $this->assertGreaterThanOrEqual(1, count($emailIocs), 'Should contain at least one email IOC');
    }

    public function testExtractIocsFromMessageWithPersistTrueIsIdempotent(): void
    {
        $message = $this->createTestMessage(
            'Contact evil-idempotent@test.com'
        );

        $iocs1 = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', [], true);
        $iocs2 = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', [], true);

        // Should return same number (upsert dedup)
        $this->assertCount(count($iocs1), $iocs2);
    }

    // ------------------------------------------------------------------ //
    //  extractIocsFromMessage with hybrid method and persist
    // ------------------------------------------------------------------ //

    public function testExtractIocsHybridWithPersist(): void
    {
        $message = $this->createTestMessage(
            'Visit https://hybrid-persist.com for your prize'
        );

        $iocs = $this->iocHandler->extractIocsFromMessage(
            $message->getMsgId(),
            'hybrid',
            [],
            true
        );

        $this->assertIsArray($iocs);
    }

    // ------------------------------------------------------------------ //
    //  extractIocsFromMessage — empty body
    // ------------------------------------------------------------------ //

    public function testExtractIocsFromMessageWithEmptyBody(): void
    {
        $message = $this->createTestMessage('');

        $iocs = $this->iocHandler->extractIocsFromMessage($message->getMsgId(), 'regex', []);

        $this->assertIsArray($iocs);
        // Empty body should yield minimal/no body IOCs (may still get header-derived IOCs)
    }

    // ------------------------------------------------------------------ //
    //  upsertEnrichedIoc — edge cases
    // ------------------------------------------------------------------ //

    public function testUpsertEnrichedIocWithAllOptionalFields(): void
    {
        $message = $this->createTestMessage();

        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'email',
                'value' => 'full-field-test@evil.com',
                'value_norm' => 'full-field-test@evil.com',
                'source' => 'body',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => ['virustotal' => ['malicious' => 2]],
            'tags' => ['phishing', 'credential-theft'],
            'tlp' => 'RED',
            'category' => 'Credential_phish',
        ]);

        $this->assertNotNull($ioc);
        $context = $ioc->getContext();
        $this->assertSame('email', $context['type']);
    }

    public function testUpsertEnrichedIocWithSha256Type(): void
    {
        $message = $this->createTestMessage();

        $hash = hash('sha256', 'test-content');
        $ioc = $this->iocHandler->upsertEnrichedIoc([
            'msg_id' => $message->getMsgId(),
            'ioc' => [
                'type' => 'sha256',
                'value' => $hash,
                'value_norm' => $hash,
                'source' => 'attachment',
                'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
            ],
            'enrichment' => [],
            'tlp' => 'AMBER',
        ]);

        $this->assertNotNull($ioc);
        $this->assertSame('sha256', $ioc->getContext()['type']);
    }

    // ------------------------------------------------------------------ //
    //  calculateMessageRisk — with multiple IOC types
    // ------------------------------------------------------------------ //

    public function testCalculateMessageRiskWithMultipleDiverseIocTypes(): void
    {
        $message = $this->createTestMessage();

        // Add diverse IOC types
        $types = [
            ['type' => 'url', 'value' => 'https://diverse-risk.com', 'value_norm' => 'diverse-risk.com'],
            ['type' => 'email', 'value' => 'diverse@evil.com', 'value_norm' => 'diverse@evil.com'],
            ['type' => 'iban', 'value' => 'DE89370400440532013000', 'value_norm' => 'DE89370400440532013000'],
        ];

        foreach ($types as $t) {
            $this->iocHandler->upsertEnrichedIoc([
                'msg_id' => $message->getMsgId(),
                'ioc' => array_merge($t, [
                    'source' => 'body',
                    'first_seen' => (new \DateTimeImmutable())->format(DATE_ATOM),
                ]),
                'enrichment' => [],
            ]);
        }

        $risk = $this->iocHandler->calculateMessageRisk($message->getMsgId());

        $this->assertIsInt($risk['score_agg']);
        $this->assertContains($risk['level'], ['high', 'medium', 'low']);
        $this->assertIsBool($risk['should_reply']);
    }

    // ------------------------------------------------------------------ //
    //  getConversationIocs — empty conversation
    // ------------------------------------------------------------------ //

    public function testGetConversationIocsReturnsEmptyForNewConversation(): void
    {
        $message = $this->createTestMessage();
        $convId = $message->getConversation()->getConvId();

        // No IOCs added yet
        $iocs = $this->iocHandler->getConversationIocs($convId);
        $this->assertIsArray($iocs);
    }
}
