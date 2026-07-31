<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Calculates risk scores for IOCs and messages based on enrichment data.
 *
 * Scoring algorithm (v0 - conservative):
 * - VirusTotal malicious > 0 → +70 points
 * - VirusTotal suspicious > 0 → +40 points
 * - URLscan verdict "malicious" → +60 points
 * - URLscan verdict "suspicious" → +25 points
 * - Score capped at 100
 *
 * Risk levels:
 * - high: score >= 70
 * - medium: 40 <= score < 70
 * - low: score < 40
 */
final class RiskScorer
{
    /**
     * Calculate IOC score from enrichment data
     *
     * @param array{urlscan?: array{verdict?: string}, virustotal?: array{malicious?: int, suspicious?: int}} $enrichment
     *
     * @return array{vt: int, urlscan: int, agg: int, explain: string}
     */
    public function calculateIocScore(array $enrichment): array
    {
        $vtScore = 0;
        $urlscanScore = 0;
        $explanations = [];

        // VirusTotal scoring
        $vt = $enrichment['virustotal'] ?? [];
        $vtMalicious = $vt['malicious'] ?? 0;
        $vtSuspicious = $vt['suspicious'] ?? 0;

        if ($vtMalicious > 0) {
            $vtScore = 70;
            $explanations[] = sprintf('VT malicious=%d → +70', $vtMalicious);
        } elseif ($vtSuspicious > 0) {
            $vtScore = 40;
            $explanations[] = sprintf('VT suspicious=%d → +40', $vtSuspicious);
        }

        // URLscan scoring
        $urlscan = $enrichment['urlscan'] ?? [];
        $verdict = $urlscan['verdict'] ?? 'unknown';

        if ($verdict === 'malicious') {
            $urlscanScore = 60;
            $explanations[] = 'URLscan malicious → +60';
        } elseif ($verdict === 'suspicious') {
            $urlscanScore = 25;
            $explanations[] = 'URLscan suspicious → +25';
        }

        // Aggregate score (capped at 100)
        $aggScore = min(100, $vtScore + $urlscanScore);

        return [
            'vt' => $vtScore,
            'urlscan' => $urlscanScore,
            'agg' => $aggScore,
            'explain' => empty($explanations) ? 'No threats detected' : implode(' ; ', $explanations)
        ];
    }

    /**
     * Determine risk level from aggregate score
     *
     * @param int $scoreAgg Aggregate score (0-100)
     *
     * @return 'high'|'medium'|'low'
     */
    public function determineLevel(int $scoreAgg): string
    {
        if ($scoreAgg >= 70) {
            return 'high';
        }

        if ($scoreAgg >= 40) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Decide if bot should reply based on risk level.
     *
     * Decision rules (updated 2026-05-18 — operator lowered the reply
     * threshold to score_agg >= 40):
     *   - high risk   (>= 70)  → reply
     *   - medium risk (40-69)  → reply (was: only if exploitable IOC)
     *   - low risk    (< 40)   → never reply (TI storage only)
     *
     * Rationale for the change: the previous "medium needs exploitable
     * IOC (iban/phone/url)" rule dropped engaged scammers who sent a
     * follow-up courtesy email before their first concrete artefact.
     * Combined with the auto-mail pre-filter (which already
     * blocks DMARC, noreply, postmaster traffic upstream), the risk of
     * replying on a medium without exploitable IOC is now acceptable.
     *
     * The $messageIocs parameter is kept for ABI compatibility but is
     * no longer consulted; existing callers continue to work.
     *
     * @param int                        $scoreAgg    Aggregate risk score
     * @param string                     $level       Risk level (high/medium/low)
     * @param array<array{type: string}> $messageIocs IOCs extracted from the message (unused since 2026-05-18)
     *
     * @return bool True if bot should generate a reply
     */
    public function shouldReply(int $scoreAgg, string $level, array $messageIocs): bool
    {
        return $level !== 'low';
    }
}
