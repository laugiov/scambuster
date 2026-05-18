<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocEnrichmentService;
use App\Application\Communication\IocExportMapper;
use App\Application\Communication\RiskScoreCalculator;
use App\Application\Communication\RiskScorer;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\Message;
use App\Domain\Communication\ObservedIoc;
use App\Domain\Communication\ScamType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Spec 084 — unit tests for IocEnrichmentService::calculateMessageRisk.
 *
 * Pre-T02, the method computed score_agg from external (VT/URLscan)
 * enrichment scores only. These tests pin the new behaviour: the
 * returned score_agg must be MAX(external_score, intrinsic_score),
 * where intrinsic_score comes from RiskScoreCalculator (presence of
 * IBAN, BIC, phone, scam_type baseline, etc.).
 */
final class IocEnrichmentServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RiskScorer $riskScorer;
    private RiskScoreCalculator $riskScoreCalculator;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        // Use real RiskScorer + RiskScoreCalculator — they have no deps,
        // are deterministic, and we want the integration tested.
        $this->riskScorer = new RiskScorer();
        $this->riskScoreCalculator = new RiskScoreCalculator();
    }

    private function createService(): IocEnrichmentService
    {
        // IocExportMapper is final + parameterless → instantiate directly
        // (PHPUnit cannot double final classes).
        return new IocEnrichmentService(
            em: $this->em,
            riskScorer: $this->riskScorer,
            exportMapper: new IocExportMapper(),
            riskScoreCalculator: $this->riskScoreCalculator,
        );
    }

    /**
     * Mock the EM so getRepository(Message)->find($msgId) returns the
     * given Message mock, and findBy(ObservedIoc, ['message' => $message])
     * returns the given IOC list.
     */
    private function wireEm(Message $message, array $iocs): void
    {
        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn($message);

        $iocRepo = $this->createMock(EntityRepository::class);
        $iocRepo->method('findBy')->willReturn($iocs);

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($messageRepo, $iocRepo) {
            if ($class === Message::class) {
                return $messageRepo;
            }

            if ($class === ObservedIoc::class) {
                return $iocRepo;
            }

            return $this->createMock(EntityRepository::class);
        });
    }

    private function mockMessageWithScamType(string $scamCode): Message
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn($scamCode);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getScamType')->willReturn($scamType);

        $message = $this->createMock(Message::class);
        $message->method('getConversation')->willReturn($conversation);

        return $message;
    }

    /**
     * IOC mock helper: returns ObservedIoc whose getContext() yields
     * the given type + an external aggregate score.
     */
    private function mockIoc(string $type, int $externalAgg = 0, string $explain = 'No threats detected'): ObservedIoc
    {
        $ioc = $this->createMock(ObservedIoc::class);
        $ioc->method('getContext')->willReturn([
            'type' => $type,
            'score' => ['vt' => 0, 'urlscan' => 0, 'agg' => $externalAgg, 'explain' => $explain],
        ]);

        return $ioc;
    }

    // ─── US1 — intrinsic scoring pushes should_reply=true ──────────────

    public function test_calculateMessageRisk_returns_high_when_iban_present_even_without_external_enrichment(): void
    {
        // Mail with an IBAN, no external enrichment (VT/URLscan = 0).
        // Intrinsic: BASE_SCORES[INVOICE_FRAUD]=60 + 30 (IBAN) + 3 (1 type) = 93
        // → level=high → should_reply=true.
        $message = $this->mockMessageWithScamType('INVOICE_FRAUD');
        $iocs = [$this->mockIoc('iban', 0)];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-iban');

        $this->assertGreaterThanOrEqual(70, $result['score_agg'], 'Intrinsic IBAN bonus must push score >= 70');
        $this->assertSame('high', $result['level']);
        $this->assertTrue($result['should_reply']);
    }

    public function test_calculateMessageRisk_returns_reply_for_tech_support_with_phone(): void
    {
        // Mail with a phone IOC, scam_type=TECH_SUPPORT.
        // Intrinsic: 35 (TECH_SUPPORT) + 15 (phone) + 3 (1 type) = 53 → medium.
        // shouldReply(medium, [phone]) → true (phone is exploitable).
        $message = $this->mockMessageWithScamType('TECH_SUPPORT');
        $iocs = [$this->mockIoc('phone', 0)];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-phone');

        $this->assertTrue($result['should_reply'], 'Phone + TECH_SUPPORT must yield should_reply=true');
    }

    public function test_calculateMessageRisk_preserves_external_score_when_higher_than_intrinsic(): void
    {
        // External VT score 75 on a URL; intrinsic for UNKNOWN+URL is ~38.
        // max(75, 38) = 75 → high → should_reply=true.
        $message = $this->mockMessageWithScamType('UNKNOWN');
        $iocs = [$this->mockIoc('url', 75, 'VT flagged')];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-url-external');

        $this->assertSame(75, $result['score_agg']);
        $this->assertSame('high', $result['level']);
        $this->assertTrue($result['should_reply']);
    }

    public function test_calculateMessageRisk_takes_max_when_both_external_and_intrinsic_significant(): void
    {
        // External=50 (one URL flagged), intrinsic for UNKNOWN + IBAN
        // = 30+30+5+6 = 71 (URL bonus 5, 2 types × 3 = 6, capped 15).
        // max(50, 71) = 71. The worse (higher) of the two wins.
        $message = $this->mockMessageWithScamType('UNKNOWN');
        $iocs = [$this->mockIoc('url', 50, 'VT suspicious'), $this->mockIoc('iban', 0)];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-mixed');

        $this->assertGreaterThanOrEqual(70, $result['score_agg']);
        $this->assertSame('high', $result['level']);
    }

    // ─── US2 — regression guards ───────────────────────────────────────

    public function test_calculateMessageRisk_returns_low_when_no_exploitable_iocs_present(): void
    {
        // Mail with only header IOCs (email, message_id, subject), no
        // body content of value. scam_type=UNKNOWN (default).
        // Intrinsic: 30 (UNKNOWN) + 0 (no financial) + 0 (no phone)
        //          + 0 (no URL) + 9 (3 types × 3) = 39 → low.
        // shouldReply(low, ...) → false. No regression on noise filter.
        $message = $this->mockMessageWithScamType('UNKNOWN');
        $iocs = [
            $this->mockIoc('email', 0),
            $this->mockIoc('message_id', 0),
            $this->mockIoc('subject', 0),
        ];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-header-only');

        $this->assertLessThan(40, $result['score_agg'], 'No exploitable IOC must keep score in low range');
        $this->assertSame('low', $result['level']);
        $this->assertFalse($result['should_reply']);
    }

    // ─── US3 — reason field explains intrinsic trigger ────────────────

    public function test_calculateMessageRisk_reason_mentions_intrinsic_trigger_when_it_wins(): void
    {
        // IBAN present, external=0, intrinsic wins. reason must contain
        // a substring identifying the intrinsic trigger so a debugger
        // reading the JSON payload understands WHY the bot will reply.
        $message = $this->mockMessageWithScamType('INVOICE_FRAUD');
        $iocs = [$this->mockIoc('iban', 0)];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-iban-reason');

        $this->assertStringContainsString('intrinsic', $result['reason']);
        $this->assertStringContainsString('iban', $result['reason']);
    }

    public function test_calculateMessageRisk_reason_keeps_external_format_when_external_wins(): void
    {
        // External VT score 75 dominates. reason must keep its existing
        // <type>: <vt explain> format with NO 'intrinsic' marker
        // (regression guard for downstream consumers parsing the field).
        $message = $this->mockMessageWithScamType('UNKNOWN');
        $iocs = [$this->mockIoc('url', 75, 'VT flagged as malicious')];

        $this->wireEm($message, $iocs);

        $result = $this->createService()->calculateMessageRisk('msg-external-wins');

        $this->assertStringContainsString('VT flagged as malicious', $result['reason']);
        $this->assertStringNotContainsString('intrinsic', $result['reason']);
    }

    public function test_calculateMessageRisk_returns_no_iocs_when_message_has_no_iocs(): void
    {
        // Strict regression guard: the existing early-return for
        // zero-IOC messages must keep yielding the canonical
        // {score_agg:0, level:low, reason:"No IOCs detected", should_reply:false}.
        // This is the path that legitimate-sender filtering (spec 083)
        // will rely on.
        $message = $this->mockMessageWithScamType('UNKNOWN');

        $this->wireEm($message, []);

        $result = $this->createService()->calculateMessageRisk('msg-empty');

        $this->assertSame(0, $result['score_agg']);
        $this->assertSame('low', $result['level']);
        $this->assertSame('No IOCs detected', $result['reason']);
        $this->assertFalse($result['should_reply']);
    }
}
