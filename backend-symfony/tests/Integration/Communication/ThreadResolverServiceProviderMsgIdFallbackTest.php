<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\ThreadResolverService;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Direction;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\Message;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Spec 085 §US2 — ThreadResolverService gains a fallback lookup on
 * `headers->>'provider_msg_id'` when the primary `headers->>'message-id'`
 * lookup misses. Covers the 167 historical SMTP outbounds that have
 * provider_msg_id populated but message-id NULL (T04 backfill will
 * eventually normalise them, but until then the fallback ensures
 * inbound replies thread correctly).
 */
final class ThreadResolverServiceProviderMsgIdFallbackTest extends KernelTestCase
{
    private ThreadResolverService $resolver;
    private EntityManagerInterface $em;
    private ConversationHandler $conversationHandler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->resolver = $container->get(ThreadResolverService::class);
        $this->em = $container->get('doctrine')->getManager();
        $this->conversationHandler = $container->get(ConversationHandler::class);
    }

    private function createOutbound(?string $headersMessageId, ?string $providerMsgId): Message
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $scamType = $this->em->getRepository(ScamType::class)->findOneBy([]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        $dirOut = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'out']);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $scamType,
            $account,
            ConversationStatus::OPEN,
            50,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable(),
            'stix-085-' . bin2hex(random_bytes(4))
        );

        $headers = ['from' => 'honeypot@test.com', 'to' => 'scammer@example.com'];

        if ($headersMessageId !== null) {
            $headers['message-id'] = $headersMessageId;
        }

        if ($providerMsgId !== null) {
            $headers['provider_msg_id'] = $providerMsgId;
        }

        $message = new Message(
            uuid_create(UUID_TYPE_RANDOM),
            $conv,
            $channel,
            $dirOut,
            'en',
            'Re: Test',
            'body',
            null,
            $headers,
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

    public function test_resolveConversation_finds_parent_via_provider_msg_id_fallback(): void
    {
        // Scenario reproducing the 2026-05-18 prod orphan-conv incident:
        // a legacy SMTP outbound has provider_msg_id populated (with
        // chevrons) but headers.message-id NULL. The scammer's reply
        // references the bare id. Before this fix, ThreadResolver
        // returned no conversation; after the fix, it must find it via
        // the provider_msg_id fallback.
        $outbound = $this->createOutbound(
            headersMessageId: null,
            providerMsgId: '<bare-id-085-test@scambuster.local>',
        );
        $expectedConvId = $outbound->getConversation()->getConvId();

        $result = $this->resolver->resolveConversation(
            inReplyTo: 'bare-id-085-test@scambuster.local',
            references: [],
            messageId: null,
        );

        $this->assertNotNull($result['conversation'], 'fallback on provider_msg_id must yield a conversation');
        $this->assertSame($expectedConvId, $result['conversation']->getConvId());
    }

    public function test_resolveConversation_prefers_headers_lookup_when_both_populated(): void
    {
        // Regression guard: when both headers.message-id AND provider_msg_id
        // are populated (post-T02 outbound or legacy Gmail), the first
        // lookup wins. We don't care WHICH path matched, only that the
        // resolution succeeds — both queries would return the same row.
        $outbound = $this->createOutbound(
            headersMessageId: 'both-paths-085@scambuster.local',
            providerMsgId: '<both-paths-085@scambuster.local>',
        );
        $expectedConvId = $outbound->getConversation()->getConvId();

        $result = $this->resolver->resolveConversation(
            inReplyTo: 'both-paths-085@scambuster.local',
            references: [],
            messageId: null,
        );

        $this->assertNotNull($result['conversation']);
        $this->assertSame($expectedConvId, $result['conversation']->getConvId());
    }

    public function test_resolveConversation_returns_null_when_no_match_anywhere(): void
    {
        // Regression guard: unknown in-reply-to value with no matching
        // outbound anywhere → null returned (existing behavior preserved
        // so a brand-new conversation is created upstream).
        $this->createOutbound(
            headersMessageId: 'real-085@scambuster.local',
            providerMsgId: '<real-085@scambuster.local>',
        );

        $result = $this->resolver->resolveConversation(
            inReplyTo: 'totally-unknown-085@nowhere.invalid',
            references: [],
            messageId: null,
        );

        $this->assertNull($result['conversation']);
    }
}
