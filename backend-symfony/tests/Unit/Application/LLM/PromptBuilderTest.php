<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\VariationProvider;
use App\Domain\Communication\Persona;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for PromptBuilder
 */
class PromptBuilderTest extends TestCase
{
    private PromptBuilder $builder;
    private ContextAnalyzer $contextAnalyzer;
    private VariationProvider $variationProvider;
    private ReciprocityManager $reciprocityManager;
    private PersonaManager $personaManager;
    private LoggerInterface $logger;

    /** @var array<string, Persona> */
    private array $personas;

    protected function setUp(): void
    {
        $this->contextAnalyzer = new ContextAnalyzer();
        $this->variationProvider = new VariationProvider();
        $this->reciprocityManager = new ReciprocityManager();

        // Create test persona entities
        $this->personas = [
            'bank_customer' => new Persona(
                'bank_customer',
                'Client bancaire inquiet',
                'Inquiet, méfiant mais coopératif',
                'Tu es un client bancaire inquiet qui a reçu un message suspect.',
            ),
            'elderly_person' => new Persona(
                'elderly_person',
                'Personne âgée peu familière avec la technologie',
                'Poli, confus, lent à comprendre',
                'Tu es une personne âgée qui ne comprend pas bien la technologie.',
            ),
            'lonely_person' => new Persona(
                'lonely_person',
                'Personne seule en quête d\'affection',
                'Émotionnel, vulnérable, espérant une connexion',
                'Tu es une personne seule qui cherche de la compagnie.',
            ),
            'confused_user' => new Persona(
                'confused_user',
                'Utilisateur confus face à un problème technique',
                'Anxieux, dépassé, cherchant de l\'aide',
                'Tu es un utilisateur confus face à un problème technique.',
            ),
            'small_business_owner' => new Persona(
                'small_business_owner',
                'Propriétaire de petite entreprise',
                'Professionnel, pressé, pragmatique',
                'Tu es un propriétaire de petite entreprise qui gère ses factures.',
            ),
            'generic_user' => new Persona(
                'generic_user',
                'Generic User',
                'Neutral',
                str_repeat('System prompt content. ', 10),
            ),
        ];

        // Mock PersonaManager to return personas from our map
        $this->personaManager = $this->createMock(PersonaManager::class);
        $this->personaManager->method('findByCode')->willReturnCallback(
            fn (string $code) => $this->personas[$code] ?? null
        );

        // Mock Logger
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->builder = new PromptBuilder(
            $this->contextAnalyzer,
            $this->variationProvider,
            $this->reciprocityManager,
            $this->personaManager,
            $this->logger
        );
    }

