<?php

declare(strict_types=1);

namespace App\Application\Communication;

/**
 * Calculates risk scores for IOCs and messages based on enrichment data
 *
 * Implements scoring rules from specs/05-normaliser-decider.md §4
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
     * Decide if bot should reply based on risk level and extracted IOCs
     *
     * Decision rules (specs/05-normaliser-decider.md §4.3):
     * - high risk → Always reply
     * - medium risk → Reply only if exploitable artifacts present (IBAN, auth URL, phone)
     * - low risk → Never reply (TI storage only)
     *
     * @param int                        $scoreAgg    Aggregate risk score
     * @param string                     $level       Risk level (high/medium/low)
     * @param array<array{type: string}> $messageIocs All IOCs extracted from the message
     *
     * @return bool True if bot should generate a reply
     */
    public function shouldReply(int $scoreAgg, string $level, array $messageIocs): bool
    {
        // High risk → always reply
        if ($level === 'high') {
            return true;
        }

        // Low risk → never reply (TI only)
        if ($level === 'low') {
            return false;
        }

        // Medium risk → reply only if exploitable artifacts present
        $exploitableTypes = ['iban', 'phone', 'url']; // URLs can be auth pages

        foreach ($messageIocs as $ioc) {
            // Defensive: check even if PHPDoc guarantees 'type' exists
            $type = $ioc['type'];

            if (in_array($type, $exploitableTypes, true)) {
                return true;
            }
        }

        return false;
    }
}
