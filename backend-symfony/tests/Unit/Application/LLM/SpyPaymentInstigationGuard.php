<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\LLM\PaymentInstigationGuard;
use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\Generator\Generator;
use Psr\Log\NullLogger;

/**
 * Spy stub for the reply-pipeline anchoring integration tests: records
 * how many times each entry point is called and returns configured
 * verdicts without touching the DB or an LLM.
 *
 * The parent is a readonly class, so mutable call counters live inside
 * a readonly-held ArrayObject.
 */
final readonly class SpyPaymentInstigationGuard extends PaymentInstigationGuard
{
    /** @var \ArrayObject<string, int> */
    public \ArrayObject $calls;

    public function __construct(
        private bool $anchored,
        private bool $approveChecks,
    ) {
        $generator = new Generator();
        /** @var EntityManagerInterface $em */
        $em = $generator->testDouble(EntityManagerInterface::class, true);
        /** @var LLMClientInterface $llm */
        $llm = $generator->testDouble(LLMClientInterface::class, true);
        parent::__construct($em, $llm, new NullLogger());
        $this->calls = new \ArrayObject(['check' => 0, 'anchored' => 0]);
    }

    public function check(string $outboundText, string $convId): array
    {
        $this->calls['check'] = ((int) $this->calls['check']) + 1;

        return $this->approveChecks
            ? ['approved' => true, 'reason' => null]
            : ['approved' => false, 'reason' => 'payment_instigation_blocked'];
    }

    public function isPaymentTopicAnchored(string $convId): bool
    {
        $this->calls['anchored'] = ((int) $this->calls['anchored']) + 1;

        return $this->anchored;
    }
}