    /**
     * @test
     */
    public function it_loads_persona_from_database(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('loadPersona');
        $method->setAccessible(true);

        $persona = $method->invoke($this->builder, 'bank_customer');

        $this->assertIsArray($persona);
        $this->assertArrayHasKey('system_prompt', $persona);
        $this->assertArrayHasKey('persona_label', $persona);
        $this->assertArrayHasKey('persona_tone', $persona);
        $this->assertIsString($persona['system_prompt']);
        $this->assertNotEmpty($persona['system_prompt']);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_missing_persona(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Persona not found in database');

        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('loadPersona');
        $method->setAccessible(true);

        $method->invoke($this->builder, 'non_existent_persona');
    }

    /**
     * @test
     */
    public function it_builds_generator_prompts_with_empty_history(): void
    {
        $context = [
            'scam_type' => ['label_fr' => 'Arnaque bancaire'],
            'last_messages' => [],
        ];

        $prompts = $this->builder->buildGeneratorPrompts($context, 'bank_customer');

        $this->assertIsArray($prompts);
        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertStringContainsString('Arnaque bancaire', $prompts['user']);
        $this->assertStringContainsString('No prior messages', $prompts['user']);
    }

    /**
     * @test
     */
    public function it_builds_generator_prompts_with_conversation_history(): void
    {
        $context = [
            'scam_type' => ['label_fr' => 'Arnaque bancaire'],
            'last_messages' => [
                [
                    'direction' => 'in',
                    'headers' => ['from' => 'scammer@evil.com'],
                    'body_text' => 'Vous devez confirmer vos coordonnées bancaires.',
                    'ts_msg' => '2025-01-15T10:30:00+00:00',
                ],
                [
                    'direction' => 'out',
                    'headers' => ['from' => 'victim@example.com'],
                    'body_text' => 'Je ne comprends pas, qui êtes-vous ?',
                    'ts_msg' => '2025-01-15T11:00:00+00:00',
                ],
            ],
        ];

        $prompts = $this->builder->buildGeneratorPrompts($context, 'bank_customer');

        $this->assertStringContainsString('Attacker', $prompts['user']);
        $this->assertStringContainsString('Victim', $prompts['user']);
        $this->assertStringContainsString('coordonnées bancaires', $prompts['user']);
        $this->assertStringContainsString('qui êtes-vous', $prompts['user']);
        $this->assertStringContainsString('scammer@evil.com', $prompts['user']);
    }

    /**
     * @test
     */
    public function it_builds_validator_prompts(): void
    {
        $generatedText = 'Bonjour, je souhaite en savoir plus sur cette offre.';

        $prompts = $this->builder->buildValidatorPrompts($generatedText, 'bank_customer');

        $this->assertIsArray($prompts);
        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertStringContainsString('auditor', $prompts['system']);
        $this->assertStringContainsString('JSON', $prompts['system']);
        $this->assertStringContainsString($generatedText, $prompts['user']);
    }

    /**
     * @test
     */
    public function it_formats_empty_conversation_history(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('formatConversationHistory');
        $method->setAccessible(true);

        $formatted = $method->invoke($this->builder, []);

        $this->assertStringContainsString('No prior messages', $formatted);
    }

    /**
     * @test
     */
    public function it_formats_conversation_history_with_messages(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('formatConversationHistory');
        $method->setAccessible(true);

        $messages = [
            [
                'direction' => 'in',
                'headers' => ['from' => 'scammer@evil.com'],
                'body_text' => 'Hello victim',
                'ts_msg' => '2025-01-15T10:30:00+00:00',
            ],
        ];

        $formatted = $method->invoke($this->builder, $messages);

        $this->assertStringContainsString('Attacker', $formatted);
        $this->assertStringContainsString('scammer@evil.com', $formatted);
        $this->assertStringContainsString('Hello victim', $formatted);
        $this->assertStringContainsString('2025-01-15', $formatted);
    }

    /**
     * @test
     */
    public function it_includes_persona_information_in_generator_prompts(): void
    {
        $context = [
            'scam_type' => ['label_fr' => 'Phishing'],
            'last_messages' => [],
        ];

        $prompts = $this->builder->buildGeneratorPrompts($context, 'elderly_person');

        // Should include scam type information
        $this->assertStringContainsString('Phishing', $prompts['user']);
        // Should include situation section
        $this->assertStringContainsString('## SITUATION', $prompts['user']);
    }

    /**
     * @test
     */
    public function it_loads_lonely_person_persona_from_database(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('loadPersona');
        $method->setAccessible(true);

        $persona = $method->invoke($this->builder, 'lonely_person');

        $this->assertIsArray($persona);
        $this->assertArrayHasKey('system_prompt', $persona);
        $this->assertArrayHasKey('persona_label', $persona);
        $this->assertArrayHasKey('persona_tone', $persona);
        $this->assertSame('lonely_person', $persona['persona_code']);
        $this->assertSame('Personne seule en quête d\'affection', $persona['persona_label']);
        $this->assertSame('Émotionnel, vulnérable, espérant une connexion', $persona['persona_tone']);
        $this->assertIsString($persona['system_prompt']);
        $this->assertNotEmpty($persona['system_prompt']);
    }

    /**
     * @test
     */
    public function it_loads_confused_user_persona_from_database(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('loadPersona');
        $method->setAccessible(true);

        $persona = $method->invoke($this->builder, 'confused_user');

        $this->assertIsArray($persona);
        $this->assertArrayHasKey('system_prompt', $persona);
        $this->assertArrayHasKey('persona_label', $persona);
        $this->assertArrayHasKey('persona_tone', $persona);
        $this->assertSame('confused_user', $persona['persona_code']);
        $this->assertSame('Utilisateur confus face à un problème technique', $persona['persona_label']);
        $this->assertSame('Anxieux, dépassé, cherchant de l\'aide', $persona['persona_tone']);
        $this->assertIsString($persona['system_prompt']);
        $this->assertNotEmpty($persona['system_prompt']);
    }

    /**
     * @test
     */
    public function it_loads_small_business_owner_persona_from_database(): void
    {
        $reflection = new \ReflectionClass($this->builder);
        $method = $reflection->getMethod('loadPersona');
        $method->setAccessible(true);

        $persona = $method->invoke($this->builder, 'small_business_owner');

        $this->assertIsArray($persona);
        $this->assertArrayHasKey('system_prompt', $persona);
        $this->assertArrayHasKey('persona_label', $persona);
        $this->assertArrayHasKey('persona_tone', $persona);
        $this->assertSame('small_business_owner', $persona['persona_code']);
        $this->assertSame('Propriétaire de petite entreprise', $persona['persona_label']);
        $this->assertSame('Professionnel, pressé, pragmatique', $persona['persona_tone']);
        $this->assertIsString($persona['system_prompt']);
        $this->assertNotEmpty($persona['system_prompt']);
    }

    /**
     * @test
     */
    public function it_builds_generator_prompts_with_lonely_person_persona(): void
    {
        $context = [
            'scam_type' => ['label_fr' => 'Romance scam'],
            'last_messages' => [],
        ];

        $prompts = $this->builder->buildGeneratorPrompts($context, 'lonely_person');

        $this->assertIsArray($prompts);
        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertStringContainsString('Romance scam', $prompts['user']);
        $this->assertIsString($prompts['system']);
        $this->assertNotEmpty($prompts['system']);
    }

    /**
     * @test
     */
    public function it_builds_generator_prompts_with_confused_user_persona(): void
    {
        $context = [
            'scam_type' => ['label_fr' => 'Support technique frauduleux'],
            'last_messages' => [],
        ];

        $prompts = $this->builder->buildGeneratorPrompts($context, 'confused_user');

        $this->assertIsArray($prompts);
        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertStringContainsString('Support technique frauduleux', $prompts['user']);
        $this->assertIsString($prompts['system']);
        $this->assertNotEmpty($prompts['system']);
    }

    /**
     * @test
     */
    public function it_builds_generator_prompts_with_small_business_owner_persona(): void
    {
        $context = [
            'scam_type' => ['label_fr' => 'Fausse facture'],
            'last_messages' => [],
        ];

        $prompts = $this->builder->buildGeneratorPrompts($context, 'small_business_owner');

        $this->assertIsArray($prompts);
        $this->assertArrayHasKey('system', $prompts);
        $this->assertArrayHasKey('user', $prompts);
        $this->assertStringContainsString('Fausse facture', $prompts['user']);
        $this->assertIsString($prompts['system']);
        $this->assertNotEmpty($prompts['system']);
    }

    // ================================================================== //
    //  Merged from PromptBuilderCoverageTest
    // ================================================================== //

    private function buildContext(array $messages): array
    {
        return [
            'conv_id' => 'test-conv-1',
            'status' => 'open',
            'scam_type' => ['code' => 'PHISHING', 'label' => 'Phishing'],
            'persona' => 'generic_user',
            'cadence' => ['min_hours_between_replies' => 6],
            'last_messages' => $messages,
            'extracted_iocs' => [],
            'sender_history_summary' => null,
        ];
    }

    public function testBuildWithLargeBodyTriggersCleanup(): void
    {
        // Body > 50KB triggers extractReadableText path
        $largeBody = str_repeat('Normal text. ', 5000); // ~65KB
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $largeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('user', $result);
        // Body should be truncated after cleaning
        $this->assertLessThan(15000, strlen($result['user']));
    }

    public function testBuildWithBase64ImageDataRemovesIt(): void
    {
        $bodyWithBase64 = 'Hello, here is my image: data:image/png;base64,' . str_repeat('ABCD', 50) . ' and more text.';
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $bodyWithBase64,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('[IMAGE REMOVED]', $result['user']);
    }

    public function testBuildWithLongBase64SequenceRemovesIt(): void
    {
        $bodyWithLongBase64 = 'Start ' . str_repeat('A', 150) . ' End';
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $bodyWithLongBase64,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('[BASE64 DATA REMOVED]', $result['user']);
    }

    public function testBuildWithMimeBoundaryRemovesIt(): void
    {
        $bodyWithMime = "Some text\n--boundary-1234567890abcdef0123\nContent-Type: text/plain\nMore text";
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $bodyWithMime,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // MIME boundary and Content-Type should be removed
        $this->assertStringNotContainsString('boundary-1234567890abcdef0123', $result['user']);
    }

    public function testBuildWithVeryLargeTextPlainMimePart(): void
    {
        // Over 50KB body with a text/plain MIME part triggers extractReadableText
        $mimeBody = "Content-Type: text/plain; charset=utf-8\r\n\r\nThis is the plain text part.\r\n--boundary\r\n";
        $mimeBody .= str_repeat('X', 50000);

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $mimeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('plain text part', $result['user']);
    }

    public function testBuildWithVeryLargeHtmlMimePart(): void
    {
        // Over 50KB body with only text/html part
        $htmlContent = '<p>This is <strong>HTML</strong> content.</p>';
        $mimeBody = "Content-Type: text/html; charset=utf-8\r\n\r\n{$htmlContent}\r\n--boundary\r\n";
        $mimeBody .= str_repeat('Y', 50000);

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $mimeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // HTML tags should be stripped and text preserved
        $this->assertStringContainsString('HTML', $result['user']);
    }

    public function testBuildWithVeryLargeBinaryMimeFallsBackToTruncation(): void
    {
        // Over 50KB body with no text/plain or text/html part
        $mimeBody = "Content-Type: application/octet-stream\r\n\r\n" . str_repeat('Z', 50001);

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $mimeBody,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // Should fall back to truncated substring
        $this->assertStringContainsString('complex MIME content', $result['user']);
    }

    public function testBuildWithBodyExceeding10KAfterClean(): void
    {
        // Body that is large after cleaning but not MIME (so no MIME extraction)
        $body = str_repeat('word ', 2500); // ~12.5KB of clean text

        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => $body,
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        // Should be truncated to 10KB with truncation notice
        $this->assertStringContainsString('message truncated', $result['user']);
    }

    public function testBuildWithEmptyMessages(): void
    {
        $context = $this->buildContext([]);

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('first exchange', $result['user']);
    }

    public function testBuildWithSenderHistorySummary(): void
    {
        $context = $this->buildContext([
            [
                'direction' => 'in',
                'body_text' => 'Hello target',
                'headers' => ['from' => 'scammer@evil.test'],
                'ts_msg' => '2026-01-01T00:00:00+00:00',
            ],
        ]);
        $context['sender_history_summary'] = 'This sender has been seen in 3 prior conversations.';

        $result = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('Prior exchanges', $result['user']);
        $this->assertStringContainsString('3 prior conversations', $result['user']);
    }

    // -----------------------------------------------------------------------
    // scam-type-aware OBJECTIVE templates (per-bucket at payment_push)
    //
    // Stage detection in ContextAnalyzer falls back to a count heuristic:
    // ≤2 messages = first_contact, ≤5 = follow_up, >5 = payment_push.
    // The helpers below build contexts at each stage and feed a given scam code.
    // -----------------------------------------------------------------------

    /**
     * Build a payment_push-stage context (≥6 messages) carrying a specific scam_type code.
     *
     * @return array<string, mixed>
     */
    private function buildPaymentPushContext(string $scamCode): array
    {
        $messages = [];
        for ($i = 0; $i < 6; $i++) {
            $messages[] = [
                'direction' => $i % 2 === 0 ? 'in' : 'out',
                'headers' => ['from' => $i % 2 === 0 ? 'scammer@evil.test' : 'victim@example.com'],
                'body_text' => "Message {$i}",
                'ts_msg' => '2026-01-01T10:0' . $i . ':00+00:00',
            ];
        }

        return [
            'scam_type' => ['code' => $scamCode, 'label_fr' => "Test {$scamCode}"],
            'last_messages' => $messages,
        ];
    }

    /**
     * Build a first_contact-stage context (≤2 messages) carrying a specific scam_type code.
     *
     * @return array<string, mixed>
     */
    private function buildFirstContactContext(string $scamCode): array
    {
        return [
            'scam_type' => ['code' => $scamCode, 'label_fr' => "Test {$scamCode}"],
            'last_messages' => [
                ['direction' => 'in', 'headers' => ['from' => 'scammer@evil.test'], 'body_text' => 'Hello', 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
        ];
    }

    /**
     * Build a follow_up-stage context (3-5 messages) carrying a specific scam_type code.
     *
     * @return array<string, mixed>
     */
    private function buildFollowUpContext(string $scamCode): array
    {
        $messages = [];
        for ($i = 0; $i < 4; $i++) {
            $messages[] = [
                'direction' => $i % 2 === 0 ? 'in' : 'out',
                'headers' => ['from' => $i % 2 === 0 ? 'scammer@evil.test' : 'victim@example.com'],
                'body_text' => "Message {$i}",
                'ts_msg' => '2026-01-01T10:0' . $i . ':00+00:00',
            ];
        }

        return [
            'scam_type' => ['code' => $scamCode, 'label_fr' => "Test {$scamCode}"],
            'last_messages' => $messages,
        ];
    }

    /**
     * Banking bucket: ADVANCE_FEE_419, CEO_FRAUD, INVOICE_FRAUD,
     * INVESTMENT, JOB_OFFER all route to the banking template at payment_push,
     * which mentions IBAN / SWIFT-BIC / wallet for crypto / phone.
     *
     * @dataProvider bankingBucketCodesProvider
     */
    public function test_uses_banking_template_for_banking_codes_at_payment_push(string $scamCode): void
    {
        $context = $this->buildPaymentPushContext($scamCode);

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('payment push', $prompts['user']);
        $this->assertStringContainsString('IBAN', $prompts['user']);
        $this->assertStringContainsString('SWIFT/BIC', $prompts['user']);
        $this->assertStringNotContainsString('Western Union', $prompts['user'], "Banking template must not reference Western Union for code {$scamCode}");
        $this->assertStringNotContainsString('gift card', $prompts['user'], "Banking template must not reference gift card for code {$scamCode}");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function bankingBucketCodesProvider(): array
    {
        return [
            'ADVANCE_FEE_419' => ['ADVANCE_FEE_419'],
            'CEO_FRAUD' => ['CEO_FRAUD'],
            'INVOICE_FRAUD' => ['INVOICE_FRAUD'],
            'INVESTMENT' => ['INVESTMENT'],
            'JOB_OFFER' => ['JOB_OFFER'],
        ];
    }

    public function test_uses_romance_template_for_ROMANCE_at_payment_push(): void
    {
        $context = $this->buildPaymentPushContext('ROMANCE');

        $prompts = $this->builder->buildGeneratorPrompts($context, 'lonely_person');

        $this->assertStringContainsString('romance-context', $prompts['user']);
        $this->assertStringContainsString('Western Union', $prompts['user']);
        $this->assertStringContainsString('MoneyGram', $prompts['user']);
        $this->assertStringContainsString('gift cards', $prompts['user']);
        $this->assertStringContainsString('wallet address', $prompts['user']);
    }

    public function test_uses_lottery_template_for_LOTTERY_at_payment_push(): void
    {
        $context = $this->buildPaymentPushContext('LOTTERY');

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('fee released before the prize', $prompts['user']);
        $this->assertStringContainsString('fee processing method', $prompts['user']);
        $this->assertStringContainsString('gift card', $prompts['user']);
        $this->assertStringContainsString('prepaid card', $prompts['user']);
    }

    public function test_uses_charity_template_for_CHARITY_at_payment_push(): void
    {
        $context = $this->buildPaymentPushContext('CHARITY');

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('charity', $prompts['user']);
        $this->assertStringContainsString('registration number', $prompts['user']);
        $this->assertStringContainsString('donation page URL', $prompts['user']);
    }

    public function test_uses_tech_support_template_for_TECH_SUPPORT_at_payment_push(): void
    {
        $context = $this->buildPaymentPushContext('TECH_SUPPORT');

        $prompts = $this->builder->buildGeneratorPrompts($context, 'confused_user');

        $this->assertStringContainsString('tech-support context', $prompts['user']);
        $this->assertStringContainsString('company name on the invoice', $prompts['user']);
        $this->assertStringContainsString('callback phone number', $prompts['user']);
        $this->assertStringContainsString('remote-access tool', $prompts['user']);
    }

    /**
     * @dataProvider phishingBucketCodesProvider
     */
    public function test_uses_phishing_template_for_PHISH_codes_at_payment_push(string $scamCode): void
    {
        $context = $this->buildPaymentPushContext($scamCode);

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('phishing-style request', $prompts['user'], "Phishing template missing for {$scamCode}");
        $this->assertStringContainsString('which exact site', $prompts['user']);
        $this->assertStringContainsString('who hosts it', $prompts['user']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function phishingBucketCodesProvider(): array
    {
        return [
            'PHISH_CREDENTIALS' => ['PHISH_CREDENTIALS'],
            'PHISH_MALWARE' => ['PHISH_MALWARE'],
            'PHISHING' => ['PHISHING'],
        ];
    }

    public function test_falls_back_to_banking_for_UNKNOWN_at_payment_push(): void
    {
        $context = $this->buildPaymentPushContext('UNKNOWN');

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('IBAN', $prompts['user']);
        $this->assertStringContainsString('SWIFT/BIC', $prompts['user']);
        $this->assertStringNotContainsString('Western Union', $prompts['user']);
    }

    public function test_falls_back_to_banking_for_unmapped_new_code_at_payment_push(): void
    {
        $context = $this->buildPaymentPushContext('NEW_SCAM_TYPE_XYZ');

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $this->assertStringContainsString('IBAN', $prompts['user']);
        $this->assertStringContainsString('SWIFT/BIC', $prompts['user']);
    }

    public function test_keeps_first_contact_template_unchanged_regardless_of_scam_type(): void
    {
        $expected = "Stage: first contact. Express plausible interest. Ask ONE specific question about the offer itself (timeline, who you are dealing with, why you were chosen). Hold off on payment specifics until the attacker raises them.\n";

        foreach (['ROMANCE', 'CHARITY', 'CEO_FRAUD', 'PHISH_MALWARE'] as $code) {
            $context = $this->buildFirstContactContext($code);
            $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

            $this->assertStringContainsString($expected, $prompts['user'], "first_contact template must not vary by scam_type (failed for {$code})");
            $this->assertStringNotContainsString('Western Union', $prompts['user']);
            $this->assertStringNotContainsString('payment push', $prompts['user']);
        }
    }

    public function test_follow_up_template_keeps_money_ask_when_payment_topic_anchored(): void
    {
        $expected = "Stage: follow-up. The relationship is forming. Ask ONE practical question (when you need to act, the best way to contact you, where exactly the money will go).\n";

        foreach (['ROMANCE', 'CHARITY', 'CEO_FRAUD', 'TECH_SUPPORT'] as $code) {
            $context = $this->buildFollowUpContext($code);
            $context['payment_topic_anchored'] = true;
            $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

            $this->assertStringContainsString($expected, $prompts['user'], "anchored follow_up template must not vary by scam_type (failed for {$code})");
            $this->assertStringNotContainsString('Western Union', $prompts['user']);
            $this->assertStringNotContainsString('payment push', $prompts['user']);
        }
    }

    /**
     * When the scammer has never raised the payment topic
     * (payment_topic_anchored absent or false — absent fails closed), the
     * follow_up objective must not instruct the persona to ask about
     * money: that instruction is exactly what PaymentInstigationGuard
     * then blocks, and the contradiction shipped deterministic fallbacks.
     */
    public function test_follow_up_template_has_no_money_ask_without_anchoring(): void
    {
        foreach ([null, false] as $anchored) {
            $context = $this->buildFollowUpContext('UNKNOWN');

            if ($anchored !== null) {
                $context['payment_topic_anchored'] = $anchored;
            }
            $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

            $this->assertStringContainsString('Stage: follow-up.', $prompts['user']);
            $this->assertStringNotContainsString('where exactly the money will go', $prompts['user']);
            $this->assertStringContainsString('Do NOT bring up money', $prompts['user'], 'Unanchored follow_up must explicitly forbid introducing money topics');
        }
    }

    public function test_payment_push_templates_ignore_anchoring_flag(): void
    {
        // FR-regression pin: payment_push objectives are byte-identical
        // whether or not the anchoring flag is set — the stage itself
        // means money is on the table, extraction guidance must not
        // weaken.
        foreach ([true, false] as $anchored) {
            $context = $this->buildPaymentPushContext('UNKNOWN');
            $context['payment_topic_anchored'] = $anchored;
            $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

            $this->assertStringContainsString('SWIFT/BIC', $prompts['user'], 'payment_push banking template must keep its extraction guidance');
        }
    }

    /**
     * The coherence guardrail: every payment_push template MUST end with an
     * "ask HOW" or "ask WHAT" fallback that explicitly tells the persona not
     * to pre-suppose a payment method. This is the hard regression guard
     * preventing future contributors from removing the open-fallback line.
     *
     * @dataProvider allBucketScamCodesProvider
     */
    public function test_every_payment_push_template_contains_coherence_fallback(string $scamCode): void
    {
        $context = $this->buildPaymentPushContext($scamCode);

        $prompts = $this->builder->buildGeneratorPrompts($context, 'generic_user');

        $hasAskHow = str_contains($prompts['user'], 'ask HOW');
        $hasAskWhat = str_contains($prompts['user'], 'ask WHAT');

        $this->assertTrue(
            $hasAskHow || $hasAskWhat,
            "Coherence fallback (ask HOW / ask WHAT) missing in payment_push template for {$scamCode}"
        );
        $this->assertStringContainsString(
            'do not pre-suppose any method',
            $prompts['user'],
            "Coherence fallback wording 'do not pre-suppose any method' missing for {$scamCode}"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allBucketScamCodesProvider(): array
    {
        return [
            'ROMANCE (romance)' => ['ROMANCE'],
            'LOTTERY (lottery_fee)' => ['LOTTERY'],
            'CHARITY (charity)' => ['CHARITY'],
            'TECH_SUPPORT (tech_support)' => ['TECH_SUPPORT'],
            'PHISH_CREDENTIALS (phishing_pull)' => ['PHISH_CREDENTIALS'],
            'PHISH_MALWARE (phishing_pull)' => ['PHISH_MALWARE'],
            'PHISHING (phishing_pull)' => ['PHISHING'],
            'CEO_FRAUD (banking)' => ['CEO_FRAUD'],
            'UNKNOWN (banking default)' => ['UNKNOWN'],
            'NEW_UNMAPPED_CODE (banking default)' => ['NEW_UNMAPPED_CODE'],
        ];
    }
}
