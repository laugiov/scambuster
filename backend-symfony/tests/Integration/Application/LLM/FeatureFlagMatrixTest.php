<?php

declare(strict_types=1);

namespace App\Tests\Integration\Application\LLM;

use App\Application\Audit\AuditLogger;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\SignatureStripper;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * 7 sampled combinations from the
 * 32-combination space of the 5 reply-pipeline feature flags. The 5 flags:
 *
 *   1. signatureStripEnabled              (SignatureStripper)
 *   2. validatorContextEnabled            (ReplyValidator + PromptBuilder)
 *   3. validatorStructuredCorrection      (PromptBuilder)
 *   4. generatorPatchMode                 (PromptBuilder)
 *   5. generatorNoSignatureInstruction    (PromptBuilder)
 *
 * Sampled cases:
 *   - All ON (default production mode)
 *   - All OFF (full rollback to previous behavior)
 *   - 5 single-flag-OFF cases (each flag toggled individually)
 *
 * For each case, the test verifies that the relevant services can be
 * constructed and produce well-formed output, with the expected
 * behavior characteristic present or absent.
 */
final class FeatureFlagMatrixTest extends TestCase
{
    private function newStripper(bool $enabled): SignatureStripper
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $siem = $this->createMock(SiemExporterInterface::class);
        $audit = new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), $siem);

        return new SignatureStripper(
            signatureStripEnabled: $enabled,
            logger: new NullLogger(),
            auditLogger: $audit,
        );
    }

    private function newPromptBuilder(
        bool $context,
        bool $structuredCorrection,
        bool $patchMode,
        bool $noSignature,
    ): PromptBuilder {
        $personaManager = $this->createMock(PersonaManager::class);
        $personaManager->method('findByCode')->willReturn(new Persona(
            'bank_customer',
            'Bank customer',
            'Worried',
            'You are a bank customer.',
        ));

        return new PromptBuilder(
            contextAnalyzer: new ContextAnalyzer(),
            variationProvider: new VariationProvider(),
            reciprocityManager: new ReciprocityManager(),
            personaManager: $personaManager,
            logger: new NullLogger(),
            conversationAnalyzer: null,
            validatorContextEnabled: $context,
            validatorStructuredCorrection: $structuredCorrection,
            generatorNoSignatureInstruction: $noSignature,
            generatorPatchMode: $patchMode,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function newGeneratorContext(): array
    {
        return [
            'conv_id' => 'conv-matrix',
            'detected_language' => 'en',
            'scam_type' => ['code' => 'PHISHING', 'label_fr' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'scammer message', 'ts_msg' => '2026-05-12T10:00:00Z', 'headers' => ['from' => 'scammer@test']],
            ],
            'policy_min_words' => 50,
            'policy_max_words' => 150,
        ];
    }

    /**
     * @return iterable<string, array{strip:bool, ctx:bool, corr:bool, patch:bool, noSig:bool}>
     */
    public static function flagCombinationsProvider(): iterable
    {
        // All ON (full production mode)
        yield 'all flags ON (production default)' => ['strip' => true,  'ctx' => true,  'corr' => true,  'patch' => true,  'noSig' => true];

        // All OFF (full rollback to previous behavior)
        yield 'all flags OFF (full rollback)'      => ['strip' => false, 'ctx' => false, 'corr' => false, 'patch' => false, 'noSig' => false];

        // Single-flag-OFF (catches individual breakage)
        yield 'only strip OFF'                      => ['strip' => false, 'ctx' => true,  'corr' => true,  'patch' => true,  'noSig' => true];
        yield 'only validator-context OFF'          => ['strip' => true,  'ctx' => false, 'corr' => true,  'patch' => true,  'noSig' => true];
        yield 'only structured-correction OFF'      => ['strip' => true,  'ctx' => true,  'corr' => false, 'patch' => true,  'noSig' => true];
        yield 'only patch-mode OFF'                 => ['strip' => true,  'ctx' => true,  'corr' => true,  'patch' => false, 'noSig' => true];
        yield 'only no-signature-instruction OFF'   => ['strip' => true,  'ctx' => true,  'corr' => true,  'patch' => true,  'noSig' => false];
    }

    /**
     * @dataProvider flagCombinationsProvider
     */
    public function test_each_flag_combination_produces_well_formed_output(
        bool $strip,
        bool $ctx,
        bool $corr,
        bool $patch,
        bool $noSig,
    ): void {
        // ── Stripper smoke test ─────────────────────────────────────────
        $stripper = $this->newStripper($strip);
        $input = "Hello, please send the IBAN.\n\nBest regards,\nJohn";
        $stripResult = $stripper->strip($input, 'conv-matrix');

        if ($strip) {
            self::assertGreaterThan(0, $stripResult->bytesRemoved, 'strip ON: must remove the signature');
            self::assertStringNotContainsString('John', $stripResult->textAfter);
        } else {
            self::assertSame(0, $stripResult->bytesRemoved, 'strip OFF: input passes through');
            self::assertSame($input, $stripResult->textAfter);
        }

        // ── PromptBuilder smoke test (generator + validator prompts) ─────
        $builder = $this->newPromptBuilder($ctx, $corr, $patch, $noSig);

        // Validator prompt (with sample context)
        $validatorPrompts = $builder->buildValidatorPrompts(
            'sample reply text',
            'bank_customer',
            ['inbound_text' => 'scammer text', 'inbound_from' => 'x@y', 'previous_outbound_messages' => [], 'language' => 'en'],
        );

        if ($ctx) {
            self::assertStringContainsString('## Conversational context', $validatorPrompts['user']);
        } else {
            self::assertStringNotContainsString('## Conversational context', $validatorPrompts['user']);
        }

        if ($corr) {
            self::assertStringContainsString('"correction"', $validatorPrompts['system']);
        } else {
            self::assertStringNotContainsString('"correction"', $validatorPrompts['system']);
        }

        // Generator prompt (with patch-mode correction in context)
        $genContext = $this->newGeneratorContext();
        $genContext['retry_correction'] = ['problem_span' => 'x', 'replacement' => 'y', 'rationale' => 'r'];

        $generatorPrompts = $builder->buildGeneratorPrompts($genContext, 'bank_customer');

        if ($noSig) {
            self::assertStringContainsString('End your reply WITHOUT any signature', $generatorPrompts['user']);
        } else {
            self::assertStringNotContainsString('End your reply WITHOUT any signature', $generatorPrompts['user']);
        }

        if ($patch) {
            self::assertStringContainsString('### Apply this exact correction', $generatorPrompts['user']);
            self::assertStringNotContainsString('Write your reply now.', $generatorPrompts['user']);
        } else {
            self::assertStringNotContainsString('### Apply this exact correction', $generatorPrompts['user']);
            self::assertStringContainsString('Write your reply now.', $generatorPrompts['user']);
        }

        // System prompt must always be well-formed and non-empty.
        self::assertNotEmpty($validatorPrompts['system']);
        self::assertNotEmpty($validatorPrompts['user']);
        self::assertNotEmpty($generatorPrompts['system']);
        self::assertNotEmpty($generatorPrompts['user']);
    }
}
