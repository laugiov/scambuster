<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\ContextAnalyzer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContextAnalyzer
 *
 * Tests intelligent analysis of conversation context including:
 * - Stage detection (first_contact, follow_up, payment_push)
 * - IOC extraction (phone, url, iban, whatsapp, crypto, email)
 * - Missing IOC identification
 * - Target channel detection
 * - Tone analysis (calm, urgent)
 * - Promise extraction
 */
class ContextAnalyzerTest extends TestCase
{
    private ContextAnalyzer $analyzer;

    protected function setUp(): void
    {
        $this->analyzer = new ContextAnalyzer();
    }

    /**
     * @test
     */
    public function it_detects_first_contact_stage(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Bonjour, votre compte bancaire a été compromis.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('first_contact', $analysis['stage']);
    }

    /**
     * @test
     */
    public function it_detects_follow_up_stage(): void
    {
        $messages = $this->createMessages(4, [
            ['direction' => 'in', 'body_text' => 'Message 1'],
            ['direction' => 'out', 'body_text' => 'Réponse 1'],
            ['direction' => 'in', 'body_text' => 'Message 2'],
            ['direction' => 'out', 'body_text' => 'Réponse 2'],
        ]);

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('follow_up', $analysis['stage']);
    }

    /**
     * @test
     */
    public function it_detects_payment_push_stage_by_keywords(): void
    {
        $messages = $this->createMessages(3, [
            ['direction' => 'in', 'body_text' => 'Message 1'],
            ['direction' => 'out', 'body_text' => 'Réponse 1'],
            ['direction' => 'in', 'body_text' => 'Envoyez-moi 500 euros par virement bancaire maintenant.'],
        ]);

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('payment_push', $analysis['stage']);
    }

    /**
     * @test
     */
    public function it_detects_payment_push_stage_by_message_count(): void
    {
        $messages = $this->createMessages(7, []);

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('payment_push', $analysis['stage']);
    }

    /**
     * @test
     */
    public function it_extracts_phone_ioc(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Appelez-moi au 06 12 34 56 78 pour confirmer.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertCount(1, $analysis['iocs_obtained']);
        $this->assertEquals('phone', $analysis['iocs_obtained'][0]['type']);
        $this->assertEquals('0612345678', $analysis['iocs_obtained'][0]['value']);
    }

    /**
     * @test
     */
    public function it_extracts_url_ioc(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Visitez https://secure-bank-verification.com pour valider.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertCount(1, $analysis['iocs_obtained']);
        $this->assertEquals('url', $analysis['iocs_obtained'][0]['type']);
        $this->assertStringContainsString('secure-bank-verification.com', $analysis['iocs_obtained'][0]['value']);
    }

    /**
     * @test
     */
    public function it_extracts_iban_ioc(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Transférez sur le compte FR7612345678901234567890123.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertCount(1, $analysis['iocs_obtained']);
        $this->assertEquals('iban', $analysis['iocs_obtained'][0]['type']);
        $this->assertEquals('FR7612345678901234567890123', $analysis['iocs_obtained'][0]['value']);
    }

    /**
     * @test
     */
    public function it_extracts_whatsapp_mention(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Contactez-moi sur WhatsApp pour plus de détails.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertCount(1, $analysis['iocs_obtained']);
        $this->assertEquals('whatsapp', $analysis['iocs_obtained'][0]['type']);
    }

    /**
     * @test
     */
    public function it_extracts_multiple_iocs(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Appelez-moi au 0612345678 ou visitez https://evil.com',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertCount(2, $analysis['iocs_obtained']);
        $types = array_column($analysis['iocs_obtained'], 'type');
        $this->assertContains('phone', $types);
        $this->assertContains('url', $types);
    }

