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

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function multilingualSignoffsProvider(): iterable
    {
        // 22 cases across 6 non-English languages, matching the spec 080 §1
        // multilingual signoff list. Each case follows the same shape as the
        // English provider: localized body + blank line + signoff + name →
        // localized body + single trailing newline.

        // --- French (5) ---
        $bodyFr = 'Bonjour, merci de me transmettre votre IBAN pour finaliser.';
        $expFr = $bodyFr . "\n";
        yield 'FR: Cordialement'         => [$bodyFr . "\n\nCordialement,\nMartin",          $expFr];
        yield 'FR: Bien cordialement'    => [$bodyFr . "\n\nBien cordialement,\nSophie",     $expFr];
        yield 'FR: Salutations'          => [$bodyFr . "\n\nSalutations,\nPaul",             $expFr];
        yield 'FR: Bonne journée'        => [$bodyFr . "\n\nBonne journée,\nClaire",         $expFr];
        yield 'FR: Bien à vous'          => [$bodyFr . "\n\nBien à vous,\nThomas",           $expFr];

        // --- Spanish (4) ---
        $bodyEs = 'Hola, por favor envíame el IBAN para continuar.';
        $expEs = $bodyEs . "\n";
        yield 'ES: Saludos'              => [$bodyEs . "\n\nSaludos,\nPedro",                $expEs];
        yield 'ES: Cordialmente'         => [$bodyEs . "\n\nCordialmente,\nMaria",           $expEs];
        yield 'ES: Atentamente'          => [$bodyEs . "\n\nAtentamente,\nCarlos",           $expEs];
        yield 'ES: Un saludo'            => [$bodyEs . "\n\nUn saludo,\nLucia",              $expEs];

        // --- German (4) ---
        $bodyDe = 'Hallo, bitte senden Sie mir Ihre IBAN, um fortzufahren.';
        $expDe = $bodyDe . "\n";
        yield 'DE: Mit freundlichen Grüßen' => [$bodyDe . "\n\nMit freundlichen Grüßen,\nHans", $expDe];
        yield 'DE: Beste Grüße'             => [$bodyDe . "\n\nBeste Grüße,\nAnna",            $expDe];
        yield 'DE: Viele Grüße'             => [$bodyDe . "\n\nViele Grüße,\nKlaus",           $expDe];
        yield 'DE: Freundliche Grüße'       => [$bodyDe . "\n\nFreundliche Grüße,\nIngrid",    $expDe];

        // --- Italian (3) ---
        $bodyIt = 'Salve, per favore inviami l\'IBAN per procedere.';
        $expIt = $bodyIt . "\n";
        yield 'IT: Cordiali saluti'      => [$bodyIt . "\n\nCordiali saluti,\nMario",        $expIt];
        yield 'IT: Distinti saluti'      => [$bodyIt . "\n\nDistinti saluti,\nGiulia",       $expIt];
        yield 'IT: Saluti'               => [$bodyIt . "\n\nSaluti,\nLuigi",                 $expIt];

        // --- Portuguese (3) ---
        $bodyPt = 'Olá, por favor envie-me o IBAN para prosseguir.';
        $expPt = $bodyPt . "\n";
        yield 'PT: Cumprimentos'                          => [$bodyPt . "\n\nCumprimentos,\nJoao",                          $expPt];
        yield 'PT: Atenciosamente'                        => [$bodyPt . "\n\nAtenciosamente,\nAna",                         $expPt];
        yield 'PT: Com os melhores cumprimentos'          => [$bodyPt . "\n\nCom os melhores cumprimentos,\nMiguel",        $expPt];

        // --- Dutch (3) ---
        $bodyNl = 'Hallo, stuur mij alstublieft uw IBAN om door te gaan.';
        $expNl = $bodyNl . "\n";
        yield 'NL: Met vriendelijke groet' => [$bodyNl . "\n\nMet vriendelijke groet,\nJan",   $expNl];
        yield 'NL: Vriendelijke groet'     => [$bodyNl . "\n\nVriendelijke groet,\nMarieke",   $expNl];
        yield 'NL: Groeten'                => [$bodyNl . "\n\nGroeten,\nPiet",                 $expNl];
    }

    /**
     * @dataProvider multilingualSignoffsProvider
     */
    public function test_strips_multilingual_signoff_block(string $input, string $expectedTextAfter): void
    {
        $result = $this->newStripper()->strip($input, 'conv-1');

        self::assertSame($expectedTextAfter, $result->textAfter);
        self::assertSame(
            \strlen($input) - \strlen($expectedTextAfter),
            $result->bytesRemoved,
            'bytesRemoved must equal the byte-length difference (UTF-8 aware via strlen on byte string)',
        );
        self::assertNotEmpty(
            $result->matchedPatterns,
            'matchedPatterns must record at least one regex identifier when a strip occurred',
        );
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function bracketedPlaceholdersProvider(): iterable
    {
        // Standalone-line bracketed placeholders that the LLM may leave when
        // it falls back on a template pattern. Each test asserts the
        // placeholder line (and any subsequent placeholder lines forming the
        // signature block) is stripped.

        $body = 'Thank you, please send the IBAN to proceed.';
        $expected = $body . "\n";

        yield '[Your Name] alone'        => [$body . "\n\n[Your Name]",                    $expected];
        yield '[Company Name] alone'     => [$body . "\n\n[Company Name]",                 $expected];
        yield '[Your Title]'             => [$body . "\n\n[Your Title]",                   $expected];
        yield '[Date]'                   => [$body . "\n\n[Date]",                         $expected];
        yield 'Stacked placeholders'     => [$body . "\n\n[Your Name]\n[Title]\n[Phone]",  $expected];

        // Composite case: closing line + bracketed placeholder beneath. The
        // existing signoff regex catches the closing line and everything
        // after (including the bracket), so this is double-covered. We assert
        // the outcome regardless of which regex fires.
        yield 'Closing line + [Your Name]' => [
            $body . "\n\nBest regards,\n[Your Name]",
            $expected,
        ];
    }

    /**
     * @dataProvider bracketedPlaceholdersProvider
     */
    public function test_strips_bracketed_placeholders(string $input, string $expectedTextAfter): void
    {
        $result = $this->newStripper()->strip($input, 'conv-1');

        self::assertSame($expectedTextAfter, $result->textAfter);
        self::assertSame(
            \strlen($input) - \strlen($expectedTextAfter),
            $result->bytesRemoved,
        );
        self::assertNotEmpty($result->matchedPatterns);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function rfc3676SeparatorProvider(): iterable
    {
        // RFC 3676 §4.3: a line containing only "-- " (two dashes + space)
        // marks the start of a signature. Strip the marker line and
        // everything that follows.
        //
        // We also accept "--" without trailing space and "---" (3 dashes,
        // commonly seen) because LLMs and email clients are inconsistent
        // about the exact form.

        $body = 'Please send your IBAN to proceed.';
        $expected = $body . "\n";

        yield 'RFC 3676 standard "-- "'  => [$body . "\n-- \nJohn Smith\nTitle\n+1-555",   $expected];
        yield 'Two dashes no trailing space' => [$body . "\n--\nJohn Smith",                $expected];
        yield 'Three dashes'              => [$body . "\n---\nFooter line\n+1-555",         $expected];
        yield 'Separator + multi-line sig' => [$body . "\n-- \nJohn\nDirector\ncompany.com", $expected];
    }

    /**
     * @dataProvider rfc3676SeparatorProvider
     */
    public function test_strips_rfc3676_separator(string $input, string $expectedTextAfter): void
    {
        $result = $this->newStripper()->strip($input, 'conv-1');

        self::assertSame($expectedTextAfter, $result->textAfter);
        self::assertSame(
            \strlen($input) - \strlen($expectedTextAfter),
            $result->bytesRemoved,
        );
        self::assertNotEmpty($result->matchedPatterns);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function adversarialInlineProvider(): iterable
    {
        // Multilingual adversarial cases: a signoff word appears INLINE
        // in the body (mid-sentence or as part of a phrase) and must NOT
        // trigger the strip. The anchoring (\n+ before, \s*\n after) in
        // the signoff regex already protects against these — this provider
        // locks in that behavior as a regression test.

        yield 'EN: Best regards inline after comma'   => ['Please reply, Best regards are appreciated.'];
        yield 'EN: thank you inline'                  => ['I want to thank you for your patience.'];
        yield 'EN: Sincere as adjective'              => ['Sincere question: what is your address?'];
        yield 'EN: best wishes inline'                => ['My best wishes for our deal.'];

        yield 'FR: meilleurs sentiments inline'       => ['Mes meilleurs sentiments pour cette transaction.'];
        yield 'FR: vous remercier inline'             => ['Je vous remercie de votre patience.'];

        yield 'ES: Saludos cordiales inline'          => ['Saludos cordiales en este negocio.'];
        yield 'ES: Atentamente as adverb'             => ['Atentamente esperaba su respuesta.'];

        yield 'DE: Mit freundlichen Grüßen inline'    => ['Mit freundlichen Grüßen im Brief schreiben.'];

        yield 'IT: Cordiali saluti inline'            => ['Cordiali saluti in italiano sono comuni.'];

        yield 'Multi-line body with inline signoff'   => [
            "Hello, please reply soon.\nPlease reply, Best regards are appreciated.",
        ];
    }

    /**
     * @dataProvider adversarialInlineProvider
     */
    public function test_does_NOT_strip_inline_signoff_words(string $input): void
    {
        $result = $this->newStripper()->strip($input, 'conv-1');

        self::assertSame($input, $result->textAfter, 'Inline signoff words must not trigger strip');
        self::assertSame(0, $result->bytesRemoved);
        self::assertSame([], $result->matchedPatterns);
    }
}
