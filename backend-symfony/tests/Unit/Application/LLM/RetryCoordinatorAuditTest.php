<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\PersonaManager;
use App\Application\LLM\ContextAnalyzer;
use App\Application\LLM\CostEstimator;
use App\Application\LLM\FallbackProvider;
use App\Application\LLM\IOCLikelihoodScorer;
use App\Application\LLM\PolicyGuard;
use App\Application\LLM\Port\LLMClientInterface;
use App\Application\LLM\PromptBuilder;
use App\Application\LLM\ReciprocityManager;
use App\Application\LLM\ReplyValidator;
use App\Application\LLM\RetryCoordinator;
use App\Application\LLM\VariationProvider;
use App\Domain\Audit\AuditEventType;
use App\Domain\Audit\AuditLog;
use App\Infrastructure\Siem\Adapter\NullSiemExporter;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Verify RetryCoordinator emits REPLY_RETRY on
 * each gate rejection (and REPLY_REJECTED on each fallback exhaustion)
 * to give operators DB-queryable per-attempt observability.
 *
 * Uses a real AuditLogger with a mocked EntityManager as a spy
 * (canonical pattern, see BudgetThresholdNotifierTest).
 */
final class RetryCoordinatorAuditTest extends TestCase
{
    private LLMClientInterface&MockObject $llmClient;
    private PersonaManager&MockObject $personaManager;
    private NullLogger $logger;

    /** @var list<AuditLog> Captured audit_log entities emitted during the test */
    private array $emittedAuditLogs = [];

    protected function setUp(): void
    {
        $this->llmClient = $this->createMock(LLMClientInterface::class);
        $this->personaManager = $this->createMock(PersonaManager::class);
        $persona = $this->createMock(\App\Domain\Communication\Persona::class);
        $persona->method('isActive')->willReturn(true);
        $persona->method('getPersonaCode')->willReturn('generic_user');
        $persona->method('getPersonaLabel')->willReturn('Generic User');
        $persona->method('getPersonaTone')->willReturn('Friendly and curious');
        $persona->method('getSystemPrompt')->willReturn('You are a friendly person.');
        $this->personaManager->method('findByCode')->willReturn($persona);
        $this->logger = new NullLogger();
        $this->emittedAuditLogs = [];
    }

