<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Stix;

use App\Application\Stix\ExtensionSchemaValidator;
use PHPUnit\Framework\TestCase;

final class ExtensionSchemaValidatorTest extends TestCase
{
    private ExtensionSchemaValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ExtensionSchemaValidator();
    }

    // ---------------------------------------------------------------- //
    //  x_scambuster_context schema
    // ---------------------------------------------------------------- //

    public function testValidContextStructuralPasses(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.1',
            'enrichment_status' => 'structural',
            'persona_code' => 'lonely_person',
            'revelation_turn' => 7,
            'co_revealed_ioc_types' => ['iban', 'phone'],
            'co_revealed_count' => 2,
            'reward_value' => 0.4,
        ], 'x_scambuster_context');

        self::assertSame([], $errors);
    }

    public function testValidContextEnrichedWithLlmFieldsPasses(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.1',
            'enrichment_status' => 'enriched',
            'persona_code' => 'lonely_person',
            'enrichment_model' => 'gpt-4o-mini',
            'hesitation_detected' => true,
            'language_switch' => false,
            'urgency_score' => 0.87,
        ], 'x_scambuster_context');

        self::assertSame([], $errors);
    }

    public function testContextMissingRequiredKeyFails(): void
    {
        $errors = $this->validator->validate([
            'enrichment_status' => 'structural',
        ], 'x_scambuster_context');

        self::assertNotEmpty($errors);
        self::assertStringContainsString('missing required key "schema_version"', $errors[0]);
    }

    public function testContextEnrichmentStatusNotInEnumFails(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.1',
            'enrichment_status' => 'pending',
        ], 'x_scambuster_context');

        self::assertNotEmpty($errors);
        self::assertStringContainsString('not in enum', implode("\n", $errors));
    }

    public function testContextRejectsAdditionalUnknownKey(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.1',
            'enrichment_status' => 'structural',
            'this_was_not_in_the_schema' => 'oops',
        ], 'x_scambuster_context');

        self::assertNotEmpty($errors);
        self::assertStringContainsString('unexpected additional key "this_was_not_in_the_schema"', implode("\n", $errors));
    }

    public function testContextWrongTypeFails(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.1',
            'enrichment_status' => 'enriched',
            'hesitation_detected' => 'true',
        ], 'x_scambuster_context');

        self::assertNotEmpty($errors);
        self::assertStringContainsString('hesitation_detected expected type boolean', implode("\n", $errors));
    }

    // ---------------------------------------------------------------- //
    //  x_scambuster_mirror schema
    // ---------------------------------------------------------------- //

    public function testValidMirrorPasses(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.0',
            'persona_code' => 'lonely_person',
            'scam_type_code' => 'ROMANCE',
            'hunted_victim_profile' => 'Lonely retiree.',
            'cognitive_lever' => 'Trust + urgency.',
            'mirror_explanation' => 'Scammer builds trust then pivots to financial request.',
            'generated_at' => '2026-06-15T12:00:00.000Z',
            'generated_by_model' => 'gpt-4o-mini',
            'prompt_version' => 'v1',
        ], 'x_scambuster_mirror');

        self::assertSame([], $errors);
    }

    public function testMirrorMissingRequiredFieldFails(): void
    {
        $errors = $this->validator->validate([
            'schema_version' => '1.0',
            'persona_code' => 'lonely_person',
            // scam_type_code, hunted_victim_profile, cognitive_lever,
            // mirror_explanation, generated_by_model, prompt_version missing
        ], 'x_scambuster_mirror');

        self::assertNotEmpty($errors);
        $joined = implode("\n", $errors);
        self::assertStringContainsString('scam_type_code', $joined);
        self::assertStringContainsString('hunted_victim_profile', $joined);
        self::assertStringContainsString('cognitive_lever', $joined);
        self::assertStringContainsString('mirror_explanation', $joined);
        self::assertStringContainsString('generated_by_model', $joined);
        self::assertStringContainsString('prompt_version', $joined);
    }

    // ---------------------------------------------------------------- //
    //  Bundle-level validation
    // ---------------------------------------------------------------- //

    public function testValidateBundleSurfacesIndicatorAndNoteErrors(): void
    {
        $bundle = [
            'type' => 'bundle',
            'objects' => [
                ['type' => 'identity', 'id' => 'identity--x'],
                [
                    'type' => 'indicator',
                    'id' => 'indicator--1',
                    'extensions' => [
                        \App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID => [
                            'extension_type' => 'property-extension',
                            'x_scambuster_context' => [
                                'enrichment_status' => 'structural',
                                // schema_version missing → required violation
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'note',
                    'id' => 'note--1',
                    'x_scambuster_mirror' => [
                        'schema_version' => '1.0',
                        // most required fields missing
                    ],
                ],
            ],
        ];

        $errors = $this->validator->validateBundle($bundle);

        self::assertNotEmpty($errors);
        $joined = implode("\n", $errors);
        self::assertStringContainsString('objects[1] (indicator)', $joined);
        self::assertStringContainsString('objects[2] (note)', $joined);
    }

    public function testValidateBundleReturnsNoErrorsOnCleanBundle(): void
    {
        $bundle = [
            'type' => 'bundle',
            'objects' => [
                [
                    'type' => 'indicator',
                    'id' => 'indicator--1',
                    'extensions' => [
                        \App\Application\Stix\ScambusterStixExtensions::CONTEXT_ID => [
                            'extension_type' => 'property-extension',
                            'x_scambuster_context' => [
                                'schema_version' => '1.1',
                                'enrichment_status' => 'structural',
                                'persona_code' => 'lonely_person',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        self::assertSame([], $this->validator->validateBundle($bundle));
    }

    public function testValidateBundleIgnoresIndicatorsWithoutOurExtension(): void
    {
        $bundle = [
            'type' => 'bundle',
            'objects' => [
                ['type' => 'indicator', 'id' => 'indicator--1'],
                ['type' => 'indicator', 'id' => 'indicator--2', 'extensions' => []],
            ],
        ];

        self::assertSame([], $this->validator->validateBundle($bundle));
    }
}
