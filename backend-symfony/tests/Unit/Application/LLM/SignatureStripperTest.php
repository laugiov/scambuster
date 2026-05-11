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

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function englishSignoffsProvider(): iterable
    {
        // Each case: [input_with_signature, expected_text_after_strip].
        // Format: "Body.\n\n{Signoff},\n{Name}" → "Body.\n" (preserve a single
        // trailing newline from the body, remove the signature block).
        $body = 'Hello, please send me the IBAN to proceed.';
        $expected = $body . "\n";

        yield 'Best'                  => [$body . "\n\nBest,\nJohn",                  $expected];
        yield 'Best regards'          => [$body . "\n\nBest regards,\nJohn Smith",    $expected];
        yield 'Best wishes'           => [$body . "\n\nBest wishes,\nMary",           $expected];
        yield 'Kind regards'          => [$body . "\n\nKind regards,\nPaul",          $expected];
        yield 'Regards'               => [$body . "\n\nRegards,\nLisa",               $expected];
        yield 'Warm regards'          => [$body . "\n\nWarm regards,\nDave",          $expected];
        yield 'Warmly'                => [$body . "\n\nWarmly,\nEmma",                $expected];
        yield 'Warm wishes'           => [$body . "\n\nWarm wishes,\nTom",            $expected];
        yield 'Sincerely'             => [$body . "\n\nSincerely,\nAnna",             $expected];
        yield 'Cordially'             => [$body . "\n\nCordially,\nBen",              $expected];
        yield 'Cheers'                => [$body . "\n\nCheers,\nMike",                $expected];
        yield 'Thanks'                => [$body . "\n\nThanks,\nSarah",               $expected];
        yield 'Thank you'             => [$body . "\n\nThank you,\nDavid",            $expected];
        yield 'Many thanks'           => [$body . "\n\nMany thanks,\nKate",           $expected];
        yield 'All the best'          => [$body . "\n\nAll the best,\nChris",         $expected];
        yield 'Yours truly'           => [$body . "\n\nYours truly,\nSophia",         $expected];
        yield 'Yours sincerely'       => [$body . "\n\nYours sincerely,\nWilliam",    $expected];
        yield 'Yours faithfully'      => [$body . "\n\nYours faithfully,\nElizabeth", $expected];
    }

    /**
     * @dataProvider englishSignoffsProvider
     */
    public function test_strips_english_signoff_block(string $input, string $expectedTextAfter): void
    {
        $result = $this->newStripper()->strip($input, 'conv-1');

        self::assertSame($expectedTextAfter, $result->textAfter);
        self::assertSame(
            \strlen($input) - \strlen($expectedTextAfter),
            $result->bytesRemoved,
            'bytesRemoved must equal the difference in length between input and output',
        );
        self::assertNotEmpty(
            $result->matchedPatterns,
            'matchedPatterns must record at least one regex identifier when a strip occurred',
        );
    }
}
