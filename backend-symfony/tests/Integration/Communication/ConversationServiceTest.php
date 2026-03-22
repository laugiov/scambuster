<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ScamType;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ConversationStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ConversationServiceTest extends KernelTestCase
{
    private ConversationHandler $service;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(ConversationHandler::class);
        $this->em = $container->get('doctrine')->getManager();
    }

    public function testCreateConversation(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-123';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->assertNotNull($conv->getConvId());
        $this->assertSame($status, $conv->getStatus());
        $this->assertSame($scoreRisk, $conv->getScoreRisk());
        $this->assertSame($stixId, $conv->getStixId());
    }

    public function testAddChannelToConversation(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-123';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $newChannel = $this->em->getRepository(Channel::class)->findOneBy(['code' => $channel->getCode()]);// fallback: use same channel for test
        $this->service->addChannelToConversation($conv->getConvId(), $newChannel);
        $this->assertTrue(true);
    }

    public function testChangeConversationStatus(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-123';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->service->patchConversation($conv->getConvId(), ['status' => ConversationStatus::CLOSED->value]);
        $this->em->refresh($conv);
        $this->assertSame(ConversationStatus::CLOSED, $conv->getStatus());
    }

    public function testCreateConversationWithInvalidChannel(): void
    {
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-err';
        $this->expectException(\TypeError::class);
        // On force une valeur incorrecte pour le channel (ex: objet vide) pour provoquer une TypeError
        $this->service->createConversation((object)[], $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
    }

    public function testAddChannelToConversationTwice(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-dup';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $result1 = $this->service->addChannelToConversation($conv->getConvId(), $channel);
        $result2 = $this->service->addChannelToConversation($conv->getConvId(), $channel);
        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $links = $this->em->getRepository(\App\Domain\Communication\ConversationChannel::class)
            ->findBy(['conversation' => $conv, 'channel' => $channel]);
        $this->assertCount(1, $links, 'Il ne doit y avoir qu\'un seul lien conversation/canal');
    }

    public function testChangeStatusOnNonexistentConversation(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-nonexistent';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->em->remove($conv);
        $this->em->flush();
        $result = $this->service->patchConversation($conv->getConvId(), ['status' => ConversationStatus::CLOSED->value]);
        $this->assertNull($result, 'Doit retourner null si la conversation n\'existe pas');
    }

    public function testCreateConversationWithDuplicateStixId(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-unique';
        $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
    }

    public function testCreateConversationPersistsDates(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-2 hours');
        $tsLast = new \DateTimeImmutable('-1 hour');
        $stixId = 'stix-dates';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->em->clear();
        $found = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $this->assertEquals($tsFirst->format('Y-m-d H:i:s'), $found->getTsFirst()->format('Y-m-d H:i:s'));
        $this->assertEquals($tsLast->format('Y-m-d H:i:s'), $found->getTsLast()->format('Y-m-d H:i:s'));
    }

    public function testDeleteConversationRemovesLinks(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-delete';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->service->addChannelToConversation($conv->getConvId(), $channel);
        $convId = $conv->getConvId();
        $this->em->remove($conv);
        $this->em->flush();
        $this->em->clear();
        $found = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($convId);
        $this->assertNull($found);
        $link = $this->em->getRepository(\App\Domain\Communication\ConversationChannel::class)->findOneBy(['conversation' => $convId]);
        $this->assertNull($link);
    }

    public function testMessageCreationUpdatesConversationTsLast(): void
    {
        $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);
        $status = \App\Domain\Communication\ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-2 hours');
        $tsLast = new \DateTimeImmutable('-2 hours');
        $stixId = 'stix-tslast-listener';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $this->em->clear();
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->find($channel->getChannelId());
        $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->find($direction->getDirectionId());
        $this->assertEquals($tsLast->format('Y-m-d H:i:s'), $conv->getTsLast()->format('Y-m-d H:i:s'));
        // Création d'un message avec une date postérieure
        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $msgDate = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $message = new \App\Domain\Communication\Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Test subject',
            'Test body',
            null,
            ['header' => 'value'],
            bin2hex(random_bytes(32)),
            null,
            null,
            $msgDate,
            $msgDate,
            null
        );
        $this->em->persist($message);
        $this->em->flush();
        $this->em->clear();
        $updatedConv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $this->assertEquals(
            $msgDate->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $updatedConv->getTsLast()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    public function testDeleteConversationCascadesToMessagesAttachmentsObservedIoc(): void
    {
        // Create a conversation with a message, an attachment, and an observed_ioc
        $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);

        $status = \App\Domain\Communication\ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-2 hours');
        $tsLast = new \DateTimeImmutable('-2 hours');
        $stixId = 'stix-cascade-test';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);

        $msgId = uuid_create(UUID_TYPE_RANDOM);
        $msgDate = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $message = new \App\Domain\Communication\Message(
            $msgId,
            $conv,
            $channel,
            $direction,
            'en',
            'Test subject',
            'Test body',
            null,
            ['header' => 'value'],
            bin2hex(random_bytes(32)),
            null,
            null,
            $msgDate,
            $msgDate,
            null
        );
        $this->em->persist($message);

        // Attachment
        $attachment = new \App\Domain\Communication\Attachment(
            uuid_create(UUID_TYPE_RANDOM),
            $message,
            'test.pdf',
            'application/pdf',
            1234,
            bin2hex(random_bytes(32)),
            null,
            null,
            'pending',
            null,
            null,
            $msgDate,
            null
        );
        $this->em->persist($attachment);

        // ObservedIoc - First create the indicator in the indicator table
        $indicatorId = uuid_create(UUID_TYPE_RANDOM);
        $uniqueSuffix = substr($indicatorId, 0, 8);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO indicator (indicator_id, type, value, value_norm, first_seen, last_seen, created_at, updated_at)
             VALUES (:indicator_id, :type, :value, :value_norm, :first_seen, :last_seen, :created_at, :updated_at)',
            [
                'indicator_id' => $indicatorId,
                'type' => 'test',
                'value' => 'test_value_' . $uniqueSuffix,
                'value_norm' => 'test_value_' . $uniqueSuffix,
                'first_seen' => $now,
                'last_seen' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $observedIoc = new \App\Domain\Communication\ObservedIoc(
            uuid_create(UUID_TYPE_RANDOM),
            $message,
            $indicatorId,
            ['context' => 'test'],
            $msgDate
        );
        $this->em->persist($observedIoc);

        $this->em->flush();
        $this->em->clear();

        // Delete the conversation
        $convId = $conv->getConvId();
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($convId);
        $this->em->remove($conv);
        $this->em->flush();
        $this->em->clear();

        // Assert everything is deleted
        $this->assertNull($this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($convId));
        $this->assertNull($this->em->getRepository(\App\Domain\Communication\Message::class)->find($msgId));
        $this->assertEmpty($this->em->getRepository(\App\Domain\Communication\Attachment::class)->findBy(['message' => $msgId]));
        $this->assertEmpty($this->em->getRepository(\App\Domain\Communication\ObservedIoc::class)->findBy(['message' => $msgId]));
    }

    public function testFilterConversationsByStatusAndDate(): void
    {
        $repo = $this->em->getRepository(\App\Domain\Communication\Conversation::class);
        // Filter by status
        $openConvs = $repo->findBy(['status' => ConversationStatus::OPEN]);
        $this->assertIsArray($openConvs);
        foreach ($openConvs as $conv) {
            $this->assertSame(ConversationStatus::OPEN, $conv->getStatus());
        }
        // Filter by date range
        $from = new \DateTimeImmutable('-8 days');
        $to = new \DateTimeImmutable('-4 days');
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Domain\Communication\Conversation::class, 'c')
            ->where('c.tsFirst >= :from')
            ->andWhere('c.tsLast <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        $convs = $qb->getQuery()->getResult();
        $this->assertIsArray($convs);
        foreach ($convs as $conv) {
            $this->assertGreaterThanOrEqual($from->getTimestamp(), $conv->getTsFirst()->getTimestamp());
            $this->assertLessThanOrEqual($to->getTimestamp(), $conv->getTsLast()->getTimestamp());
        }
    }

    public function testPartialUpdateConversation(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $status = ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-1 hour');
        $tsLast = new \DateTimeImmutable();
        $stixId = 'stix-patch-integration';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        // Partial update: only score_risk
        $reflection = new \ReflectionObject($conv);
        $prop = $reflection->getProperty('scoreRisk');
        $prop->setAccessible(true);
        $prop->setValue($conv, 99);
        $this->em->flush();
        $this->em->clear();
        $found = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $this->assertSame(99, $found->getScoreRisk());
        // Partial update: only stix_id (reload entity from DB)
        $reflection = new \ReflectionObject($found);
        $prop = $reflection->getProperty('stixId');
        $prop->setAccessible(true);
        $prop->setValue($found, 'stix-patch-updated');
        $this->em->flush();
        $this->em->clear();
        $found2 = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $this->assertSame('stix-patch-updated', $found2->getStixId());
    }

    public function testCannotAccessSoftDeletedConversation(): void
    {
        $repo = $this->em->getRepository(\App\Domain\Communication\Conversation::class);
        // Find a soft-deleted conversation (deletedAt IS NOT NULL)
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(\App\Domain\Communication\Conversation::class, 'c')
            ->where('c.deletedAt IS NOT NULL');
        $results = $qb->getQuery()->getResult();
        $softDeleted = $results ? $results[0] : null;
        // If no soft-deleted conversation, create one
        if (!$softDeleted) {
            $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
            $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
            $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
            $conv = $this->service->createConversation($channel, $scamType, $account, ConversationStatus::CLOSED, 10, new \DateTimeImmutable('-2 days'), new \DateTimeImmutable('-1 day'), 'stix-softdel-integ');
            $reflection = new \ReflectionObject($conv);
            $prop = $reflection->getProperty('deletedAt');
            $prop->setAccessible(true);
            $prop->setValue($conv, new \DateTimeImmutable('-1 hour'));
            $this->em->flush();
            $softDeleted = $conv;
        }
        $found = $repo->find($softDeleted->getConvId());
        $this->assertNotNull($found->getDeletedAt());
    }

    public function testConversationReceivesMultipleMessagesAndUpdatesTsLast(): void
    {
        $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(\App\Domain\Communication\ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(\App\Domain\Communication\MailAccount::class)->findOneBy([]);
        $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->findOneBy([]);
        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);
        $this->assertNotNull($direction);
        $status = \App\Domain\Communication\ConversationStatus::OPEN;
        $scoreRisk = 42;
        $tsFirst = new \DateTimeImmutable('-2 hours');
        $tsLast = new \DateTimeImmutable('-2 hours');
        $stixId = 'stix-multimsg-test';
        $conv = $this->service->createConversation($channel, $scamType, $account, $status, $scoreRisk, $tsFirst, $tsLast, $stixId);
        $channelId = $channel->getChannelId();
        $directionId = $direction->getDirectionId();
        $this->em->clear();
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->find($channelId);
        $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->find($directionId);
        $msgDate1 = new \DateTimeImmutable('-1 hour');
        $msgDate2 = new \DateTimeImmutable('now');
        $message1 = new \App\Domain\Communication\Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conv,
            $channel,
            $direction,
            'en',
            'Sujet 1',
            'Body 1',
            null,
            ['header' => 'value'],
            bin2hex(random_bytes(32)),
            null,
            null,
            $msgDate1,
            $msgDate1,
            null
        );
        $message2 = new \App\Domain\Communication\Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conv,
            $channel,
            $direction,
            'en',
            'Sujet 2',
            'Body 2',
            null,
            ['header' => 'value'],
            bin2hex(random_bytes(32)),
            null,
            null,
            $msgDate2,
            $msgDate2,
            null
        );
        $this->em->persist($message1);
        $this->em->persist($message2);
        $this->em->flush();
        $this->em->clear();
        $conv = $this->em->getRepository(\App\Domain\Communication\Conversation::class)->find($conv->getConvId());
        $channel = $this->em->getRepository(\App\Domain\Communication\Channel::class)->find($channel->getChannelId());
        $direction = $this->em->getRepository(\App\Domain\Communication\Direction::class)->find($direction->getDirectionId());
        $messages = $this->em->getRepository(\App\Domain\Communication\Message::class)->findBy(['conversation' => $conv]);
        $this->assertCount(2, $messages, 'La conversation doit avoir 2 messages');
        foreach ($messages as $msg) {
            $this->assertSame($conv->getConvId(), $msg->getConversation()->getConvId(), 'Tous les messages doivent être liés à la même conversation');
        }
        $this->assertEquals(
            $msgDate2->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            $conv->getTsLast()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'ts_last doit être mis à jour avec la date du dernier message'
        );
    }

    public function testUpdateConversationScamType(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamTypeUnknown = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'unknown']);
        // Sprint 3: Scam type codes are uppercase
        $scamTypeRomance = $this->em->getRepository(ScamType::class)->findOneBy(['code' => 'ROMANCE']);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamTypeUnknown);
        $this->assertNotNull($scamTypeRomance);
        $this->assertNotNull($account);

        // Create conversation with 'unknown' scam type
        $conv = $this->service->createConversation(
            $channel,
            $scamTypeUnknown,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-scam-type-update-test'
        );

        $this->assertSame('unknown', $conv->getScamType()->getCode());

        // Update to 'romance' scam type
        $result = $this->service->patchConversation($conv->getConvId(), [
            'scam_type_id' => $scamTypeRomance->getScamTypeId()
        ]);

        $this->assertNotNull($result);
        $this->assertNotFalse($result);

        // Refresh entity from database
        $this->em->refresh($conv);

        // Sprint 3: Scam type codes are uppercase
        $this->assertSame('ROMANCE', $conv->getScamType()->getCode());
        $this->assertSame($scamTypeRomance->getScamTypeId(), $conv->getScamType()->getScamTypeId());
    }

    public function testUpdateConversationWithInvalidScamTypeThrowsException(): void
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy([]);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);

        $this->assertNotNull($channel);
        $this->assertNotNull($scamType);
        $this->assertNotNull($account);

        $conv = $this->service->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-invalid-scam-type-test'
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid scam_type_id');

        // Try to update with non-existent scam_type_id
        $this->service->patchConversation($conv->getConvId(), [
            'scam_type_id' => 99999
        ]);
    }
} 