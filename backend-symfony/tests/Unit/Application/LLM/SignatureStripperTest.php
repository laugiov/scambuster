<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\LLM;

use App\Application\Audit\AuditLogger;
use App\Application\Audit\Port\SiemExporterInterface;
use App\Application\LLM\SignatureStripper;
use App\Application\LLM\StripResult;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Spec 080 §1 — SignatureStripper unit tests.
 *
 * Built incrementally as a TDD sequence: each Red commit adds a behavior
 * expectation, each Green commit grows the regex pipeline. See
 * specs/080-validator-coherence-and-signature-strip/tasks.md T03.
 */
final class SignatureStripperTest extends TestCase
{
    private function newStripper(bool $enabled = true): SignatureStripper
    {
        // AuditLogger is final readonly — cannot be mocked. Build a real
        // instance with mocked EM + SiemExporter, following the pattern in
        // existing tests (e.g. TotpLoginControllerTest:setUp).
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createMock(Connection::class));
        $siemExporter = $this->createMock(SiemExporterInterface::class);

        $auditLogger = new AuditLogger(
            em: $em,
            logger: new NullLogger(),
            requestStack: new RequestStack(),
            siemExporter: $siemExporter,
        );

        return new SignatureStripper(
            signatureStripEnabled: $enabled,
            logger: new NullLogger(),
            auditLogger: $auditLogger,
        );
    }

    /**
     * Baseline: when the input contains no signature pattern of any kind,
     * the stripper must return the input unchanged with bytesRemoved=0 and
     * an empty matchedPatterns array.
     */
    public function test_returns_text_unchanged_when_no_signature_present(): void
    {
        $input = 'Hello world.';
        $result = $this->newStripper()->strip($input, 'conv-1');

        self::assertInstanceOf(StripResult::class, $result);
        self::assertSame($input, $result->textAfter);
        self::assertSame(0, $result->bytesRemoved);
        self::assertSame([], $result->matchedPatterns);
    }
}
