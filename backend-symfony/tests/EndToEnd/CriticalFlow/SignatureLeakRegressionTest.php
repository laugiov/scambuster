<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd\CriticalFlow;

use App\Application\Audit\AuditLogger;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Application\LLM\SignatureStripper;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Regression suite for the 6 real-world conversations from
 * the 2026-05-11 production incident where LLM-generated replies leaked
 * an identity-betraying signature past every existing validator.
 *
 * Each fixture replays the actual LLM output through the SignatureStripper
 * and asserts that the failure marker (the verbatim text that betrayed the
 * automated nature of the reply) is NOT present in the post-strip body.
 *
 * The 6 cases were captured from the live `message` table at
 * 2026-05-11 13:00..14:30 UTC.
 */
final class SignatureLeakRegressionTest extends TestCase
{
    private function newStripper(): SignatureStripper
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $siem = $this->createMock(SiemExporterInterface::class);
        $audit = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), $siem);

        return new SignatureStripper(
            signatureStripEnabled: true,
            logger: new NullLogger(),
            auditLogger: $audit,
        );
    }

    /**
     * @return iterable<string, array{
     *   conv_id: string,
     *   persona: string,
     *   leak_pattern_description: string,
     *   historical_llm_output: string,
     *   failure_marker: string,
     * }>
     */
    public static function regressionFixturesProvider(): iterable
    {
        // Each fixture preserves the historical LLM output verbatim (truncated
        // body where needed for test isolation, but the leak block at the end
        // is intact). The failure_marker is the smallest substring that
        // proves the leak happened.

        yield 'conv 66666666 accountant_meticulous [Your Name]' => [
            'conv_id' => '66666666',
            'persona' => 'accountant_meticulous',
            'leak_pattern_description' => 'Literal placeholder [Your Name] left by template fallback',
            'historical_llm_output' => "Hello James,\n\nThank you for reaching out. I appreciate your offer to assist with website design and development. Before we proceed, could you please provide your company's invoice number and any relevant purchase order references for our records? Additionally, I would like to know the expected due date for any proposed services.\n\nAs part of our internal validation procedures, all payments must be approved by my manager, so having this information will be essential for a smooth process.\n\nLooking forward to your response.\n\nBest regards,\n[Your Name]",
            'failure_marker' => '[Your Name]',
        ];

        yield 'conv 44444444 accountant_meticulous [Your Name]' => [
            'conv_id' => '44444444',
            'persona' => 'accountant_meticulous',
            'leak_pattern_description' => 'Literal placeholder [Your Name] (second occurrence)',
            'historical_llm_output' => "Dear Sender,\n\nThank you for your message regarding the investment opportunity. However, before I can consider this, I require some essential details to proceed. Please provide the relevant invoice number, purchase order reference, and the due date for any initial payment associated with this opportunity.\n\nAs part of our internal validation procedures, all investment opportunities must be reviewed and approved by my manager before any action can be taken.\n\nI look forward to your prompt response with the necessary information.\n\nBest regards,\n[Your Name]",
            'failure_marker' => '[Your Name]',
        ];

        yield 'conv 55555555 unemployed_graduate signed with scammer name Oscar' => [
            'conv_id' => '55555555',
            'persona' => 'unemployed_graduate',
            'leak_pattern_description' => "Sentinel signed with the scammer's own name (Oscar)",
            'historical_llm_output' => "Hello James,\n\nI hope you're doing well. I'm glad you're interested in moving forward with the project! To process the payment smoothly, could you please provide me with your bank details, including the IBAN? This will help expedite the transaction.\n\nAdditionally, if you have a preferred payment method, feel free to let me know. I'm excited to collaborate and can't wait to see your portfolio shine!\n\nLooking forward to your response.\n\nBest,\nEta",
            'failure_marker' => "Best,\nEta",
        ];

        yield 'conv 11111111 elderly_person mailbox-derived name Beta' => [
            'conv_id' => '11111111',
            'persona' => 'elderly_person',
            'leak_pattern_description' => 'Hallucinated signature derived from mailbox name beta-counsel',
            'historical_llm_output' => "Oh dear, this all sounds quite exciting! I would love to learn more about web design — my grandchildren tell me everyone has a website these days. If you have any examples of your work, that would be wonderful!\n\nLooking forward to hearing from you soon!\n\nWarmly,\nBeta",
            'failure_marker' => "Warmly,\nBeta",
        ];

        yield 'conv 22222222 elderly_person hallucinated name Epsilon' => [
            'conv_id' => '22222222',
            'persona' => 'elderly_person',
            'leak_pattern_description' => 'Pure LLM hallucination — Epsilon appears nowhere in inbound context',
            'historical_llm_output' => "Hello dear,\n\nWhat a kind message! Of course I would love to know more about this opportunity. Do you need my phone number? I can give you that if it helps.\n\nLooking forward to hearing from you!\n\nWarmly,\nEpsilon",
            'failure_marker' => "Warmly,\nEpsilon",
        ];

        yield 'conv 33333333 worried_customer hallucinated name Ash' => [
            'conv_id' => '33333333',
            'persona' => 'worried_customer',
            'leak_pattern_description' => 'Pure LLM hallucination — Ash appears nowhere in inbound context',
            'historical_llm_output' => "Oh my goodness, I am so worried! I need to figure this out? I need to get this sorted out immediately! What should I do next? Please respond quickly!\n\nThank you!\nZeta",
            'failure_marker' => "Thank you!\nZeta",
        ];
    }

    /**
     * @dataProvider regressionFixturesProvider
     */
    public function test_failure_marker_is_absent_after_strip(
        string $conv_id,
        string $persona,
        string $leak_pattern_description,
        string $historical_llm_output,
        string $failure_marker,
    ): void {
        // Pre-condition: the failure marker IS present in the historical
        // output (sanity check on the fixture itself).
        self::assertStringContainsString(
            $failure_marker,
            $historical_llm_output,
            "Fixture for conv {$conv_id} is malformed — failure_marker not found in historical output",
        );

        $stripper = $this->newStripper();
        $result = $stripper->strip($historical_llm_output, $conv_id);

        self::assertGreaterThan(
            0,
            $result->bytesRemoved,
            "Stripper failed to remove any bytes from conv {$conv_id} ({$persona}): {$leak_pattern_description}",
        );

        self::assertStringNotContainsString(
            $failure_marker,
            $result->textAfter,
            "After strip, conv {$conv_id} ({$persona}) STILL contains the failure marker: '{$failure_marker}'",
        );

        self::assertNotEmpty(
            $result->matchedPatterns,
            "Stripper did not record which pattern fired for conv {$conv_id}",
        );
    }
}
