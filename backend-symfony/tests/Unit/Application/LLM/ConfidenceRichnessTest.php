<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ContextualEnrichmentResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for richness-aware confidence capping (spec 075e).
 *
 * Verifies that the confidence cap accounts for context richness:
 * message length, IOC count, and urgency patterns.
 *
 * @covers \App\Application\LLM\ContextualEnrichmentResult
 */
final class ConfidenceRichnessTest extends TestCase
{
    /**
     * 1 message, short text, no IOCs, no urgency -> base cap 0.50.
     */
    public function testShortMessage1AvailableMaxes050(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => 'Hello',
            'ioc_types' => [],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.50,
            $result->enrichmentConfidence,
            0.001,
            '1 message, short, no IOCs: max 0.50',
        );
    }

    /**
     * 1 message, long text (>200 chars), no IOCs -> base 0.50 + 0.10 length = 0.60.
     */
    public function testLongMessage1AvailableMaxes060(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => str_repeat('A', 250),
            'ioc_types' => [],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.60,
            $result->enrichmentConfidence,
            0.001,
            '1 message, long (>200 chars): max 0.60',
        );
    }

    /**
     * 1 message, long text + 4 IOCs -> base 0.50 + 0.10 length + 0.10 IOCs = 0.70.
     */
    public function testLongMessagePlus4Iocs1AvailableMaxes070(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => str_repeat('A', 250),
            'ioc_types' => ['url', 'email', 'iban', 'phone'],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.70,
            $result->enrichmentConfidence,
            0.001,
            '1 message, long + 4 IOCs: max 0.70',
        );
    }

    /**
     * 1 message, long text + 4 IOCs + urgency -> base 0.50 + 0.30 = 0.80.
     */
    public function testLongMessagePlus4IocsUrgency1AvailableMaxes080(): void
    {
        $data = [
            'stimulus_type' => 'URGENCY_PRESSURE',
            'scammer_urgency_score' => 0.9,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => str_repeat('A', 201) . ' Your account expires immediately and legal action will be taken',
            'ioc_types' => ['url', 'email', 'iban', 'phone'],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.80,
            $result->enrichmentConfidence,
            0.001,
            '1 message, long + 4 IOCs + urgency: max 0.80',
        );
    }

    /**
     * 2 messages, short text -> base cap 0.70.
     */
    public function testShortMessage2AvailableMaxes070(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => 'Hello',
            'ioc_types' => [],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 2);
        $this->assertEqualsWithDelta(
            0.70,
            $result->enrichmentConfidence,
            0.001,
            '2 messages, short: max 0.70',
        );
    }

    /**
     * 2 messages, rich context -> base 0.70 + 0.30 = 1.0 (clamped).
     */
    public function testRichContext2AvailableMaxes100(): void
    {
        $data = [
            'stimulus_type' => 'URGENCY_PRESSURE',
            'scammer_urgency_score' => 0.9,
            'enrichment_confidence' => 0.99,
            'stimulus_message' => str_repeat('B', 250) . ' deadline urgent legal action',
            'ioc_types' => ['url', 'email', 'iban', 'phone'],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 2);
        $this->assertEqualsWithDelta(
            0.99,
            $result->enrichmentConfidence,
            0.001,
            '2 messages, rich context: effective cap 1.0, LLM confidence 0.99 passes through',
        );
    }

    /**
     * 3 messages, no richness -> base cap 0.90.
     */
    public function testShortMessage3AvailableMaxes090(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => 'Hello',
            'ioc_types' => [],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 3);
        $this->assertEqualsWithDelta(
            0.90,
            $result->enrichmentConfidence,
            0.001,
            '3 messages, short: max 0.90',
        );
    }

    /**
     * 3 messages, rich context -> base 0.90 + 0.30 = 1.20 clamped to 1.0.
     */
    public function testRichContext3AvailableMaxes100(): void
    {
        $data = [
            'stimulus_type' => 'URGENCY_PRESSURE',
            'scammer_urgency_score' => 0.9,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => str_repeat('C', 250) . ' deadline expires immediately',
            'ioc_types' => ['url', 'email', 'iban', 'phone'],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 3);
        $this->assertEqualsWithDelta(
            0.95,
            $result->enrichmentConfidence,
            0.001,
            '3 messages, rich: cap is 1.0, so LLM confidence 0.95 passes through',
        );
    }

    /**
     * Confidence below cap should pass through unchanged.
     */
    public function testConfidenceBelowCapUnchanged(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.30,
            'stimulus_message' => 'Hello',
            'ioc_types' => [],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.30,
            $result->enrichmentConfidence,
            0.001,
            'Confidence below cap should pass through unchanged',
        );
    }

    /**
     * Specific urgency keywords are detected.
     */
    public function testUrgencyKeywordsDetected(): void
    {
        $keywords = ['deadline', 'expires', 'urgent', 'immediate', 'hours', 'legal action', 'suspend', 'closure'];

        foreach ($keywords as $keyword) {
            $data = [
                'stimulus_type' => 'PASSIVE',
                'scammer_urgency_score' => 0.5,
                'enrichment_confidence' => 0.95,
                'stimulus_message' => "This is a short msg with {$keyword}",
                'ioc_types' => [],
                'ioc_roles' => [],
            ];

            $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
            // Base 0.50 + urgency 0.10 = 0.60 (message is short, no IOCs)
            $this->assertEqualsWithDelta(
                0.60,
                $result->enrichmentConfidence,
                0.001,
                "Urgency keyword '{$keyword}' should add +0.10 bonus",
            );
        }
    }

    /**
     * IOC count exactly 3 should NOT trigger bonus (threshold is > 3).
     */
    public function testExactly3IocsNoBonus(): void
    {
        $data = [
            'stimulus_type' => 'PASSIVE',
            'scammer_urgency_score' => 0.5,
            'enrichment_confidence' => 0.95,
            'stimulus_message' => 'Short',
            'ioc_types' => ['url', 'email', 'iban'],
            'ioc_roles' => [],
        ];

        $result = ContextualEnrichmentResult::fromLlmResponse($data, [], 1);
        $this->assertEqualsWithDelta(
            0.50,
            $result->enrichmentConfidence,
            0.001,
            'Exactly 3 IOC types should NOT trigger the IOC bonus',
        );
    }
}
