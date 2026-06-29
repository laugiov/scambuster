<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PaymentInstigationGuard;
use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Psr\Log\NullLogger;

/**
 * Stub that bypasses the real LLM judge in unit tests for the reply
 * pipeline. Returns approved=true for every check().
 */
final readonly class AlwaysApprovePaymentInstigationGuard extends PaymentInstigationGuard
{
    public function __construct()
    {
        $generator = new Generator();
        /** @var EntityManagerInterface $em */
        $em = $generator->testDouble(EntityManagerInterface::class, true);
        /** @var LLMClientInterface $llm */
        $llm = $generator->testDouble(LLMClientInterface::class, true);
        parent::__construct($em, $llm, new NullLogger());
    }

    public function check(string $outboundText, string $convId): array
    {
        return ['approved' => true, 'reason' => null];
    }
}
