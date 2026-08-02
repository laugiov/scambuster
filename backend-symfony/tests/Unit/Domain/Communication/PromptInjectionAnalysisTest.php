<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Communication;

use App\Domain\Communication\PromptInjectionAnalysis;
use PHPUnit\Framework\TestCase;

class PromptInjectionAnalysisTest extends TestCase
{
    // =========================================================================
    // Construction & getters
    // =========================================================================

    public function testConstructWithValidValues(): void
    {
        $analysis = new PromptInjectionAnalysis(
            riskScore: 0.75,
            detectedTechniques: [
                ['technique' => 'jailbreak', 'evidence' => 'DAN mode', 'severity' => 'high'],
            ],
            confidence: 0.9,
            summary: 'Jailbreak attempt detected.',
            patternMatches: ['jailbreak:dan_jailbreak'],
            modelVersion: 'pattern_matcher+llm',
            analyzedAt: new \DateTimeImmutable('2026-03-11T12:00:00+00:00'),
        );

        $this->assertSame(0.75, $analysis->getRiskScore());
        $this->assertCount(1, $analysis->getDetectedTechniques());
        $this->assertSame('jailbreak', $analysis->getDetectedTechniques()[0]['technique']);
        $this->assertSame(0.9, $analysis->getConfidence());
        $this->assertSame('Jailbreak attempt detected.', $analysis->getSummary());
        $this->assertSame(['jailbreak:dan_jailbreak'], $analysis->getPatternMatches());
        $this->assertSame('pattern_matcher+llm', $analysis->getModelVersion());
        $this->assertInstanceOf(\DateTimeImmutable::class, $analysis->getAnalyzedAt());
    }

    public function testConstructWithEmptyValues(): void
    {
        $analysis = new PromptInjectionAnalysis(0.0, [], 0.0, '', [], '', new \DateTimeImmutable());

        $this->assertSame(0.0, $analysis->getRiskScore());
        $this->assertEmpty($analysis->getDetectedTechniques());
        $this->assertSame(0.0, $analysis->getConfidence());
        $this->assertSame('', $analysis->getSummary());
        $this->assertEmpty($analysis->getPatternMatches());
        $this->assertSame('', $analysis->getModelVersion());
    }

    public function testConstructWithMultipleTechniques(): void
    {
        $techniques = [
            ['technique' => 'direct_injection', 'evidence' => 'ignore instructions', 'severity' => 'high'],
            ['technique' => 'jailbreak', 'evidence' => 'DAN mode', 'severity' => 'high'],
            ['technique' => 'prompt_extraction', 'evidence' => 'show system prompt', 'severity' => 'medium'],
        ];

        $analysis = new PromptInjectionAnalysis(0.95, $techniques, 0.88, 'Multiple techniques.', [], 'gpt-4o-mini', new \DateTimeImmutable());

        $this->assertCount(3, $analysis->getDetectedTechniques());
        $this->assertSame('direct_injection', $analysis->getDetectedTechniques()[0]['technique']);
        $this->assertSame('jailbreak', $analysis->getDetectedTechniques()[1]['technique']);
        $this->assertSame('prompt_extraction', $analysis->getDetectedTechniques()[2]['technique']);
    }

    public function testConstructWithMultiplePatternMatches(): void
    {
        $matches = ['instruction_override:ignore_previous', 'jailbreak:dan_jailbreak', 'prompt_extraction:system_prompt'];
        $analysis = new PromptInjectionAnalysis(0.8, [], 0.7, 'Test', $matches, 'pattern_matcher_only', new \DateTimeImmutable());

        $this->assertCount(3, $analysis->getPatternMatches());
        $this->assertSame($matches, $analysis->getPatternMatches());
    }

    // =========================================================================
    // isHighRisk threshold
    // =========================================================================

    /**
     * @dataProvider highRiskThresholdProvider
     */
    public function testIsHighRiskThreshold(float $score, bool $expectedHighRisk): void
    {
        $analysis = new PromptInjectionAnalysis($score, [], 0.5, '', [], '', new \DateTimeImmutable());
        $this->assertSame($expectedHighRisk, $analysis->isHighRisk());
    }

    public static function highRiskThresholdProvider(): array
    {
        return [
            'exact 0.7 threshold' => [0.7, true],
            'above threshold' => [0.71, true],
            'max score' => [1.0, true],
            'slightly below threshold' => [0.69, false],
            'medium risk' => [0.5, false],
            'low risk' => [0.1, false],
            'zero risk' => [0.0, false],
        ];
    }

    // =========================================================================
    // Validation: invalid scores
    // =========================================================================

    /**
     * @dataProvider invalidRiskScoreProvider
     */
    public function testRejectsInvalidRiskScore(float $score): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Risk score must be in [0.0, 1.0]');

