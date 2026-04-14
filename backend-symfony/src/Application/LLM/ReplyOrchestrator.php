<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Spec 065h — Thin orchestration facade for reply generation.
 *
 * The heavy lifting (3-attempt retry loop, stage sequencing, fallback
 * logic) lives in RetryCoordinator. This class is the stable public
 * contract that callers depend on.
 *
 * Before spec 065h, this class was 656 lines. After extraction, it is
 * a constructor + a single delegate call.
 */
final readonly class ReplyOrchestrator
{
    private RetryCoordinator $coordinator;

    public function __construct(
        LLMClientInterface $llmClient,
        PromptBuilder $promptBuilder,
        PolicyGuard $policyGuard,
        ReplyValidator $replyValidator,
        IOCLikelihoodScorer $iocScorer,
        LoggerInterface $logger,
        int $iocThreshold = 60,
        ?FallbackProvider $fallbackProvider = null,
        ?CostEstimator $costEstimator = null,
        ?OperationalLeakageDetector $leakDetector = null,
        ?\App\Application\Audit\AuditLogger $auditLogger = null,
    ) {
        $this->coordinator = new RetryCoordinator(
            $llmClient,
            $promptBuilder,
            $policyGuard,
            $replyValidator,
            $iocScorer,
            $logger,
            $iocThreshold,
            $fallbackProvider,
            $costEstimator,
            $leakDetector,
            $auditLogger,
        );
    }

    /**
     * Generate and validate a reply with iterative refinement and IOC scoring.
     *
     * @param array<string, mixed> $context     Conversation context
     * @param string               $personaCode Persona code
     *
     * @return array<string, mixed>
     */
    public function generate(array $context, string $personaCode): array
    {
        return $this->coordinator->execute($context, $personaCode);
    }
}
