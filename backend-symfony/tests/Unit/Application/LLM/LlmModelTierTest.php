<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Audit\ConversationQualityAuditor;
use App\Application\LLM\ConversationAnalyzer;
use App\Application\LLM\OperationalLeakageDetector;
use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Behaviour proof for the base/strong model tiering: a base-tier service passes
 * its injected base model to the call, a strong-tier service passes its injected
 * strong model — neither hardcodes an OpenAI slug, so the resolved model always
 * follows the configured provider.
 */
final class LlmModelTierTest extends TestCase
{
    public function testBaseTierServiceUsesInjectedBaseModel(): void
    {
        $client = new CapturingLlmClient('{"leak_detected": false, "reason": "none"}');
        $detector = new OperationalLeakageDetector($client, new NullLogger(), 'base-model-under-test');

        $detector->check('some reply text', 'PERSONA_A');

        self::assertNotNull($client->lastOptions);
        self::assertSame(
            'base-model-under-test',
            $client->lastOptions['model'] ?? null,
            'a base-tier service must use its injected %llm.model%, not a hardcoded slug',
        );
    }

    public function testStrongTierServiceUsesInjectedModel(): void
    {
        $client = new CapturingLlmClient(
            (string) json_encode([
                'analysis' => 'x',
                'repetitions_detected' => [],
                'strategic_suggestions' => [],
                'tone_recommendation' => 'neutral',
                'instructions_for_llm' => [],
            ]),
        );
        $analyzer = new ConversationAnalyzer($client, new NullLogger(), null, 'strong-model-under-test');

        $analyzer->analyzeAndGenerateInstructions([
            'conversation_id' => 'c1',
            'scam_type' => 'ADVANCE_FEE_419',
            'persona_code' => 'PERSONA_A',
            'all_messages' => [
                ['direction' => 'inbound', 'body_text' => 'hello', 'ts_msg' => '2026-01-01T00:00:00Z'],
                ['direction' => 'outbound', 'body_text' => 'hi there', 'ts_msg' => '2026-01-01T00:01:00Z'],
            ],
        ]);

        self::assertNotNull($client->lastOptions);
        self::assertSame(
            'strong-model-under-test',
            $client->lastOptions['model'] ?? null,
            'a strong-tier service must use its injected model, not a hardcoded slug',
        );
    }

    public function testQualityAuditorAcceptsInjectedModel(): void
    {
        // Construction contract: the auditor takes an injected strong model.
        $auditor = new ConversationQualityAuditor(
            new CapturingLlmClient('{}'),
            $this->createMock(Connection::class),
            new NullLogger(),
            'strong-model-under-test',
        );

        self::assertInstanceOf(ConversationQualityAuditor::class, $auditor);
    }
}

/**
 * Minimal capturing double for the LLM port.
 */
final class CapturingLlmClient implements LLMClientInterface
{
    /** @var array<string, mixed>|null */
    public ?array $lastOptions = null;

    public function __construct(private readonly string $response)
    {
    }

    public function chat(array $messages, array $options = []): string
    {
        $this->lastOptions = $options;

        return $this->response;
    }
}
