<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Domain\Communication\Message;
use App\Domain\Communication\PromptInjectionAnalysis;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates two-layer prompt injection detection on inbound messages.
 *
 * Layer 1 (PatternMatcher): fast deterministic regex scan (<1ms, free)
 * Layer 2 (LlmAnalyzer): semantic LLM-as-judge analysis (async, paid)
 *
 * Mirrors the PolicyGuard + LLM Validator pattern used for outbound content safety.
 * Detection is forensic -- it does not block ingestion or modify the reply pipeline.
 */
final class PromptInjectionDetector
{
    public function __construct(
        private readonly PromptInjectionPatternMatcher $patternMatcher,
        private readonly PromptInjectionLlmAnalyzer $llmAnalyzer,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Run both detection layers on a message and return a structured analysis.
     *
     * Non-blocking: if Layer 2 (LLM) fails, returns Layer 1 results only.
     */
    public function analyze(Message $message): ?PromptInjectionAnalysis
    {
        if (!$this->enabled) {
            $this->logger->debug('[PromptInjectionDetector] Detection disabled, skipping');

            return null;
        }

        $msgId = $message->getMsgId();
        $bodyText = $message->getBodyText();
        $subject = $message->getSubject() ?? '';

        $this->logger->debug('[PromptInjectionDetector] Starting analysis', [
            'msg_id' => $msgId,
            'body_length' => mb_strlen($bodyText),
        ]);

        // Layer 1: Pattern matching (always runs)
        $textToScan = $subject . "\n" . $bodyText;
        $patternResult = $this->patternMatcher->scan($textToScan);
        $patternMatches = $patternResult['matches'];
        $patternScore = $patternResult['score'];

        // Layer 2: LLM analysis
        $llmResult = null;
        try {
            $senderFrom = $message->getHeaders()['from'] ?? 'unknown';
            $llmResult = $this->llmAnalyzer->analyze($subject, $bodyText, $senderFrom);
        } catch (\Exception $e) {
            $this->logger->warning('[PromptInjectionDetector] Layer 2 (LLM) failed, using Layer 1 only', [
                'msg_id' => $msgId,
                'error' => $e->getMessage(),
            ]);
        }

        // Combine results from both layers
        return $this->buildAnalysis($patternMatches, $patternScore, $llmResult);
    }

    /**
     * Run only Layer 1 (pattern matching) -- for batch mode or when LLM budget is exhausted.
     */
    public function analyzePatternOnly(Message $message): PromptInjectionAnalysis
    {
        $textToScan = ($message->getSubject() ?? '') . "\n" . $message->getBodyText();
        $patternResult = $this->patternMatcher->scan($textToScan);

        return $this->buildAnalysis($patternResult['matches'], $patternResult['score'], null);
    }

    /**
     * @param array<string>                                                                                             $patternMatches
     * @param array{risk_score: float, detected_techniques: array, confidence: float, summary: string}|null $llmResult
     */
    private function buildAnalysis(array $patternMatches, float $patternScore, ?array $llmResult): PromptInjectionAnalysis
    {
        if ($llmResult !== null) {
            // Combine both layers: take the higher risk score
            $riskScore = max($patternScore, $llmResult['risk_score']);
            $detectedTechniques = $llmResult['detected_techniques'];
            $confidence = $llmResult['confidence'];
            $summary = $llmResult['summary'];
            $modelVersion = 'pattern_matcher+llm';
        } else {
            // Layer 1 only
            $riskScore = $patternScore;
            $detectedTechniques = array_map(
                fn (string $match) => [
                    'technique' => explode(':', $match)[0],
                    'evidence' => $match,
                    'severity' => $patternScore >= 0.7 ? 'high' : ($patternScore >= 0.4 ? 'medium' : 'low'),
                ],
                $patternMatches
            );
            $confidence = count($patternMatches) > 0 ? 0.7 : 0.5;
            $summary = count($patternMatches) > 0
                ? sprintf('Pattern-based detection: %d known injection pattern(s) found.', count($patternMatches))
                : 'Pattern-based scan only (LLM analysis unavailable). No known patterns detected.';
            $modelVersion = 'pattern_matcher_only';
        }

        // Enrich: if pattern matches found additional techniques not in LLM result, merge them
        if ($llmResult !== null && count($patternMatches) > 0) {
            $summary .= sprintf(' Layer 1 also detected %d pattern match(es): %s.', count($patternMatches), implode(', ', $patternMatches));
        }

        return new PromptInjectionAnalysis(
            riskScore: $riskScore,
            detectedTechniques: $detectedTechniques,
            confidence: $confidence,
            summary: $summary,
            patternMatches: $patternMatches,
            modelVersion: $modelVersion,
            analyzedAt: new \DateTimeImmutable(),
        );
    }
}
