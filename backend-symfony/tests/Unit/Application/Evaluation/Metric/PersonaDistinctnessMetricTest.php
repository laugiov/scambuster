<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\PersonaDistinctnessMetric;
use PHPUnit\Framework\TestCase;

final class PersonaDistinctnessMetricTest extends TestCase
{
    private PersonaDistinctnessMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new PersonaDistinctnessMetric();
    }

    public function test_identical_texts_across_personas_yield_low_distinctness(): void
    {
        $corpus = [
            ['persona_code' => 'p1', 'text' => 'Thank you for the information about the transfer.'],
            ['persona_code' => 'p2', 'text' => 'Thank you for the information about the transfer.'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertLessThan(0.10, $result->measuredValue);
    }

    public function test_different_vocabulary_yields_high_distinctness(): void
    {
        $corpus = [];

        for ($i = 0; $i < 5; ++$i) {
            $corpus[] = ['persona_code' => 'elderly', 'text' => 'Oh dear, this is quite confusing for me. My grandson usually helps with these computer things. Could you please explain in simpler terms? I am old and retired and the technology baffles me. Variation ' . $i];
            $corpus[] = ['persona_code' => 'accountant', 'text' => 'I need the SIRET number, bank details, invoice reference, and accounting documentation before processing any payment. The financial regulations require proper paperwork. Variation ' . $i];
            $corpus[] = ['persona_code' => 'romantic', 'text' => 'Your message touched my heart deeply. Since my husband passed, I have been so lonely. Tell me more about yourself, where do you live, what are your dreams and hopes? Variation ' . $i];
        }

        $result = $this->metric->compute($corpus);

        $this->assertGreaterThan(0.15, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_single_persona_insufficient(): void
    {
        $corpus = [
            ['persona_code' => 'p1', 'text' => 'Hello world'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
    }

    public function test_similarity_matrix_has_correct_shape(): void
    {
        $corpus = [
            ['persona_code' => 'a', 'text' => 'Banking terms financial payment invoice'],
            ['persona_code' => 'b', 'text' => 'Love heart romance feeling lonely missing'],
        ];

        $matrix = $this->metric->getSimilarityMatrix($corpus);

        $this->assertArrayHasKey('a', $matrix);
        $this->assertArrayHasKey('b', $matrix);
        $this->assertSame(1.0, $matrix['a']['a']);
        $this->assertSame(1.0, $matrix['b']['b']);
        $this->assertLessThan(1.0, $matrix['a']['b']);
    }

    public function test_fallback_entries_excluded(): void
    {
        $corpus = [
            ['persona_code' => 'p1', 'text' => 'Real text here', 'fallback_used' => false],
            ['persona_code' => 'p2', 'text' => 'Fallback', 'fallback_used' => true],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
    }
}
