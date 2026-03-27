<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\LanguageComplianceMetric;
use PHPUnit\Framework\TestCase;

final class LanguageComplianceMetricTest extends TestCase
{
    private LanguageComplianceMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new LanguageComplianceMetric();
    }

    public function test_all_matching_languages(): void
    {
        $corpus = array_merge(
            array_fill(0, 5, ['detected_language' => 'fr', 'reply_language' => 'fr']),
            array_fill(0, 5, ['detected_language' => 'en', 'reply_language' => 'en']),
        );

        $result = $this->metric->compute($corpus);

        $this->assertSame(1.0, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_some_mismatches(): void
    {
        $corpus = array_merge(
            array_fill(0, 9, ['detected_language' => 'fr', 'reply_language' => 'fr']),
            [['detected_language' => 'fr', 'reply_language' => 'en']],
        );

        $result = $this->metric->compute($corpus);

        $this->assertSame(0.9, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_fallbacks_excluded(): void
    {
        $corpus = [
            ['detected_language' => 'fr', 'reply_language' => 'fr'],
            ['detected_language' => 'fr', 'reply_language' => 'en', 'fallback_used' => true],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
        $this->assertSame(1.0, $result->measuredValue);
    }

    public function test_null_language_excluded(): void
    {
        $corpus = [
            ['detected_language' => null, 'reply_language' => 'en'],
            ['detected_language' => 'fr', 'reply_language' => 'fr'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
    }

    public function test_empty_corpus(): void
    {
        $result = $this->metric->compute([]);

        $this->assertSame(0, $result->sampleSize);
    }
}