        new PromptInjectionAnalysis($score, [], 0.5, '', [], '', new \DateTimeImmutable());
    }

    public static function invalidRiskScoreProvider(): array
    {
        return [
            'above 1.0' => [1.5],
            'far above 1.0' => [10.0],
            'negative' => [-0.1],
            'large negative' => [-100.0],
            'slightly above 1.0' => [1.001],
        ];
    }

    /**
     * @dataProvider invalidConfidenceProvider
     */
    public function testRejectsInvalidConfidence(float $confidence): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Confidence must be in [0.0, 1.0]');

        new PromptInjectionAnalysis(0.5, [], $confidence, '', [], '', new \DateTimeImmutable());
    }

    public static function invalidConfidenceProvider(): array
    {
        return [
            'above 1.0' => [1.1],
            'negative' => [-0.3],
            'large negative' => [-1.0],
        ];
    }

    // =========================================================================
    // Boundary values
    // =========================================================================

    public function testBoundaryValues(): void
    {
        $min = new PromptInjectionAnalysis(0.0, [], 0.0, '', [], '', new \DateTimeImmutable());
        $this->assertSame(0.0, $min->getRiskScore());
        $this->assertSame(0.0, $min->getConfidence());

        $max = new PromptInjectionAnalysis(1.0, [], 1.0, '', [], '', new \DateTimeImmutable());
        $this->assertSame(1.0, $max->getRiskScore());
        $this->assertSame(1.0, $max->getConfidence());
    }

    // =========================================================================
    // toArray serialization
    // =========================================================================

    public function testToArrayProducesExpectedStructure(): void
    {
        $now = new \DateTimeImmutable('2026-03-11T14:30:00+00:00');
        $techniques = [
            ['technique' => 'direct_injection', 'evidence' => 'ignore rules', 'severity' => 'high'],
        ];

        $analysis = new PromptInjectionAnalysis(
            riskScore: 0.85,
            detectedTechniques: $techniques,
            confidence: 0.92,
            summary: 'Direct injection.',
            patternMatches: ['instruction_override:ignore_previous'],
            modelVersion: 'pattern_matcher+llm',
            analyzedAt: $now,
        );

        $array = $analysis->toArray();

        $this->assertSame(0.85, $array['risk_score']);
        $this->assertSame($techniques, $array['detected_techniques']);
        $this->assertSame(0.92, $array['confidence']);
        $this->assertSame('Direct injection.', $array['summary']);
        $this->assertSame(['instruction_override:ignore_previous'], $array['pattern_matches']);
        $this->assertSame('pattern_matcher+llm', $array['model_version']);
        $this->assertSame('2026-03-11T14:30:00+00:00', $array['analyzed_at']);
    }

    public function testToArrayContainsAllExpectedKeys(): void
    {
        $analysis = new PromptInjectionAnalysis(0.0, [], 0.0, '', [], '', new \DateTimeImmutable());
        $array = $analysis->toArray();

        $expectedKeys = ['risk_score', 'detected_techniques', 'confidence', 'summary', 'pattern_matches', 'model_version', 'analyzed_at'];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array, "Missing key: {$key}");
        }
        $this->assertCount(7, $array, 'toArray should have exactly 7 keys');
    }

    public function testToArrayWithEmptyAnalysis(): void
    {
        $analysis = new PromptInjectionAnalysis(0.0, [], 0.0, '', [], '', new \DateTimeImmutable());
        $array = $analysis->toArray();

        $this->assertSame(0.0, $array['risk_score']);
        $this->assertSame([], $array['detected_techniques']);
        $this->assertSame(0.0, $array['confidence']);
        $this->assertSame('', $array['summary']);
        $this->assertSame([], $array['pattern_matches']);
    }

    // =========================================================================
    // fromArray deserialization
    // =========================================================================

    public function testFromArrayReconstructsCorrectly(): void
    {
        $data = [
            'risk_score' => 0.6,
            'detected_techniques' => [
                ['technique' => 'jailbreak', 'evidence' => 'DAN', 'severity' => 'high'],
            ],
            'confidence' => 0.8,
            'summary' => 'Some patterns found.',
            'pattern_matches' => ['role_manipulation:act_as'],
            'model_version' => 'pattern_matcher_only',
            'analyzed_at' => '2026-03-11T14:30:00+00:00',
        ];

        $analysis = PromptInjectionAnalysis::fromArray($data);

        $this->assertSame(0.6, $analysis->getRiskScore());
        $this->assertCount(1, $analysis->getDetectedTechniques());
        $this->assertSame(0.8, $analysis->getConfidence());
        $this->assertSame('Some patterns found.', $analysis->getSummary());
        $this->assertSame(['role_manipulation:act_as'], $analysis->getPatternMatches());
        $this->assertSame('pattern_matcher_only', $analysis->getModelVersion());
    }

    public function testFromArrayHandlesMissingFields(): void
    {
        $analysis = PromptInjectionAnalysis::fromArray([]);

        $this->assertSame(0.0, $analysis->getRiskScore());
        $this->assertEmpty($analysis->getDetectedTechniques());
        $this->assertSame(0.0, $analysis->getConfidence());
        $this->assertSame('', $analysis->getSummary());
        $this->assertEmpty($analysis->getPatternMatches());
        $this->assertSame('', $analysis->getModelVersion());
    }

    public function testFromArrayWithPartialData(): void
    {
        $analysis = PromptInjectionAnalysis::fromArray([
            'risk_score' => 0.5,
            'summary' => 'Partial data.',
        ]);

        $this->assertSame(0.5, $analysis->getRiskScore());
        $this->assertSame('Partial data.', $analysis->getSummary());
        $this->assertSame(0.0, $analysis->getConfidence());
        $this->assertEmpty($analysis->getDetectedTechniques());
    }

    // =========================================================================
    // toArray / fromArray round-trip
    // =========================================================================

    public function testRoundTripPreservesAllData(): void
    {
        $techniques = [
            ['technique' => 'direct_injection', 'evidence' => 'ignore all rules', 'severity' => 'high'],
            ['technique' => 'jailbreak', 'evidence' => 'DAN mode enabled', 'severity' => 'high'],
        ];
        $matches = ['instruction_override:ignore_previous', 'jailbreak:dan_jailbreak'];

        $original = new PromptInjectionAnalysis(
            riskScore: 0.92,
            detectedTechniques: $techniques,
            confidence: 0.88,
            summary: 'Multiple injection techniques detected.',
            patternMatches: $matches,
            modelVersion: 'pattern_matcher+llm',
            analyzedAt: new \DateTimeImmutable('2026-03-11T14:00:00+00:00'),
        );

        $restored = PromptInjectionAnalysis::fromArray($original->toArray());

        $this->assertSame($original->getRiskScore(), $restored->getRiskScore());
        $this->assertSame($original->getDetectedTechniques(), $restored->getDetectedTechniques());
        $this->assertSame($original->getConfidence(), $restored->getConfidence());
        $this->assertSame($original->getSummary(), $restored->getSummary());
        $this->assertSame($original->getPatternMatches(), $restored->getPatternMatches());
        $this->assertSame($original->getModelVersion(), $restored->getModelVersion());
    }

    public function testRoundTripWithZeroRiskAnalysis(): void
    {
        $original = new PromptInjectionAnalysis(0.0, [], 0.95, 'Clean message.', [], 'gpt-4o-mini', new \DateTimeImmutable());
        $restored = PromptInjectionAnalysis::fromArray($original->toArray());

        $this->assertSame(0.0, $restored->getRiskScore());
        $this->assertEmpty($restored->getDetectedTechniques());
        $this->assertSame(0.95, $restored->getConfidence());
        $this->assertSame('Clean message.', $restored->getSummary());
    }

    public function testRoundTripWithMaxRiskAnalysis(): void
    {
        $original = new PromptInjectionAnalysis(
            1.0,
            [['technique' => 'direct_injection', 'evidence' => 'massive attack', 'severity' => 'high']],
            1.0,
            'Critical injection.',
            ['instruction_override:ignore_previous', 'jailbreak:jailbreak_keyword'],
            'pattern_matcher+llm',
            new \DateTimeImmutable(),
        );

        $restored = PromptInjectionAnalysis::fromArray($original->toArray());
        $this->assertSame(1.0, $restored->getRiskScore());
        $this->assertSame(1.0, $restored->getConfidence());
        $this->assertTrue($restored->isHighRisk());
    }

    // =========================================================================
    // JSON compatibility (simulating Doctrine JSON column)
    // =========================================================================

    public function testJsonEncodeDecodeRoundTrip(): void
    {
        $analysis = new PromptInjectionAnalysis(
            0.75,
            [['technique' => 'jailbreak', 'evidence' => 'DAN mode', 'severity' => 'high']],
            0.9,
            'Jailbreak attempt.',
            ['jailbreak:dan_jailbreak'],
            'pattern_matcher+llm',
            new \DateTimeImmutable('2026-03-11T14:00:00+00:00'),
        );

        // Simulate what Doctrine does: toArray -> json_encode -> store -> json_decode -> fromArray
        $json = json_encode($analysis->toArray());
        $this->assertIsString($json);
        $this->assertNotFalse($json);

        $decoded = json_decode($json, true);
        $restored = PromptInjectionAnalysis::fromArray($decoded);

        $this->assertSame($analysis->getRiskScore(), $restored->getRiskScore());
        $this->assertSame($analysis->getConfidence(), $restored->getConfidence());
        $this->assertSame($analysis->getSummary(), $restored->getSummary());
    }
}