    private function createAuditLoggerSpy(): AuditLogger
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($entity): void {
            if ($entity instanceof AuditLog) {
                $this->emittedAuditLogs[] = $entity;
            }
        });

        return new AuditLogger($em, new NullLogger(), new \App\Tests\Support\Audit\NullRequestContext(), new NullSiemExporter());
    }

    private function createCoordinator(?AuditLogger $auditLogger = null, int $iocThreshold = 60): RetryCoordinator
    {
        $contextAnalyzer = new ContextAnalyzer($this->logger);
        $variationProvider = new VariationProvider();
        $reciprocityManager = new ReciprocityManager($this->logger);
        $promptBuilder = new PromptBuilder(
            $contextAnalyzer,
            $variationProvider,
            $reciprocityManager,
            $this->personaManager,
            $this->logger,
        );

        return new RetryCoordinator(
            llmClient: $this->llmClient,
            promptBuilder: $promptBuilder,
            policyGuard: new PolicyGuard($this->logger),
            replyValidator: new ReplyValidator($this->llmClient, $promptBuilder, $this->logger),
            iocScorer: new IOCLikelihoodScorer($this->logger),
            logger: $this->logger,
            paymentInstigationGuard: new AlwaysApprovePaymentInstigationGuard(),
            iocThreshold: $iocThreshold,
            fallbackProvider: new FallbackProvider(),
            costEstimator: new CostEstimator(),
            leakDetector: null,
            auditLogger: $auditLogger,
        );
    }

    private function baseContext(): array
    {
        return [
            'conv_id' => 'test-conv-fix13',
            'scam_type' => ['code' => 'PHISHING', 'label' => 'Phishing'],
            'last_messages' => [
                ['direction' => 'in', 'body_text' => 'Hello I have an urgent business proposal for you please reply immediately with your account number.', 'ts_msg' => '2026-01-01T10:00:00+00:00'],
            ],
            'detected_language' => 'en',
            'persona' => 'generic_user',
        ];
    }

    /**
     * Filter captured audit_log entries by event_type.
     *
     * @return list<AuditLog>
     */
    private function auditLogsOfType(AuditEventType $type): array
    {
        return array_values(array_filter(
            $this->emittedAuditLogs,
            static fn (AuditLog $log): bool => $log->getEventType() === $type->value,
        ));
    }

    // ====================================================================
    // REPLY_RETRY emissions
    // ====================================================================

    public function testReplyRetryEmittedWhenPolicyGuardRejectsAttempt1(): void
    {
        // Attempt 1: too-short reply (PolicyGuard min_words rejects). Attempt 2: valid.
        $tooShort = 'Short.';
        $validReply = 'Oh my, that sounds really interesting and I have been looking for exactly this kind of opportunity for weeks now. '
            . 'Could you please tell me more about how this works and what the timeline looks like? I would love to hear the details. '
            . 'My friend told me about something similar last week and now I am very curious to learn how to participate properly.';

        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $tooShort, $validReply): string {
            $callCount++;

            // Pattern: generator, validator-(skipped-on-fail), generator, validator
            if ($callCount === 1) {
                return $tooShort;
            }

            if ($callCount === 2) {
                return $validReply;
            }

            // Validator
            return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":3,"reasons":["OK"],"fix_suggestion":""}';
        });

        $audit = $this->createAuditLoggerSpy();
        $result = $this->createCoordinator($audit, iocThreshold: 0)->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
        $retries = $this->auditLogsOfType(AuditEventType::REPLY_RETRY);
        $this->assertGreaterThanOrEqual(1, count($retries), 'Expected ≥1 REPLY_RETRY emission for PolicyGuard rejection');
        $this->assertSame('policy_guard', $retries[0]->getDetails()['reason']);
        $this->assertSame(1, $retries[0]->getDetails()['attempt']);
    }

    public function testReplyRetryHelperPopulatesIocThresholdPayload(): void
    {
        // Lightweight smoke test that exercises the private helper signature
        // for the ioc_threshold reason via Reflection — the integration-level
        // assertion (IOCScorer < threshold → retry → audit row) is verified
        // end-to-end by the test_cases.sh harness, where the live IOCScorer
        // formula is exercised rather than a fragile mocked-text approximation.
        $audit = $this->createAuditLoggerSpy();
        $coordinator = $this->createCoordinator($audit, iocThreshold: 60);

        $ref = new \ReflectionClass($coordinator);
        $method = $ref->getMethod('emitReplyRetry');
        $method->setAccessible(true);
        $method->invoke($coordinator, 'conv-fix13-ioc', 'ioc_threshold', 1, 'generic_user', [
            'ioc_score' => 25,
            'threshold' => 60,
        ]);

        $retries = $this->auditLogsOfType(AuditEventType::REPLY_RETRY);
        $this->assertCount(1, $retries);
        $details = $retries[0]->getDetails();
        $this->assertSame('ioc_threshold', $details['reason']);
        $this->assertSame(25, $details['ioc_score']);
        $this->assertSame(60, $details['threshold']);
        $this->assertSame(1, $details['attempt']);
    }

    public function testReplyRetryEmittedWhenValidatorRejects(): void
    {
        // All-good generator output, but validator first rejects then approves.
        $validReply = 'Oh my, that sounds really interesting and I have been looking for exactly this kind of opportunity for weeks now. '
            . 'Could you please tell me more about how this works and what the timeline looks like? I would love to hear the details. '
            . 'My friend told me about something similar last week and now I am very curious to learn how to participate properly.';

        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validReply): string {
            $callCount++;

            // Pattern: gen1, validator-rejects, gen2, validator-approves
            if ($callCount === 1) {
                return $validReply;
            }

            if ($callCount === 2) {
                return '{"approved":false,"naturalness":2,"persona_fit":3,"ti_value":2,"reasons":["too generic"],"fix_suggestion":"ask a specific question"}';
            }

            if ($callCount === 3) {
                return $validReply;
            }

            return '{"approved":true,"naturalness":4,"persona_fit":4,"ti_value":4,"reasons":["OK"],"fix_suggestion":""}';
        });

        $audit = $this->createAuditLoggerSpy();
        $this->createCoordinator($audit, iocThreshold: 0)->execute($this->baseContext(), 'generic_user');

        $retries = $this->auditLogsOfType(AuditEventType::REPLY_RETRY);
        $validatorRetries = array_values(array_filter(
            $retries,
            static fn (AuditLog $log): bool => ($log->getDetails()['reason'] ?? null) === 'validator',
        ));
        $this->assertGreaterThanOrEqual(1, count($validatorRetries), 'Expected ≥1 REPLY_RETRY emission for validator gate');
    }

    // ====================================================================
    // REPLY_REJECTED emissions
    // ====================================================================

    public function testReplyRejectedEmittedWhenPolicyGuardExhausts(): void
    {
        // All 3 attempts produce a too-short reply → PolicyGuard rejects all → fallback.
        $tooShort = 'Short reply.';
        $this->llmClient->method('chat')->willReturnCallback(function () use ($tooShort): string {
            // Every generator call returns tooShort, validator never invoked
            return $tooShort;
        });

        $audit = $this->createAuditLoggerSpy();
        $result = $this->createCoordinator($audit, iocThreshold: 0)->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['fallback_used'] ?? false, 'Expected fallback after exhausting PolicyGuard');
        $rejections = $this->auditLogsOfType(AuditEventType::REPLY_REJECTED);
        $this->assertCount(1, $rejections, 'Expected exactly 1 REPLY_REJECTED emission on PolicyGuard exhaustion');
        $details = $rejections[0]->getDetails();
        $this->assertSame('policy_guard', $details['gate']);
        $this->assertSame(3, $details['attempts']);
    }

    // ====================================================================
    // Regression guard — clean first attempt emits nothing
    // ====================================================================

    public function testNoRejectedEmissionWhenReplyIsApproved(): void
    {
        // Regression guard: when the orchestrator returns an approved reply
        // (whether on attempt 1, 2 or 3), REPLY_REJECTED must NEVER fire.
        // REPLY_RETRY count is non-deterministic in unit context (PolicyGuard
        // sensitivity varies with text), so we only assert the no-fallback
        // invariant.
        $validReply = 'Oh my, that sounds really interesting and I have been looking for exactly this kind of opportunity for weeks now. '
            . 'Could you please tell me more about how this works and what the timeline looks like? I would love to hear the details. '
            . 'My friend told me about something similar last week and now I am very curious to learn how to participate properly.';

        $callCount = 0;
        $this->llmClient->method('chat')->willReturnCallback(function () use (&$callCount, $validReply): string {
            $callCount++;

            return $callCount % 2 === 1
                ? $validReply
                : '{"approved":true,"naturalness":5,"persona_fit":5,"ti_value":4,"reasons":["OK"],"fix_suggestion":""}';
        });

        $audit = $this->createAuditLoggerSpy();
        $result = $this->createCoordinator($audit, iocThreshold: 0)->execute($this->baseContext(), 'generic_user');

        $this->assertTrue($result['approved']);
        $this->assertCount(0, $this->auditLogsOfType(AuditEventType::REPLY_REJECTED), 'Approved reply must emit 0 REPLY_REJECTED rows');
    }
}
