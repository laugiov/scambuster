<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\IocExtractorOrchestrator;
use App\Application\Communication\IocExtractor;
use App\Application\Communication\IocNormalizer;
use App\Application\Communication\IocValidator;
use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for F4: Improved phone regex to handle spaced international phone numbers.
 */
final class PhoneRegexImprovedTest extends TestCase
{
    private IocExtractorOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $validator = new IocValidator();
        $normalizer = new IocNormalizer();

        $llmClient = $this->createMock(LLMClientInterface::class);
        $logger = new \Psr\Log\NullLogger();
        $iocExtractor = new IocExtractor($llmClient, $logger);

        $this->orchestrator = new IocExtractorOrchestrator($em, $validator, $normalizer, $iocExtractor);
    }

    /**
     * @dataProvider spacedPhoneProvider
     */
    public function test_spaced_phone_numbers_are_extracted(string $text, string $expectedPhone): void
    {
        $results = $this->orchestrator->extractIocsWithRegex($text, ['phone']);

        $phones = array_map(
            fn (array $ioc): string => $ioc['value'],
            $results,
        );

        $this->assertContains($expectedPhone, $phones, "Phone '{$expectedPhone}' should be extracted from: {$text}");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spacedPhoneProvider(): array
    {
        return [
            'UK spaced' => ['Call me at +44 611 285 5150 please', '+44 611 285 5150'],
            'FR spaced' => ['Contact: +33 254 614 9956 for details', '+33 254 614 9956'],
            'GH spaced' => ['Reach us at +233 969 684 8446 now', '+233 969 684 8446'],
            'NG no spaces' => ['Phone: +2348943936313 available 24/7', '+2348943936313'],
        ];
    }
}
