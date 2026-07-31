<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin orchestration facade for reply generation.
 *
 * The heavy lifting (3-attempt retry loop, stage sequencing, fallback
 * logic) lives in RetryCoordinator. This class is the stable public
 * contract that callers depend on — a constructor + a single delegate call.
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
        PaymentInstigationGuard $paymentInstigationGuard,
        int $iocThreshold = 60,
        ?FallbackProvider $fallbackProvider = null,
        ?CostEstimator $costEstimator = null,
        ?OperationalLeakageDetector $leakDetector = null,
        ?\App\Application\Audit\AuditLogger $auditLogger = null,
        string $model = 'gpt-4o',
        ?SignatureStripper $signatureStripper = null,
    ) {
        $this->coordinator = new RetryCoordinator(
            llmClient: $llmClient,
            promptBuilder: $promptBuilder,
            policyGuard: $policyGuard,
            replyValidator: $replyValidator,
            iocScorer: $iocScorer,
            logger: $logger,
            paymentInstigationGuard: $paymentInstigationGuard,
            iocThreshold: $iocThreshold,
            fallbackProvider: $fallbackProvider,
            costEstimator: $costEstimator,
            leakDetector: $leakDetector,
            auditLogger: $auditLogger,
            model: $model,
            signatureStripper: $signatureStripper,
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
