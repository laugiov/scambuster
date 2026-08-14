<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Communication;

use App\Application\Communication\ReplyRecipientPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The guards standing between an attacker-controlled header and mail leaving
 * the operator's mailbox.
 *
 * These live in the `unit` suite deliberately: it is one of the suites CI runs.
 * `functional` is not (see .github/workflows/ci.yml), so a controller test would
 * have left these unguarded on the branch.
 */
final class ReplyRecipientPolicyTest extends TestCase
{
    private ReplyRecipientPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReplyRecipientPolicy();
    }

    /**
     * The regression this whole change exists for.
     *
     * The parser lowercases header names but does not normalise them, so a
     * scammer writing `Reply_To:` with an underscore lands a literal `reply_to`
     * key. The old code preferred that key over `from`, which let the sender
     * choose who received mail from the operator's mailbox.
     */
    public function testReplyToHeaderNeverChoosesTheRecipient(): void
    {
        $to = $this->policy->resolveRecipient([
            'from' => 'scammer@evil.example',
            'reply_to' => 'victim@target.example',
            'reply-to' => 'other@evil.example',
        ], ['honeypot@operator.example']);

        self::assertSame('scammer@evil.example', $to);
    }

    public function testRecipientComesFromTheFromHeader(): void
    {
        $to = $this->policy->resolveRecipient(
            ['from' => 'Bob <scammer@evil.example>'],
            ['honeypot@operator.example'],
        );

        self::assertSame('Bob <scammer@evil.example>', $to);
    }

    public function testMissingFromIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine reply recipient');

        $this->policy->resolveRecipient(['reply_to' => 'victim@target.example'], ['honeypot@operator.example']);
    }

    public function testBlankFromIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->policy->resolveRecipient(['from' => '   '], ['honeypot@operator.example']);
    }

    /**
     * A spoofed `From:` carrying the honeypot's own address would make the
     * honeypot mail itself, forever.
     */
    #[DataProvider('selfAddressedProvider')]
    public function testRefusesToReplyToItself(string $from, string $honeypot): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recipient equals the honeypot address');

        $this->policy->resolveRecipient(['from' => $from], [$honeypot]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function selfAddressedProvider(): iterable
    {
        yield 'exact' => ['honeypot@operator.example', 'honeypot@operator.example'];
        yield 'case differs' => ['HoneyPot@Operator.Example', 'honeypot@operator.example'];
        yield 'display name hides it' => ['Support <honeypot@operator.example>', 'honeypot@operator.example'];
        yield 'whitespace padded' => ['  honeypot@operator.example  ', 'honeypot@operator.example'];
    }

    /**
     * The returned value is what reaches the sender, so it must not carry the
     * whitespace the header had.
     */
    public function testRecipientIsTrimmed(): void
    {
        $to = $this->policy->resolveRecipient(
            ['from' => "  scammer@evil.example \t"],
            ['honeypot@operator.example'],
        );

        self::assertSame('scammer@evil.example', $to);
    }

    public function testAnUnknownSenderIsStillAllowed(): void
    {
        // Replying to strangers is the product. The guard must not overreach.
        $to = $this->policy->resolveRecipient(
            ['from' => 'someone@unknown.example'],
            ['honeypot@operator.example'],
        );

        self::assertSame('someone@unknown.example', $to);
    }

    /**
     * Regression: the guard used to compare the inbound `From:` against a
     * honeypot address read from the inbound `To:` — two values written by the
     * same hand. A `To:` naming a decoy first defeated it, because the parser
     * keeps only the first address of a multi-address `To:`.
     *
     * The authoritative list is now passed in from the mail account and the
     * configured honeypot addresses, so a decoy in the headers changes nothing.
     */
    public function testDecoyInTheHeadersCannotDefeatTheSelfAddressGuard(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recipient equals the honeypot address');

        $this->policy->resolveRecipient(
            [
                'from' => 'honeypot@operator.example',   // spoofed
                'to' => 'decoy@attacker.example',        // what the old guard compared against
            ],
            ['honeypot@operator.example'],
        );
    }

    /**
     * Regression: an empty honeypot identity used to turn the guard off
     * silently. A guard that cannot run must not pass.
     */
    public function testNoHoneypotIdentityFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no honeypot identity configured');

        $this->policy->resolveRecipient(['from' => 'scammer@evil.example'], []);
    }

    public function testBlankHoneypotIdentitiesAreNotAnIdentity(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no honeypot identity configured');

        $this->policy->resolveRecipient(['from' => 'scammer@evil.example'], ['', '   ']);
    }

    /**
     * Any of the configured addresses must trigger the guard, not just the first.
     */
    public function testEveryConfiguredHoneypotAddressIsChecked(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('recipient equals the honeypot address');

        $this->policy->resolveRecipient(
            ['from' => 'second@operator.example'],
            ['first@operator.example', 'second@operator.example'],
        );
    }

    public function testNonStringFromIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot determine reply recipient');

        $this->policy->resolveRecipient(['from' => ['scammer@evil.example']], ['honeypot@operator.example']);
    }

    /**
     * @param array<string, mixed> $headers
     */
    #[DataProvider('automatedMailProvider')]
    public function testAutomatedMailIsRefused(array $headers, string $expectedReason): void
    {
        self::assertSame($expectedReason, $this->policy->autoSubmittedReason($headers));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function automatedMailProvider(): iterable
    {
        yield 'auto-replied' => [['auto-submitted' => 'auto-replied'], 'auto-submitted'];
        yield 'auto-generated' => [['auto-submitted' => 'auto-generated'], 'auto-submitted'];
        yield 'underscore variant' => [['auto_submitted' => 'auto-replied'], 'auto-submitted'];
        yield 'mixed case' => [['auto-submitted' => 'Auto-Generated'], 'auto-submitted'];
        yield 'list-id' => [['list-id' => '<news.example.com>'], 'list-id'];
        yield 'list_id underscore' => [['list_id' => '<news.example.com>'], 'list-id'];
        // Padding must not buy an evasion.
        yield 'auto-submitted padded' => [['auto-submitted' => '  auto-replied  '], 'auto-submitted'];
    }

    /**
     * @param array<string, mixed> $headers
     */
    #[DataProvider('humanMailProvider')]
    public function testHumanMailProceeds(array $headers): void
    {
        self::assertNull($this->policy->autoSubmittedReason($headers));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function humanMailProvider(): iterable
    {
        yield 'no markers' => [['from' => 'scammer@evil.example']];
        // RFC 3834 §5: `no` is the value meaning a human wrote it.
        yield 'auto-submitted no' => [['auto-submitted' => 'no']];
        yield 'auto-submitted No' => [['auto-submitted' => 'No']];
        yield 'empty auto-submitted' => [['auto-submitted' => '']];
        // A whitespace-only header carries no marker; flagging it would refuse
        // to reply to real mail.
        yield 'blank auto-submitted' => [['auto-submitted' => "  \t "]];
        // `Precedence: bulk` is NOT a refusal. It marks mass mail, and
        // mass-mailed advance-fee fraud is the honeypot's main input; refusing
        // on it would silence the product against exactly what it exists for.
        yield 'precedence bulk is not automated mail' => [['precedence' => 'bulk']];
        yield 'precedence list' => [['precedence' => 'list']];
    }

    public function testSameMailboxIgnoresDisplayNameAndCase(): void
    {
        self::assertTrue($this->policy->sameMailbox('Bob <a@b.test>', 'A@B.TEST'));
        self::assertFalse($this->policy->sameMailbox('a@b.test', 'c@b.test'));
    }

    public function testSameMailboxTreatsBlanksAsDifferent(): void
    {
        self::assertFalse($this->policy->sameMailbox('', 'a@b.test'));
        self::assertFalse($this->policy->sameMailbox('a@b.test', '  '));
    }
}
