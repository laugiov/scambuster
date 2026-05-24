<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocEnrichmentService;
use App\Application\Communication\IocExportMapper;
use App\Application\Communication\RiskScoreCalculator;
use App\Application\Communication\RiskScorer;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
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

    private function mockMessageWithScamType(string $scamCode, ConversationStatus $status = ConversationStatus::OPEN): Message
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn($scamCode);

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getScamType')->willReturn($scamType);
        // Spec 091 — calculateMessageRisk now reads conv.status to short-circuit
        // on closed/abandoned/mistake. Existing tests default to OPEN (preserves
        // their assumed path); new closed-conv tests pass non-OPEN explicitly.
        $conversation->method('getStatus')->willReturn($status);

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

    // ─── Spec 086 §US2 — pre-filter shortcut on /risk endpoint ─────────

    /**
     * Helper: mock a Message with the pre-filter marker AND body IOCs that
     * would normally trigger reply (URL + multiple types) — reproduces the
     * 2026-05-19 incident shape inside a unit test.
     */
    private function mockPreFilteredMessageWithBodyIocs(string $kind = 'domain', string $pattern = 'github.com'): Message
    {
        $scamType = $this->createMock(ScamType::class);
        $scamType->method('getCode')->willReturn('UNKNOWN');

        $conversation = $this->createMock(Conversation::class);
        $conversation->method('getScamType')->willReturn($scamType);
        // Spec 091 — production code reads conv.status before pre-filter check.
        $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

        $message = $this->createMock(Message::class);
        $message->method('getConversation')->willReturn($conversation);
        $message->method('getHeaders')->willReturn([
            'from' => 'noreply@github.com',
            'pre_filter' => [
                'kind' => $kind,
                'pattern' => $pattern,
                'matched_at' => '2026-05-19T13:07:00+00:00',
            ],
        ]);

        return $message;
    }

    public function test_calculateMessageRisk_shortcuts_when_pre_filter_marker_present(): void
    {
        // Spec 086 §US2.1 — pre-filter marker on headers must override any
        // IOC-based scoring. Even with body IOCs that would push score_agg
        // medium (URL + diversity), should_reply must be false.
        $message = $this->mockPreFilteredMessageWithBodyIocs('domain', 'github.com');

        // Wire EM such that getMessage succeeds; IOC fetch must NEVER be
        // reached (the shortcut returns before the findBy call). We assert
        // this by configuring the IOC repo to throw if called.
        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn($message);

        $iocRepo = $this->createMock(EntityRepository::class);
        $iocRepo->expects($this->never())->method('findBy');

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($messageRepo, $iocRepo) {
            return $class === Message::class ? $messageRepo : $iocRepo;
        });

        $result = $this->createService()->calculateMessageRisk('msg-prefiltered');

        $this->assertSame(0, $result['score_agg']);
        $this->assertSame('low', $result['level']);
        $this->assertStringContainsString('pre_filtered: domain:github.com', $result['reason']);
        $this->assertFalse($result['should_reply']);
    }

    public function test_calculateMessageRisk_falls_through_when_no_pre_filter_marker(): void
    {
        // Spec 086 §US2.2 — regression guard: messages without the marker
        // (commercial B2B, real scams, all the unfiltered cases) must
        // continue through the existing spec-084 scoring path.
        $message = $this->mockMessageWithScamType('PHISHING');
        $iocs = [$this->mockIoc('email', 0)];

        // Configure findBy to be called (IOC fetch must reach it).
        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn($message);

        $iocRepo = $this->createMock(EntityRepository::class);
        $iocRepo->expects($this->once())->method('findBy')->willReturn($iocs);

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($messageRepo, $iocRepo) {
            return $class === Message::class ? $messageRepo : $iocRepo;
        });

        $this->createService()->calculateMessageRisk('msg-unfiltered');
        // Assertion is the expects($this->once()) above — if shortcut wrongly
        // fired, findBy would not be called and the test would fail.
    }

    public function test_calculateMessageRisk_treats_malformed_pre_filter_as_absent(): void
    {
        // Spec 086 §US2.4 — defensive: a malformed marker (empty array,
        // wrong type, missing keys) must fall through to normal scoring,
        // not crash and not short-circuit.
        foreach ([
            'empty_array' => [],
            'string_not_array' => 'unexpected-string',
            'missing_pattern' => ['kind' => 'domain'],
            'missing_kind' => ['pattern' => 'github.com'],
            'non_string_kind' => ['kind' => 42, 'pattern' => 'github.com'],
        ] as $label => $malformedMarker) {
            $scamType = $this->createMock(ScamType::class);
            $scamType->method('getCode')->willReturn('UNKNOWN');

            $conversation = $this->createMock(Conversation::class);
            $conversation->method('getScamType')->willReturn($scamType);
            // Spec 091 — production code reads conv.status before pre-filter check.
            $conversation->method('getStatus')->willReturn(ConversationStatus::OPEN);

            $message = $this->createMock(Message::class);
            $message->method('getConversation')->willReturn($conversation);
            $message->method('getHeaders')->willReturn(['pre_filter' => $malformedMarker]);

            $messageRepo = $this->createMock(EntityRepository::class);
            $messageRepo->method('find')->willReturn($message);

            $iocRepo = $this->createMock(EntityRepository::class);
            // Must reach IOC fetch (fall-through) — if it doesn't, the malformed
            // marker triggered the shortcut wrongly.
            $iocRepo->expects($this->once())->method('findBy')->willReturn([]);

            $em = $this->createMock(EntityManagerInterface::class);
            $em->method('getRepository')->willReturnCallback(function (string $class) use ($messageRepo, $iocRepo) {
                return $class === Message::class ? $messageRepo : $iocRepo;
            });

            $service = new IocEnrichmentService(
                em: $em,
                riskScorer: $this->riskScorer,
                exportMapper: new IocExportMapper(),
                riskScoreCalculator: $this->riskScoreCalculator,
            );
            $service->calculateMessageRisk('msg-' . $label);
        }
    }

    // ─── Spec 091 — closed-conv short-circuit on /risk endpoint ──────────

    public function test_calculateMessageRisk_shortcuts_when_conversation_is_closed(): void
    {
        // Spec 091 §US1 — when the operator has manually closed the conv,
        // ReplyHandler refuses to generate a reply (defense-in-depth at
        // ReplyHandler.php:104). /risk must surface should_reply=false
        // before n8n's Decision Gate triggers WF-REPLY-GENERATE-V2 in
        // vain. Even IOCs that would normally push should_reply=true
        // (IBAN on INVOICE_FRAUD, intrinsic score >= high) must be
        // bypassed.
        $message = $this->mockMessageWithScamType('INVOICE_FRAUD', ConversationStatus::CLOSED);

        // IOC fetch must NEVER be reached: the short-circuit returns
        // before findBy, mirroring the spec 086 pre-filter pattern.
        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn($message);

        $iocRepo = $this->createMock(EntityRepository::class);
        $iocRepo->expects($this->never())->method('findBy');

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($messageRepo, $iocRepo) {
            return $class === Message::class ? $messageRepo : $iocRepo;
        });

        $result = $this->createService()->calculateMessageRisk('msg-closed-conv');

        $this->assertSame(0, $result['score_agg']);
        $this->assertSame('low', $result['level']);
        $this->assertStringContainsString('conversation_closed: closed', $result['reason']);
        $this->assertFalse($result['should_reply'], 'closed conv must yield should_reply=false');
    }

    public function test_calculateMessageRisk_shortcuts_when_conversation_is_abandoned(): void
    {
        // Spec 091 §US1.2 — same short-circuit applies to abandoned and
        // mistake (any non-open status). The reason embeds the actual
        // status so the operator can distinguish them in logs.
        $message = $this->mockMessageWithScamType('PHISHING', ConversationStatus::ABANDONED);

        $messageRepo = $this->createMock(EntityRepository::class);
        $messageRepo->method('find')->willReturn($message);

        $iocRepo = $this->createMock(EntityRepository::class);
        $iocRepo->expects($this->never())->method('findBy');

        $this->em->method('getRepository')->willReturnCallback(function (string $class) use ($messageRepo, $iocRepo) {
            return $class === Message::class ? $messageRepo : $iocRepo;
        });

        $result = $this->createService()->calculateMessageRisk('msg-abandoned-conv');

        $this->assertFalse($result['should_reply']);
        $this->assertStringContainsString('conversation_closed: abandoned', $result['reason']);
    }
}
