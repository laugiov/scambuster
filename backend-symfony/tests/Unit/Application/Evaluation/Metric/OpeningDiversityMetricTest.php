<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\OpeningDiversityMetric;
use PHPUnit\Framework\TestCase;

final class OpeningDiversityMetricTest extends TestCase
{
    private OpeningDiversityMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new OpeningDiversityMetric();
    }

    public function test_all_different_openings_yield_ratio_1(): void
    {
        $corpus = [
            ['text' => 'Hello, I am writing to you about the offer.'],
            ['text' => 'Dear sir, thank you for your message.'],
            ['text' => 'Bonjour, je suis interesse par votre proposition.'],
            ['text' => 'Good morning, I have a question about the transfer.'],
            ['text' => 'Thank you for contacting me about this matter.'],
            ['text' => 'I appreciate your email and the details provided.'],
            ['text' => 'Merci beaucoup pour ces informations detaillees.'],
            ['text' => 'Could you please clarify the terms of this arrangement?'],
            ['text' => 'Mon fils me dit que je devrais faire attention.'],
            ['text' => 'This is very interesting and I would like more details.'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1.0, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_all_same_openings_yield_low_ratio(): void
    {
        $corpus = [];

        for ($i = 0; $i < 10; ++$i) {
            $corpus[] = ['text' => 'Hello there. Variation number ' . $i . ' of the same opening.'];
        }

        $result = $this->metric->compute($corpus);

        $this->assertLessThanOrEqual(0.34, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_fallback_entries_excluded(): void
    {
        $corpus = [
            ['text' => 'Real reply here.', 'fallback_used' => false],
            ['text' => 'Fallback message.', 'fallback_used' => true],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(1, $result->sampleSize);
    }

    public function test_empty_corpus(): void
    {
        $result = $this->metric->compute([]);

        $this->assertSame(0, $result->sampleSize);
    }

    public function test_extraction_handles_newlines(): void
    {
        $corpus = [
            ['text' => "First line opening\nSecond line continuation."],
            ['text' => "Different opening\nAnother line."],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(2, $result->sampleSize);
        $this->assertSame(1.0, $result->measuredValue);
    }
}
