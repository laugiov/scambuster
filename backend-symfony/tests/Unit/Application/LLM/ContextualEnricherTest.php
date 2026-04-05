<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Communication\MessageAnonymizer;
use App\Application\LLM\ContextualEnricher;
use App\Application\LLM\ContextualEnrichmentRequest;
use App\Application\LLM\ContextualEnrichmentResult;
use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\LLM\Event\LlmCallCompletedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \App\Application\LLM\ContextualEnricher
 */
class ContextualEnricherTest extends TestCase
{
    private LLMClientInterface $llmClient;
    private MessageAnonymizer $anonymizer;
    private EventDispatcherInterface $dispatcher;
    private LoggerInterface $logger;
    private ContextualEnricher $enricher;

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->anonymizer = new MessageAnonymizer();
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->enricher = new ContextualEnricher(
            $this->llmClient,
            $this->anonymizer,
            $this->dispatcher,
            $this->logger,
        );
    }

    private function createRequest(array $iocTypes = ['url', 'iban']): ContextualEnrichmentRequest
    {
        return new ContextualEnrichmentRequest(
            iocTypes: $iocTypes,
            scamType: 'PHISHING',
            personaCode: 'bank_customer',
            revelationTurn: 3,
            totalTurns: 5,
            revelationMessageText: 'Please pay via https://evil.com or send to FR7630006000011234567890189',
            stimulusMessageText: 'I would like to proceed. What are the payment details?',
            previousInboundText: 'Dear customer, your account needs verification.',
        );
    }

    private function validLlmResponse(): string
    {
        return json_encode([
            'stimulus_type' => 'DIRECT_REQUEST',
            'scammer_urgency_score' => 0.75,
            'language_switch_detected' => false,
            'hesitation_detected' => false,
            'context_excerpt' => 'Scammer provided payment details after request',
            'enrichment_confidence' => 0.85,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PAYMENT_REDIRECT_URL'],
                ['type' => 'iban', 'role' => 'PAYMENT_DESTINATION'],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    public function testValidResponseReturnsResult(): void
    {
        $this->llmClient
            ->method('chat')
            ->willReturn($this->validLlmResponse());

        $result = $this->enricher->enrich($this->createRequest());

        $this->assertInstanceOf(ContextualEnrichmentResult::class, $result);
        $this->assertSame('DIRECT_REQUEST', $result->stimulusType);
        $this->assertSame(0.75, $result->urgencyScore);
        $this->assertSame('PAYMENT_REDIRECT_URL', $result->iocRoles['url']);
        $this->assertSame('PAYMENT_DESTINATION', $result->iocRoles['iban']);
    }

    public function testLlmTimeoutReturnsNull(): void
    {
        $this->llmClient
            ->method('chat')
            ->willThrowException(new \RuntimeException('Connection timeout'));

        $result = $this->enricher->enrich($this->createRequest());

        $this->assertNull($result);
    }

    public function testInvalidJsonReturnsNull(): void
    {
        $this->llmClient
            ->method('chat')
            ->willReturn('This is not JSON at all');

        $result = $this->enricher->enrich($this->createRequest());

        $this->assertNull($result);
    }

    public function testPiiInExcerptGetsRedacted(): void
    {
        $responseWithPii = json_encode([
            'stimulus_type' => 'DIRECT_REQUEST',
            'scammer_urgency_score' => 0.75,
            'language_switch_detected' => false,
            'hesitation_detected' => false,
            'context_excerpt' => 'Scammer sent IBAN FR7630006000011234567890189 in the message',
            'enrichment_confidence' => 0.85,
            'ioc_roles' => [
                ['type' => 'url', 'role' => 'PAYMENT_REDIRECT_URL'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->llmClient
            ->method('chat')
            ->willReturn($responseWithPii);

        $result = $this->enricher->enrich($this->createRequest(['url']));

        $this->assertNotNull($result);
        $this->assertSame('[PII detected - redacted]', $result->contextExcerpt);
    }

    public function testLlmCallCompletedEventDispatched(): void
    {
        $this->llmClient
            ->method('chat')
            ->willReturn($this->validLlmResponse());

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof LlmCallCompletedEvent
                    && $event->getPurpose() === 'contextual_enrichment';
            }));

        $this->enricher->enrich($this->createRequest());
    }

    public function testMarkdownWrappedJsonIsParsed(): void
    {
        $wrapped = "```json\n" . $this->validLlmResponse() . "\n```";

        $this->llmClient
            ->method('chat')
            ->willReturn($wrapped);

        $result = $this->enricher->enrich($this->createRequest());

        $this->assertInstanceOf(ContextualEnrichmentResult::class, $result);
        $this->assertSame('DIRECT_REQUEST', $result->stimulusType);
    }

    public function testMessageTextsAreAnonymized(): void
    {
        $this->llmClient
            ->expects($this->once())
            ->method('chat')
            ->with(
                $this->callback(function (array $messages) {
                    // The user prompt should not contain the raw IBAN
                    $userContent = $messages[1]['content'] ?? '';

                    return !str_contains($userContent, 'FR7630006000011234567890189');
                }),
                $this->anything()
            )
            ->willReturn($this->validLlmResponse());

        $this->enricher->enrich($this->createRequest());
    }
}
