<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Application\Communication\ConversationHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Abstract base class for integration tests.
 *
 * Provides shared helpers for creating test conversations and messages
 * against the real database, reducing boilerplate across test classes.
 */
abstract class AbstractIntegrationTestCase extends KernelTestCase
{
    /** Well-known fixture UUID for the first open conversation. */
    protected const CONV_OPEN_UUID = '00000000-0000-0000-0000-000000000001';

    /** Well-known fixture UUID for the default mail account. */
    protected const ACCOUNT_UUID = '11111111-1111-1111-1111-111111111111';

    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get('doctrine')->getManager();
    }

    /**
     * Create a persisted test message within a new conversation.
     *
     * @param string      $bodyText            Message body text
     * @param array|null  $headers             Custom headers (null = defaults)
     * @param string|null $externalMessageId   External Message-ID for resolution tests
     */
    protected function createTestMessage(
        string $bodyText = 'Test message body',
        ?array $headers = null,
        ?string $externalMessageId = null,
    ): Message {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);

        $convHandler = static::getContainer()->get(ConversationHandler::class);
        $conv = $convHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-test-' . bin2hex(random_bytes(4)),
        );

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $defaultHeaders = [
            'from' => 'scammer@evil-test.com',
            'to' => 'honeypot@test.com',
            'message-id' => $externalMessageId ?? '<test-' . bin2hex(random_bytes(8)) . '@test.com>',
        ];

        $message = new Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Test Subject',
            $bodyText,
            '<p>' . $bodyText . '</p>',
            $headers ?? $defaultHeaders,
            bin2hex(random_bytes(32)),
            null,
            null,
            new \DateTimeImmutable(),
            new \DateTimeImmutable(),
            null,
        );

        if ($externalMessageId) {
            $message->setExternalMessageId($externalMessageId);
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    /**
     * Create a persisted test conversation (without messages).
     */
    protected function createTestConversation(
        ConversationStatus $status = ConversationStatus::OPEN,
        int $scoreRisk = 50,
    ): Conversation {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        $convHandler = static::getContainer()->get(ConversationHandler::class);

        return $convHandler->createConversation(
            $channel,
            $scamType,
            $account,
            $status,
            $scoreRisk,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-test-' . bin2hex(random_bytes(4)),
        );
    }
}
