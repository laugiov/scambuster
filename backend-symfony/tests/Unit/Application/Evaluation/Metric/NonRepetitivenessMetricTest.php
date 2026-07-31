<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Evaluation\Metric;

use App\Application\Evaluation\Metric\NonRepetitivenessMetric;
use PHPUnit\Framework\TestCase;

final class NonRepetitivenessMetricTest extends TestCase
{
    private NonRepetitivenessMetric $metric; // @phpstan-ignore-line

    protected function setUp(): void
    {
        $this->metric = new NonRepetitivenessMetric();
    }

    public function test_identical_replies_yield_high_similarity(): void
    {
        $corpus = [];

        for ($i = 0; $i < 12; ++$i) {
            $corpus[] = ['conv_id' => 'c' . ($i % 6), 'text' => 'Hello, I received your message and I am interested.'];
        }

        $result = $this->metric->compute($corpus);

        $this->assertSame('non_repetitiveness', $result->name);
        $this->assertGreaterThan(0.90, $result->measuredValue);
        $this->assertSame('FAIL', $result->verdict);
    }

    public function test_different_replies_yield_low_similarity(): void
    {
        $corpus = [];
        $texts = [
            'Bonjour, merci pour votre message, je suis tres interessee par votre proposition financiere.',
            'Could you kindly provide more details about the investment opportunity you mentioned previously?',
            'I would like to know about the banking procedures for international transfers.',
            'Mon mari travaillait dans la finance, peut-etre pourriez-vous expliquer davantage.',
            'What documents do I need to prepare for this transaction?',
            'Je ne comprends pas bien, pouvez-vous simplifier votre explication?',
            'This sounds interesting but I need to consult my son first about the details.',
            'Pourriez-vous me donner le numero IBAN et le code BIC pour le virement?',
            'I have been waiting for a response regarding the payment schedule you mentioned.',
            'Merci beaucoup, je vais examiner les documents que vous avez envoyes.',
            'Can you explain the fees associated with this type of international transfer?',
            'Je suis un peu mefiant, avez-vous des references que je pourrais verifier?',
        ];

        for ($i = 0; $i < 12; ++$i) {
            $corpus[] = ['conv_id' => 'c' . ($i % 6), 'text' => $texts[$i]];
        }

        $result = $this->metric->compute($corpus);

        $this->assertLessThan(0.30, $result->measuredValue);
        $this->assertSame('PASS', $result->verdict);
    }

    public function test_single_reply_per_conversation_returns_no_pairs(): void
    {
        $corpus = [
            ['conv_id' => 'c1', 'text' => 'Hello there'],
            ['conv_id' => 'c2', 'text' => 'Bonjour monsieur'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(0, $result->sampleSize);
    }

    public function test_empty_corpus(): void
    {
        $result = $this->metric->compute([]);

        $this->assertSame(0, $result->sampleSize);
        $this->assertSame(0.0, $result->measuredValue);
    }

    public function test_partial_overlap_yields_medium_similarity(): void
    {
        $corpus = [
            ['conv_id' => 'c1', 'text' => 'Thank you for the information about the bank transfer details.'],
            ['conv_id' => 'c1', 'text' => 'Thank you for the message, could you explain the payment process?'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertGreaterThan(0.10, $result->measuredValue);
        $this->assertLessThan(0.90, $result->measuredValue);
    }

    public function test_multiple_conversations_averaged(): void
    {
        $corpus = [
            ['conv_id' => 'c1', 'text' => 'Hello world test message one'],
            ['conv_id' => 'c1', 'text' => 'Hello world test message one'],
            ['conv_id' => 'c2', 'text' => 'Completely different text here about banking'],
            ['conv_id' => 'c2', 'text' => 'Another unique response regarding investments'],
        ];

        $result = $this->metric->compute($corpus);

        $this->assertSame(2, $result->sampleSize);
    }
}
