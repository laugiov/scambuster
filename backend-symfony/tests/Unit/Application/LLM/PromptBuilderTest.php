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
}
