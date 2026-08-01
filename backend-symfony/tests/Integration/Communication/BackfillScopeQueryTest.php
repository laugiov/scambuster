<?php

declare(strict_types=1);

namespace App\Tests\Integration\Communication;

use App\Application\Communication\ConversationHandler;
use App\Application\Communication\ScamClassificationHandler;
use App\Domain\Communication\Channel;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\MailAccount;
use App\Domain\Communication\ScamType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The backfill scope query must select ONLY conversations that are both
 * created within the trailing window AND currently UNKNOWN AND not
 * soft-deleted — the safety envelope for re-classifying the recent past.
 */
class BackfillScopeQueryTest extends KernelTestCase
{
    private ScamClassificationHandler $handler;
    private ConversationHandler $conversationHandler;
    private \Doctrine\ORM\EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->handler = $c->get(ScamClassificationHandler::class);
        $this->conversationHandler = $c->get(ConversationHandler::class);
        $this->em = $c->get('doctrine')->getManager();
    }

    private function makeConversation(string $typeCode, \DateTimeImmutable $createdAt, bool $deleted = false): string
    {
        $channel = $this->em->getRepository(Channel::class)->findOneBy(['code' => 'email']);
        $type = $this->em->getRepository(ScamType::class)->findOneBy(['code' => $typeCode]);
        $account = $this->em->getRepository(MailAccount::class)->findOneBy([]);
        self::assertNotNull($channel);
        self::assertNotNull($type);
        self::assertNotNull($account);

        $conv = $this->conversationHandler->createConversation(
            $channel,
            $type,
            $account,
            ConversationStatus::OPEN,
            50,
            $createdAt,
            $createdAt,
            'backfill-scope-' . bin2hex(random_bytes(4))
        );

        // createConversation stamps created_at itself; force it to the
        // desired age for the window test.
        $this->em->createQueryBuilder()
            ->update(\App\Domain\Communication\Conversation::class, 'c')
            ->set('c.createdAt', ':ts')
            ->where('c.convId = :id')
            ->setParameter('ts', $createdAt)
            ->setParameter('id', $conv->getConvId())
            ->getQuery()->execute();

        if ($deleted) {
            $this->conversationHandler->deleteConversation($conv->getConvId());
        }
        $this->em->clear();

        return $conv->getConvId();
    }

    public function testScopeSelectsOnlyRecentUnknownNonDeleted(): void
    {
        $now = new \DateTimeImmutable();

        $recentUnknown = $this->makeConversation('UNKNOWN', $now->modify('-3 days'));
        $oldUnknown = $this->makeConversation('UNKNOWN', $now->modify('-90 days'));
        $recentPhishing = $this->makeConversation('PHISHING', $now->modify('-3 days'));
        $deletedRecentUnknown = $this->makeConversation('UNKNOWN', $now->modify('-3 days'), deleted: true);

        $ids = $this->handler->findRecentUnknownConversationIds(31);

        $this->assertContains($recentUnknown, $ids, 'recent UNKNOWN must be in scope');
        $this->assertNotContains($oldUnknown, $ids, 'UNKNOWN older than the window must be excluded');
        $this->assertNotContains($recentPhishing, $ids, 'recent non-UNKNOWN must be excluded');
        $this->assertNotContains($deletedRecentUnknown, $ids, 'soft-deleted must be excluded');
    }

    public function testLimitCapsTheScope(): void
    {
        $now = new \DateTimeImmutable();

        for ($i = 0; $i < 3; $i++) {
            $this->makeConversation('UNKNOWN', $now->modify('-1 days'));
        }

        $ids = $this->handler->findRecentUnknownConversationIds(31, 2);
        $this->assertLessThanOrEqual(2, \count($ids));
    }
}
