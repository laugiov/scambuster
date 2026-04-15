<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IngestPostProcessor;
use App\Application\Communication\IocHandler;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for F2: Dynamic risk score rebalancing.
 *
 * Verifies that financial IOC bonuses are increased and IOC diversity
 * is properly rewarded in risk score computation.
 */
final class RiskScoreRebalanceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private IocHandler&MockObject $iocHandler;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->iocHandler = $this->createMock(IocHandler::class);
    }

    private function createProcessor(): IngestPostProcessor
    {
        return new IngestPostProcessor(
            em: $this->em,
            logger: new NullLogger(),
            iocHandler: $this->iocHandler,
        );
    }

    /**
     * CHARITY (base=25) + IBAN + wallet_btc = 25 + 30 (financial) + 10 (extra financial type) + 6 (2 types * 3) = 71.
     * Must be > 70.
     */
    public function test_charity_with_iban_and_wallet_btc_risk_above_70(): void
    {
        $iocs = [
            $this->createIocMock('iban'),
            $this->createIocMock('wallet_btc'),
        ];

        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($iocs);
        $this->em->method('getRepository')->willReturn($repo);

        $message = $this->createMessageMock('msg-1');
        $conversation = $this->createConversationMock('conv-1', 'CHARITY', scoreRisk: 0);

        $capturedRisk = null;
        $conversation->method('updateRiskScore')->willReturnCallback(function (int $score) use (&$capturedRisk): void {
            $capturedRisk = $score;
        });

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');

        $this->assertNotNull($capturedRisk, 'Risk score should have been updated');
        $this->assertGreaterThan(70, $capturedRisk, 'CHARITY + IBAN + wallet_btc must yield risk > 70');
    }

    /**
     * PHISHING (base=40) + URL only = 40 + 5 (1 url) + 3 (1 type diversity) = 48.
     * Must be <= 65.
     */
    public function test_phishing_with_url_only_risk_at_most_65(): void
    {
        $iocs = [
            $this->createIocMock('url'),
        ];

        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($iocs);
        $this->em->method('getRepository')->willReturn($repo);

        $message = $this->createMessageMock('msg-2');
        $conversation = $this->createConversationMock('conv-2', 'PHISHING', scoreRisk: 0);

        $capturedRisk = null;
        $conversation->method('updateRiskScore')->willReturnCallback(function (int $score) use (&$capturedRisk): void {
            $capturedRisk = $score;
        });

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');

        $this->assertNotNull($capturedRisk, 'Risk score should have been updated');
        $this->assertLessThanOrEqual(65, $capturedRisk, 'PHISHING + URL only must yield risk <= 65');
    }

    /**
     * INVOICE_FRAUD (base=60) + IBAN + BIC + phone = 60 + 30 (financial) + 10 (extra type: bic) + 15 (phone) + 9 (3 types * 3) = 124 -> capped at 100.
     * Must be >= 80.
     */
    public function test_invoice_fraud_with_iban_bic_phone_risk_at_least_80(): void
    {
        $iocs = [
            $this->createIocMock('iban'),
            $this->createIocMock('bic'),
            $this->createIocMock('phone'),
        ];

        $this->iocHandler->method('extractAndUpsertHeaderIocs')->willReturn(0);
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn($iocs);
        $this->em->method('getRepository')->willReturn($repo);

        $message = $this->createMessageMock('msg-3');
        $conversation = $this->createConversationMock('conv-3', 'INVOICE_FRAUD', scoreRisk: 0);

        $capturedRisk = null;
        $conversation->method('updateRiskScore')->willReturnCallback(function (int $score) use (&$capturedRisk): void {
            $capturedRisk = $score;
        });

        $processor = $this->createProcessor();
        $processor->processAfterIngest($message, $conversation, 'en');

        $this->assertNotNull($capturedRisk, 'Risk score should have been updated');
        $this->assertGreaterThanOrEqual(80, $capturedRisk, 'INVOICE_FRAUD + IBAN + BIC + phone must yield risk >= 80');
    }

    // --- Helpers ---

    private function createIocMock(string $type): ObservedIoc&MockObject
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn(['type' => $type]);

        return $ioc;
    }

    private function createMessageMock(string $msgId): Message&MockObject
    {
        $message = $this->createMock(Message::class);
        $message->method('getMsgId')->willReturn($msgId);
        $message->method('getHeaders')->willReturn(['from' => 'scammer@evil.test']);
        $message->method('getBodyText')->willReturn('Test body');
        $message->method('getSubject')->willReturn('Test subject');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn('conv-helper');
        $message->method('getConversation')->willReturn($conversation);

        return $message;
    }

    private function createConversationMock(string $convId, string $scamTypeCode, int $scoreRisk = 30): Conversation&MockObject
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn($scamTypeCode);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getConvId')->willReturn($convId);
        $conversation->method('getScamType')->willReturn($scamType);
        $conversation->method('getScoreRisk')->willReturn($scoreRisk);
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

        return $conversation;
    }
}
