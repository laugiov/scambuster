<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\TheaterHumanFactorCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pure-function unit tests on the human_factor
 * aggregator. No DB, no Doctrine.
 *
 * Critical assertions per spec:
 * - Rule #7: cascades computed on DEDUPED IOC set (repeats don't inflate).
 * - Rule #9: stimulus_msg_id pointing outside conv = null in output.
 * - language_switch DETERMINISTIC from lang_detect deltas.
 */
final class TheaterHumanFactorCalculatorTest extends TestCase
{
    private TheaterHumanFactorCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new TheaterHumanFactorCalculator();
    }

    public function testEngagementHoursFromFirstToLastMessage_097S2(): void
    {
        $messages = $this->mkMessages([
            ['idx' => 1, 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00'],
            ['idx' => 2, 'direction' => 'out', 'ts_msg' => '2026-06-01T16:00:00+00:00'],
            ['idx' => 3, 'direction' => 'in', 'ts_msg' => '2026-06-02T10:00:00+00:00'],
        ]);

        $result = $this->calculator->compute($messages, [], null, 0.0);
        $this->assertSame(24.0, $result['deterministic']['engagement_hours']);
    }

    public function testFirstFinancialTurnPicksEarliestFinancialIoc_097S2(): void
    {
        $messages = $this->mkMessages([
            ['idx' => 1, 'msg_id' => 'm1', 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00'],
            ['idx' => 2, 'msg_id' => 'm2', 'direction' => 'out', 'ts_msg' => '2026-06-01T11:00:00+00:00'],
            ['idx' => 3, 'msg_id' => 'm3', 'direction' => 'in', 'ts_msg' => '2026-06-01T12:00:00+00:00'],
        ]);
        $iocs = [
            ['msg_id' => 'm3', 'category' => 'financial', 'type' => 'iban'],
            ['msg_id' => 'm1', 'category' => 'contact', 'type' => 'phone'],
        ];

        $result = $this->calculator->compute($messages, $iocs, null, 0.0);
        $this->assertSame(3, $result['deterministic']['first_financial_turn']);
        $this->assertSame(round(3 / 3, 3), $result['deterministic']['first_financial_ratio']);
    }

    public function testFirstFinancialTurnNullWhenNoFinancial_097S2(): void
    {
        $messages = $this->mkMessages([
            ['idx' => 1, 'msg_id' => 'm1', 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00'],
        ]);
        $iocs = [['msg_id' => 'm1', 'category' => 'contact', 'type' => 'phone']];

        $result = $this->calculator->compute($messages, $iocs, null, 0.0);
        $this->assertNull($result['deterministic']['first_financial_turn']);
        $this->assertNull($result['deterministic']['first_financial_ratio']);
    }

    public function testScammerResponseTimesHoursOutToInPairs_097S2(): void
    {
        $messages = $this->mkMessages([
            ['idx' => 1, 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00'],
            ['idx' => 2, 'direction' => 'out', 'ts_msg' => '2026-06-01T11:00:00+00:00'],
            ['idx' => 3, 'direction' => 'in', 'ts_msg' => '2026-06-01T15:00:00+00:00'],  // 4h delta
            ['idx' => 4, 'direction' => 'out', 'ts_msg' => '2026-06-01T16:00:00+00:00'],
            ['idx' => 5, 'direction' => 'in', 'ts_msg' => '2026-06-02T16:00:00+00:00'],  // 24h delta
        ]);

        $result = $this->calculator->compute($messages, [], null, 0.0);
        $this->assertSame([4.0, 24.0], $result['deterministic']['scammer_response_times_hours']);
        $this->assertSame(14.0, $result['deterministic']['scammer_response_time_hours_median']);
    }

    public function testCascadeEventsOnDedupedSetRule7_097S2(): void
    {
        // CRITICAL: an IOC repeated in a later msg does NOT inflate cascade size.
        // Dedup must happen BEFORE cascade detection (already done upstream by
        // TheaterAssemblyService; here we test that the calculator processes
        // the dedup output correctly).
        $messages = $this->mkMessages([
            ['idx' => 1, 'msg_id' => 'm1', 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00'],
            ['idx' => 2, 'msg_id' => 'm2', 'direction' => 'in', 'ts_msg' => '2026-06-01T11:00:00+00:00'],
        ]);
        // Dedup already applied: m1 has 2 NEW IOCs, m2 has 1 NEW IOC.
        $iocs = [
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'iban'],
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'bic'],
            ['msg_id' => 'm2', 'category' => 'contact', 'type' => 'phone'],
        ];

        $result = $this->calculator->compute($messages, $iocs, null, 0.0);
        $cascades = $result['deterministic']['cascade_events'];
        $this->assertCount(1, $cascades, 'only m1 has ≥ 2 new IOCs');
        $this->assertSame('m1', $cascades[0]['trigger_msg_id']);
        $this->assertSame(1, $cascades[0]['turn']);
        $this->assertEqualsCanonicalizing(['iban', 'bic'], $cascades[0]['yielded_types']);
    }

    public function testCascadeEventsEmptyWhenNoMessageHasTwoIocs_097S2(): void
    {
        $messages = $this->mkMessages([
            ['idx' => 1, 'msg_id' => 'm1', 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00'],
            ['idx' => 2, 'msg_id' => 'm2', 'direction' => 'in', 'ts_msg' => '2026-06-01T11:00:00+00:00'],
        ]);
        $iocs = [
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'iban'],
            ['msg_id' => 'm2', 'category' => 'contact', 'type' => 'phone'],
        ];

        $result = $this->calculator->compute($messages, $iocs, null, 0.0);
        $this->assertSame([], $result['deterministic']['cascade_events']);
    }

    public function testLanguageSwitchFromLangDetectDeltas_097S2(): void
    {
        // Spec correction §b: language_switch is DETERMINISTIC from lang_detect.
        $messages = $this->mkMessages([
            ['idx' => 1, 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00', 'lang_detect' => 'en'],
            ['idx' => 2, 'direction' => 'out', 'ts_msg' => '2026-06-01T11:00:00+00:00', 'lang_detect' => 'en'],
            ['idx' => 3, 'direction' => 'in', 'ts_msg' => '2026-06-01T12:00:00+00:00', 'lang_detect' => 'fr'],
            ['idx' => 4, 'direction' => 'out', 'ts_msg' => '2026-06-01T13:00:00+00:00', 'lang_detect' => 'fr'],
            ['idx' => 5, 'direction' => 'in', 'ts_msg' => '2026-06-01T14:00:00+00:00', 'lang_detect' => 'en'],
        ]);

        $result = $this->calculator->compute($messages, [], null, 0.0);
        $this->assertSame(2, $result['deterministic']['language_switch_count']);
        $this->assertSame([3, 5], $result['deterministic']['language_switch_turns']);
    }

    public function testLanguageSwitchSkipsEmptyLangDetect_097S2(): void
    {
        // Empty lang_detect must NOT count as a switch (e.g., upstream
        // language detector failed on that message).
        $messages = $this->mkMessages([
            ['idx' => 1, 'direction' => 'in', 'ts_msg' => '2026-06-01T10:00:00+00:00', 'lang_detect' => 'en'],
            ['idx' => 2, 'direction' => 'out', 'ts_msg' => '2026-06-01T11:00:00+00:00', 'lang_detect' => ''],
            ['idx' => 3, 'direction' => 'in', 'ts_msg' => '2026-06-01T12:00:00+00:00', 'lang_detect' => 'en'],
        ]);

        $result = $this->calculator->compute($messages, [], null, 0.0);
        $this->assertSame(0, $result['deterministic']['language_switch_count']);
    }

    public function testPersonaPressureProfileCountsFinancials_097S2(): void
    {
        $iocs = [
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'iban'],
            ['msg_id' => 'm2', 'category' => 'financial', 'type' => 'bic'],
            ['msg_id' => 'm3', 'category' => 'contact', 'type' => 'phone'],
        ];
        $result = $this->calculator->compute([], $iocs, 'small_business_owner', 0.0);
        $profile = $result['deterministic']['persona_pressure_profile'];
        $this->assertSame('small_business_owner', $profile['persona_code']);
        $this->assertSame(3, $profile['iocs_obtained']);
        $this->assertSame(2, $profile['financial_obtained']);
    }

    public function testExploratoryLlmSignalsAggregates_097S2(): void
    {
        $iocs = [
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'iban', 'revelation_context' => [
                'enrichment_status' => 'enriched',
                'enrichment_confidence' => 0.80,
                'stimulus_type' => 'DIRECT_REQUEST',
                'stimulus_msg_id' => 'o1',
                'urgency_score' => 0.30,
                'hesitation_detected' => true,
            ]],
            ['msg_id' => 'm2', 'category' => 'contact', 'type' => 'phone', 'revelation_context' => [
                'enrichment_status' => 'enriched',
                'enrichment_confidence' => 0.40,
                'stimulus_type' => 'PASSIVE',
                'stimulus_msg_id' => null,
                'urgency_score' => 0.10,
                'hesitation_detected' => false,
            ]],
            ['msg_id' => 'm3', 'category' => 'other', 'type' => 'unknown', 'revelation_context' => null],
        ];

        $result = $this->calculator->compute([], $iocs, null, 66.7);
        $exp = $result['exploratory_llm_signals'];

        $this->assertSame(66.7, $exp['enrichment_coverage_pct']);
        $this->assertSame(0.6, $exp['enrichment_confidence_avg']);
        $this->assertSame(0.6, $exp['enrichment_confidence_median']);
        $this->assertSame(1, $exp['active_stimuli_count'], 'one unique stimulus_msg_id');
        $this->assertSame(1, $exp['iocs_under_active_stimulus']);
        $this->assertSame(0.2, $exp['avg_urgency_at_reveal']);
        $this->assertSame(1, $exp['hesitation_count']);
    }

    public function testActiveStimuliCountDeduplicatesByMsgId_097S2(): void
    {
        // Two IOCs revealed by the SAME outbound = 1 stimulus.
        $iocs = [
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'iban', 'revelation_context' => [
                'enrichment_status' => 'enriched',
                'stimulus_type' => 'DIRECT_REQUEST',
                'stimulus_msg_id' => 'same-outbound',
            ]],
            ['msg_id' => 'm1', 'category' => 'financial', 'type' => 'bic', 'revelation_context' => [
                'enrichment_status' => 'enriched',
                'stimulus_type' => 'DIRECT_REQUEST',
                'stimulus_msg_id' => 'same-outbound',
            ]],
        ];

        $result = $this->calculator->compute([], $iocs, null, 0.0);
        $this->assertSame(1, $result['exploratory_llm_signals']['active_stimuli_count']);
        $this->assertSame(2, $result['exploratory_llm_signals']['iocs_under_active_stimulus']);
    }

    public function testEmptyConversationGracefulOutput_097S2(): void
    {
        $result = $this->calculator->compute([], [], null, 0.0);

        $this->assertSame(0, $result['deterministic']['total_turns']);
        $this->assertSame(0.0, $result['deterministic']['engagement_hours']);
        $this->assertNull($result['deterministic']['first_financial_turn']);
        $this->assertSame([], $result['deterministic']['scammer_response_times_hours']);
        $this->assertSame([], $result['deterministic']['cascade_events']);
        $this->assertNull($result['exploratory_llm_signals']['avg_urgency_at_reveal']);
        $this->assertSame(0, $result['exploratory_llm_signals']['hesitation_count']);
    }

    /**
     * @param list<array<string, mixed>> $partials
     *
     * @return list<array<string, mixed>>
     */
    private function mkMessages(array $partials): array
    {
        $defaults = [
            'msg_id' => 'm-default',
            'sender' => 'x@y',
            'subject' => null,
            'body_text' => '',
            'lang_detect' => '',
        ];
        $out = [];

        foreach ($partials as $p) {
            $out[] = array_merge($defaults, $p);
        }

        return $out;
    }
}
