<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\IocCategory;

/**
 * Spec 097 / Slice 2 — Pure deterministic aggregator that derives the
 * Theater's `human_factor` block from already-serialized messages and IOCs.
 *
 * Separation of concerns enforced by the spec (§Construct validity):
 * - `deterministic` sub-block: pure SQL/timestamp math, no LLM.
 *   - language_switch is computed here from `message.lang_detect`
 *     deltas (NOT from LLM, per spec correction §b).
 *   - cascade_events: groups of ≥ 2 NEW IOCs (after dedup) revealed in
 *     the same inbound message — per spec §Behavior rule #7.
 *   - persona_pressure_profile.
 *   - first_financial_*.
 *   - scammer_response_times_hours.
 * - `exploratory_llm_signals` sub-block: aggregates over LLM-classified
 *   fields, surfaced with their confidence so the viewer can judge.
 *
 * Pure function: no I/O, no Doctrine, no Connection. Easy to unit-test.
 */
final readonly class TheaterHumanFactorCalculator
{
    /**
     * @param list<array<string, mixed>> $messages  serialized by TheaterAssemblyService::serializeMessages
     * @param list<array<string, mixed>> $iocsByMsg serialized + dedup IOCs (already enriched with revelation_context)
     *
     * @return array{
     *   deterministic: array<string, mixed>,
     *   exploratory_llm_signals: array<string, mixed>
     * }
     */
    public function compute(array $messages, array $iocsByMsg, ?string $personaCode, float $enrichmentCoveragePct): array
    {
        $inbound = array_values(array_filter($messages, static fn (array $m): bool => 'in' === $m['direction']));
        $outbound = array_values(array_filter($messages, static fn (array $m): bool => 'out' === $m['direction']));

        return [
            'deterministic' => [
                'total_turns' => \count($messages),
                'inbound_count' => \count($inbound),
                'outbound_count' => \count($outbound),
                'engagement_hours' => $this->engagementHours($messages),
                'first_financial_turn' => $this->firstFinancialTurn($iocsByMsg, $messages),
                'first_financial_ratio' => $this->firstFinancialRatio($iocsByMsg, $messages),
                'scammer_response_times_hours' => $this->scammerResponseTimesHours($messages),
                'scammer_response_time_hours_median' => $this->scammerResponseTimeMedian($messages),
                'cascade_events' => $this->cascadeEvents($iocsByMsg, $messages),
                'language_switch_count' => $this->languageSwitchCount($messages),
                'language_switch_turns' => $this->languageSwitchTurns($messages),
                'persona_pressure_profile' => $this->personaPressureProfile($iocsByMsg, $personaCode),
            ],
            'exploratory_llm_signals' => [
                'enrichment_coverage_pct' => $enrichmentCoveragePct,
                'enrichment_confidence_avg' => $this->enrichmentConfidenceAvg($iocsByMsg),
                'enrichment_confidence_median' => $this->enrichmentConfidenceMedian($iocsByMsg),
                'active_stimuli_count' => $this->activeStimuliCount($iocsByMsg),
                'iocs_under_active_stimulus' => $this->iocsUnderActiveStimulus($iocsByMsg),
                'avg_urgency_at_reveal' => $this->avgUrgencyAtReveal($iocsByMsg),
                'hesitation_count' => $this->hesitationCount($iocsByMsg),
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function engagementHours(array $messages): float
    {
        if ([] === $messages) {
            return 0.0;
        }

        $firstTs = $messages[0]['ts_msg'] ?? null;
        $lastTs = $messages[\count($messages) - 1]['ts_msg'] ?? null;

        if (!\is_string($firstTs) || !\is_string($lastTs)) {
            return 0.0;
        }

        $first = strtotime($firstTs);
        $last = strtotime($lastTs);

        if (false === $first || false === $last) {
            return 0.0;
        }

        return round(max(0, $last - $first) / 3600.0, 2);
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     * @param list<array<string, mixed>> $messages
     */
    private function firstFinancialTurn(array $iocsByMsg, array $messages): ?int
    {
        $msgIdxById = $this->buildMsgIdxIndex($messages);

        $firstTurn = null;

        foreach ($iocsByMsg as $ioc) {
            if (IocCategory::FINANCIAL !== ($ioc['category'] ?? null)) {
                continue;
            }

            $msgId = $this->asString($ioc['msg_id'] ?? null);
            $turn = null === $msgId ? null : ($msgIdxById[$msgId] ?? null);

            if (null !== $turn && (null === $firstTurn || $turn < $firstTurn)) {
                $firstTurn = $turn;
            }
        }

        return $firstTurn;
    }

    /**
     * @param list<array<string, mixed>> $messages
     *
     * @return array<string, int>
     */
    private function buildMsgIdxIndex(array $messages): array
    {
        $out = [];

        foreach ($messages as $msg) {
            $msgId = $this->asString($msg['msg_id'] ?? null);
            $idx = $this->asInt($msg['idx'] ?? null);

            if (null !== $msgId && null !== $idx) {
                $out[$msgId] = $idx;
            }
        }

        return $out;
    }

    private function asString(mixed $v): ?string
    {
        return \is_string($v) && '' !== $v ? $v : null;
    }

    private function asInt(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     * @param list<array<string, mixed>> $messages
     */
    private function firstFinancialRatio(array $iocsByMsg, array $messages): ?float
    {
        $turn = $this->firstFinancialTurn($iocsByMsg, $messages);
        $total = \count($messages);

        if (null === $turn || 0 === $total) {
            return null;
        }

        return round($turn / $total, 3);
    }

    /**
     * Time (in hours) between each persona OUTBOUND and the next scammer
     * INBOUND that follows. Empty list when the conv has no out→in pairs.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<float>
     */
    private function scammerResponseTimesHours(array $messages): array
    {
        $deltas = [];
        $lastOutTs = null;

        foreach ($messages as $msg) {
            $tsStr = $this->asString($msg['ts_msg'] ?? null);

            if (null === $tsStr) {
                continue;
            }
            $ts = strtotime($tsStr);

            if (false === $ts) {
                continue;
            }

            if ('out' === ($msg['direction'] ?? null)) {
                $lastOutTs = $ts;

                continue;
            }

            // direction === 'in'
            if (null !== $lastOutTs && $ts >= $lastOutTs) {
                $deltas[] = round(($ts - $lastOutTs) / 3600.0, 2);
                $lastOutTs = null; // pair consumed
            }
        }

        return $deltas;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function scammerResponseTimeMedian(array $messages): ?float
    {
        $deltas = $this->scammerResponseTimesHours($messages);

        if ([] === $deltas) {
            return null;
        }

        sort($deltas);
        $n = \count($deltas);
        $mid = (int) ($n / 2);

        if (0 === $n % 2) {
            return round(($deltas[$mid - 1] + $deltas[$mid]) / 2.0, 2);
        }

        return $deltas[$mid];
    }

    /**
     * Cascade events: an inbound message that yields ≥ 2 NEW (deduplicated)
     * IOCs in a single turn. Operates on the already-deduped `iocsByMsg`
     * per spec §Behavior rule #7.
     *
     * @param list<array<string, mixed>> $iocsByMsg
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array{trigger_msg_id: string, turn: int, yielded_types: list<string>}>
     */
    private function cascadeEvents(array $iocsByMsg, array $messages): array
    {
        $msgIdxById = $this->buildMsgIdxIndex($messages);

        $iocsGroupedByMsg = [];

        foreach ($iocsByMsg as $ioc) {
            $msgId = $this->asString($ioc['msg_id'] ?? null);
            $type = $this->asString($ioc['type'] ?? null);

            if (null === $msgId || null === $type) {
                continue;
            }
            $iocsGroupedByMsg[$msgId] ??= [];
            $iocsGroupedByMsg[$msgId][] = $type;
        }

        $events = [];

        foreach ($iocsGroupedByMsg as $msgId => $types) {
            if (\count($types) < 2) {
                continue;
            }

            $events[] = [
                'trigger_msg_id' => $msgId,
                'turn' => $msgIdxById[$msgId] ?? 0,
                'yielded_types' => array_values(array_unique($types)),
            ];
        }

        usort($events, static fn (array $a, array $b): int => $a['turn'] <=> $b['turn']);

        return $events;
    }

    /**
     * Spec §correction (b): language_switch is DETERMINISTIC, computed from
     * `lang_detect` deltas between consecutive messages. Header artifacts
     * with empty lang are skipped (no spurious switch).
     *
     * @param list<array<string, mixed>> $messages
     */
    private function languageSwitchCount(array $messages): int
    {
        return \count($this->languageSwitchTurns($messages));
    }

    /**
     * Detects per-message language change between consecutive messages.
     * Skips entries with empty/unknown lang_detect to avoid spurious
     * switches when the upstream language detector failed.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<int>
     */
    private function languageSwitchTurns(array $messages): array
    {
        $switches = [];
        $prevLang = null;

        foreach ($messages as $msg) {
            $raw = $msg['lang_detect'] ?? null;
            $lang = \is_string($raw) ? trim($raw) : '';

            if ('' === $lang) {
                continue;
            }

            if (null !== $prevLang && $prevLang !== $lang) {
                $idx = $this->asInt($msg['idx'] ?? null);

                if (null !== $idx) {
                    $switches[] = $idx;
                }
            }

            $prevLang = $lang;
        }

        return $switches;
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     *
     * @return array{persona_code: ?string, iocs_obtained: int, financial_obtained: int}
     */
    private function personaPressureProfile(array $iocsByMsg, ?string $personaCode): array
    {
        $financial = 0;

        foreach ($iocsByMsg as $ioc) {
            if (IocCategory::FINANCIAL === $ioc['category']) {
                $financial++;
            }
        }

        return [
            'persona_code' => $personaCode,
            'iocs_obtained' => \count($iocsByMsg),
            'financial_obtained' => $financial,
        ];
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function enrichmentConfidenceAvg(array $iocsByMsg): ?float
    {
        $values = $this->collectEnrichedFloat($iocsByMsg, 'enrichment_confidence');

        if ([] === $values) {
            return null;
        }

        return round(array_sum($values) / \count($values), 2);
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function enrichmentConfidenceMedian(array $iocsByMsg): ?float
    {
        $values = $this->collectEnrichedFloat($iocsByMsg, 'enrichment_confidence');

        if ([] === $values) {
            return null;
        }

        sort($values);
        $n = \count($values);
        $mid = (int) ($n / 2);

        return 0 === $n % 2 ? round(($values[$mid - 1] + $values[$mid]) / 2.0, 2) : $values[$mid];
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function activeStimuliCount(array $iocsByMsg): int
    {
        $msgIds = [];

        foreach ($iocsByMsg as $ioc) {
            $ctx = $ioc['revelation_context'] ?? null;

            if (!\is_array($ctx) || 'active' !== ($ctx['stimulus_type'] ?? null)) {
                continue;
            }

            $stim = $ctx['stimulus_msg_id'] ?? null;

            if (\is_string($stim) && '' !== $stim) {
                $msgIds[$stim] = true;
            }
        }

        return \count($msgIds);
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function iocsUnderActiveStimulus(array $iocsByMsg): int
    {
        $count = 0;

        foreach ($iocsByMsg as $ioc) {
            $ctx = $ioc['revelation_context'] ?? null;

            if (\is_array($ctx) && 'active' === ($ctx['stimulus_type'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function avgUrgencyAtReveal(array $iocsByMsg): ?float
    {
        $values = $this->collectEnrichedFloat($iocsByMsg, 'urgency_score');

        if ([] === $values) {
            return null;
        }

        return round(array_sum($values) / \count($values), 2);
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     */
    private function hesitationCount(array $iocsByMsg): int
    {
        $count = 0;

        foreach ($iocsByMsg as $ioc) {
            $ctx = $ioc['revelation_context'] ?? null;

            if (\is_array($ctx) && true === ($ctx['hesitation_detected'] ?? null)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $iocsByMsg
     *
     * @return list<float>
     */
    private function collectEnrichedFloat(array $iocsByMsg, string $field): array
    {
        $values = [];

        foreach ($iocsByMsg as $ioc) {
            $ctx = $ioc['revelation_context'] ?? null;

            if (\is_array($ctx) && 'enriched' === ($ctx['enrichment_status'] ?? null)) {
                $v = $ctx[$field] ?? null;

                if (is_numeric($v)) {
                    $values[] = (float) $v;
                }
            }
        }

        return $values;
    }
}
