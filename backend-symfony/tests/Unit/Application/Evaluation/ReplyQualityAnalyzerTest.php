<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation;

use App\Application\Evaluation\ReplyQualityAnalyzer;
use PHPUnit\Framework\TestCase;

final class ReplyQualityAnalyzerTest extends TestCase
{
    private ReplyQualityAnalyzer $analyzer; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->analyzer = new ReplyQualityAnalyzer();
    }

    public function test_analyze_returns_all_metrics(): void
    {
        $corpus = $this->buildSyntheticCorpus(20);
        $result = $this->analyzer->analyze($corpus);

        $this->assertCount(9, $result['metrics']);
        $this->assertSame(20, $result['corpus_size']);
        $this->assertContains($result['overall_verdict'], ['PASS', 'WARNING', 'FAIL', 'INSUFFICIENT_DATA']);
    }

    public function test_analyze_extracts_best_and_worst(): void
    {
        $corpus = $this->buildSyntheticCorpus(20);
        $result = $this->analyzer->analyze($corpus);

        $this->assertLessThanOrEqual(5, count($result['best_replies']));
        $this->assertLessThanOrEqual(5, count($result['worst_replies']));
    }

    public function test_analyze_builds_persona_matrix(): void
    {
        $corpus = $this->buildSyntheticCorpus(20);
        $result = $this->analyzer->analyze($corpus);

        $this->assertNotEmpty($result['persona_matrix']);
    }

    public function test_empty_corpus_returns_insufficient_data(): void
    {
        $result = $this->analyzer->analyze([]);

        $this->assertSame('INSUFFICIENT_DATA', $result['overall_verdict']);
        $this->assertSame(0, $result['corpus_size']);
    }

    public function test_metric_names_cover_all_dimensions(): void
    {
        $corpus = $this->buildSyntheticCorpus(20);
        $result = $this->analyzer->analyze($corpus);

        $names = array_map(fn ($m) => $m->name, $result['metrics']);

        $this->assertContains('non_repetitiveness', $names);
        $this->assertContains('opening_diversity', $names);
        $this->assertContains('persona_distinctness', $names);
        $this->assertContains('first_attempt_approval', $names);
        $this->assertContains('avg_naturalness_score', $names);
        $this->assertContains('language_compliance', $names);
        $this->assertContains('ioc_elicitation', $names);
        $this->assertContains('security_pass_rate', $names);
        $this->assertContains('fallback_rate', $names);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildSyntheticCorpus(int $size): array
    {
        $personas = ['elderly_person', 'accountant_meticulous', 'lonely_divorcee', 'tech_newbie'];
        $scamTypes = ['PHISHING', 'ROMANCE', 'ADVANCE_FEE_419'];
        $openings = [
            'Bonjour, merci pour votre message.',
            'Oh mon Dieu, est-ce vraiment vrai?',
            'Je vous prie de bien vouloir me fournir les details.',
            'Cher monsieur, je suis tres interessee.',
            'Could you please explain more about this opportunity?',
        ];

        $corpus = [];

        for ($i = 0; $i < $size; ++$i) {
            $convId = 'conv-' . ($i % 5);
            $persona = $personas[$i % count($personas)];
            $scamType = $scamTypes[$i % count($scamTypes)];
            $opening = $openings[$i % count($openings)];

            $corpus[] = [
                'conv_id' => $convId,
                'scam_type' => $scamType,
                'persona_code' => $persona,
                'message_count' => rand(2, 10),
                'detected_language' => $i % 4 === 0 ? 'en' : 'fr',
                'reply_language' => $i % 4 === 0 ? 'en' : 'fr',
                'text' => $opening . ' Additional context about the conversation topic number ' . $i . ' with unique content.',
                'word_count' => rand(40, 120),
                'attempts' => $i % 3 === 0 ? 2 : 1,
                'fallback_used' => false,
                'approved' => true,
                'naturalness' => rand(2, 5),
                'persona_fit' => rand(2, 5),
                'ti_value' => rand(2, 5),
                'security_pass' => true,
                'policy_flags' => [],
                'cost_estimate' => 0.003,
            ];
        }

        return $corpus;
    }
}