    /**
     * @test
     */
    public function it_ignores_iocs_from_victim_messages(): void
    {
        $messages = [
            [
                'direction' => 'out',
                'body_text' => 'Mon numéro est 0612345678.', // Should be ignored
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
            [
                'direction' => 'in',
                'body_text' => 'Merci.',
                'ts_msg' => '2025-01-01T10:01:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEmpty($analysis['iocs_obtained']);
    }

    /**
     * @test
     */
    public function it_identifies_missing_iocs(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Appelez-moi au 0612345678.', // phone obtained
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $missing = $analysis['missing_iocs'];

        $this->assertNotContains('phone', $missing); // phone obtained
        $this->assertContains('url', $missing);
        $this->assertContains('iban', $missing);
        $this->assertContains('whatsapp', $missing);
    }

    /**
     * @test
     */
    public function it_detects_target_channel_from_keywords(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Envoyez-moi un message sur WhatsApp.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('whatsapp', $analysis['canal_cible']);
    }

    /**
     * @test
     */
    public function it_detects_iban_target_channel_from_virement(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Faites un virement pour confirmer.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('iban', $analysis['canal_cible']);
    }

    /**
     * @test
     */
    public function it_prioritizes_missing_iocs_for_target_channel(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Message sans keyword spécifique.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        // Should default to 'phone' as highest priority missing IOC
        $this->assertEquals('phone', $analysis['canal_cible']);
    }

    /**
     * @test
     */
    public function it_detects_calm_tone(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Bonjour, pouvez-vous me confirmer vos coordonnées ?',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('calm', $analysis['risk_tone']);
    }

    /**
     * @test
     */
    public function it_detects_pressured_tone_from_keywords(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'C\'est URGENT ! Répondez immédiatement !',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('urgent', $analysis['risk_tone']);
    }

    /**
     * @test
     */
    public function it_detects_pressured_tone_from_exclamations(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Votre compte est bloqué! Agissez maintenant!',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('urgent', $analysis['risk_tone']);
    }

    /**
     * @test
     */
    public function it_detects_pressured_tone_from_uppercase(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'ATTENTION votre compte sera fermé',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('urgent', $analysis['risk_tone']);
    }

    /**
     * @test
     */
    public function it_extracts_promises_from_victim(): void
    {
        $messages = [
            [
                'direction' => 'out',
                'body_text' => 'Je vais vérifier mes documents et je vous envoie ça.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertNotEmpty($analysis['promises_made']);
        $this->assertStringContainsString('je vais', strtolower($analysis['promises_made'][0]));
    }

    /**
     * @test
     */
    public function it_extracts_availability_promises(): void
    {
        $messages = [
            [
                'direction' => 'out',
                'body_text' => 'Je suis disponible à partir de 18h ce soir.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertNotEmpty($analysis['promises_made']);
        $this->assertStringContainsString('disponible', strtolower($analysis['promises_made'][0]));
    }

    /**
     * @test
     */
    public function it_ignores_promises_from_attacker(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Je vais vous envoyer le lien.', // Should be ignored
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEmpty($analysis['promises_made']);
    }

    /**
     * @test
     */
    public function it_deduplicates_iocs(): void
    {
        $messages = [
            [
                'direction' => 'in',
                'body_text' => 'Appelez 0612345678 ou SMS au 0612345678.',
                'ts_msg' => '2025-01-01T10:00:00+00:00',
                'headers' => [],
            ],
        ];

        $analysis = $this->analyzer->analyzeConversation($messages);

        // Should have only 1 IOC despite phone appearing twice
        $this->assertCount(1, $analysis['iocs_obtained']);
    }

    /**
     * @test
     */
    public function it_handles_empty_messages_array(): void
    {
        $messages = [];

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals('first_contact', $analysis['stage']);
        $this->assertEmpty($analysis['iocs_obtained']);
        $this->assertNotEmpty($analysis['missing_iocs']);
        $this->assertEquals('phone', $analysis['canal_cible']);
        $this->assertEquals('calm', $analysis['risk_tone']);
        $this->assertEmpty($analysis['promises_made']);
        $this->assertEquals(0, $analysis['message_count']);
    }

    /**
     * @test
     */
    public function it_includes_message_count(): void
    {
        $messages = $this->createMessages(5, []);

        $analysis = $this->analyzer->analyzeConversation($messages);

        $this->assertEquals(5, $analysis['message_count']);
    }

    /**
     * Helper: Create test messages
     *
     * @param int $count
     * @param array<int, array{direction: string, body_text: string}> $overrides
     * @return array<int, array{direction: string, body_text: string, ts_msg: string, headers: array<string, mixed>}>
     */
    private function createMessages(int $count, array $overrides = []): array
    {
        $messages = [];

        for ($i = 0; $i < $count; $i++) {
            $messages[] = [
                'direction' => $overrides[$i]['direction'] ?? ($i % 2 === 0 ? 'in' : 'out'),
                'body_text' => $overrides[$i]['body_text'] ?? "Message de test {$i}",
                'ts_msg' => sprintf('2025-01-01T10:%02d:00+00:00', $i),
                'headers' => [],
            ];
        }

        return $messages;
    }
}
