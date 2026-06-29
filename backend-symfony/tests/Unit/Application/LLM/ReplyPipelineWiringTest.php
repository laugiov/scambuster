<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PaymentInstigationGuard;
use App\Application\LLM\ReplyOrchestrator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Defensive regression test for the entire reply-validation pipeline.
 *
 * Classes under src/Application/LLM/ are EXCLUDED from autowire in
 * config/services.yaml (see line ~51, exclude pattern). Every new guard
 * or judge MUST be:
 *   1. registered explicitly in config/packages/llm.yaml
 *   2. passed as an argument to ReplyOrchestrator (or whoever creates
 *      the pipeline that needs it)
 *
 * When step 1 or 2 is forgotten, the guard class still compiles and
 * unit-tests pass in isolation, but ReplyOrchestrator silently
 * instantiates RetryCoordinator with null for that argument and the
 * guard is never invoked for any real reply. This is exactly what
 * happened to spec 116 PaymentInstigationGuard: shipped, smoke-tested,
 * never actually fired for ~3 days of production traffic until an
 * operator spotted the persona asking for SWIFT/BIC unprompted.
 *
 * This test guards against repeating the same pattern.
 *
 * Add an assertion to this test EVERY TIME a new validation/guard/judge
 * service is added to the reply pipeline.
 */
final class ReplyPipelineWiringTest extends KernelTestCase
{
    public function testPaymentInstigationGuardIsRegisteredInContainer(): void
    {
        $container = self::getContainer();

        $this->assertTrue(
            $container->has(PaymentInstigationGuard::class),
            'PaymentInstigationGuard must be registered in config/packages/llm.yaml. '
            . 'src/Application/LLM/ is excluded from autowire — explicit wiring required.'
        );

        $guard = $container->get(PaymentInstigationGuard::class);
        $this->assertInstanceOf(PaymentInstigationGuard::class, $guard);
    }

    public function testReplyOrchestratorReceivesThePaymentInstigationGuard(): void
    {
        $container = self::getContainer();

        $this->assertTrue($container->has(ReplyOrchestrator::class), 'ReplyOrchestrator must be in the container');

        // Reflection check: the orchestrator's internal RetryCoordinator
        // must hold the guard (not null). This catches the case where the
        // guard is registered in the container but the ReplyOrchestrator
        // constructor argument list forgot to pass it through.
        $orchestrator = $container->get(ReplyOrchestrator::class);

        $coordRef = new \ReflectionProperty($orchestrator, 'coordinator');
        $coord = $coordRef->getValue($orchestrator);
        $this->assertNotNull($coord, 'ReplyOrchestrator must have a RetryCoordinator');

        $guardRef = new \ReflectionProperty($coord, 'paymentInstigationGuard');
        $injectedGuard = $guardRef->getValue($coord);

        $this->assertInstanceOf(
            PaymentInstigationGuard::class,
            $injectedGuard,
            'RetryCoordinator received NULL for paymentInstigationGuard. '
            . 'Check ReplyOrchestrator::__construct passes $paymentInstigationGuard '
            . 'through to new RetryCoordinator(..., $paymentInstigationGuard).'
        );
    }
}
