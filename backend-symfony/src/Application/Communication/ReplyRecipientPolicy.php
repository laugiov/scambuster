<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Communication\Exception\ReplyRefusedException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Mime\Address;

/**
 * Decides whether an inbound message may be replied to, and to whom.
 *
 * Split out of ReplyHandler on purpose. These are the guards standing between
 * an attacker-controlled header and mail leaving the operator's mailbox, so
 * they must be exercisable without a database, a kernel or a mail account —
 * i.e. in the `unit` suite, which is one of the suites CI actually runs.
 * `functional` is not (see the comment in .github/workflows/ci.yml).
 *
 * Everything reachable from here treats the headers array as hostile input.
 */
final class ReplyRecipientPolicy
{
    public function __construct(private readonly LoggerInterface $logger = new NullLogger())
    {
    }

    /**
     * Resolve who a reply goes to.
     *
     * Reads `from` and nothing else. It used to prefer `reply_to`, which handed
     * the choice of recipient to the sender: the parser keeps the raw header
     * name and only lowercases it (MailMimeParser splits on the first `:` and
     * validates nothing), so a scammer writing `Reply_To:` with an underscore
     * lands a `reply_to` key in the headers array and picks who receives a
     * deceptive mail sent from the operator's mailbox.
     *
     * This closes the third-party reflector. It does not close every abuse of
     * the reply path: a scammer sending from infrastructure they own presents a
     * legitimate `from`, and replying to them is the point of the product.
     *
     * @param array<string, mixed> $headers           inbound message headers, attacker-controlled
     * @param list<string>         $honeypotAddresses every address we know to be ours. MUST come
     *                                                from configuration and the mail account, never
     *                                                from the inbound headers: comparing an
     *                                                attacker-controlled value against another
     *                                                attacker-controlled value is not a guard.
     *
     * @throws ReplyRefusedException when this message must not be answered
     */
    public function resolveRecipient(array $headers, array $honeypotAddresses): string
    {
        $from = $headers['from'] ?? null;

        if (!\is_string($from) || trim($from) === '') {
            throw new ReplyRefusedException('no_sender', 'Cannot determine reply recipient');
        }

        $to = trim($from);

        $known = array_values(array_filter(
            $honeypotAddresses,
            static fn (string $address): bool => trim($address) !== '',
        ));

        // No identity to compare against. The first version of this refused
        // outright, on the principle that a guard which cannot run must not
        // pass. That was the wrong trade: the risk it prevents is a self-loop —
        // contained, and requiring the sender to spoof our own address — while
        // refusing costs every reply the honeypot would ever send. It silenced
        // the whole product on any deployment that had not set
        // HONEYPOT_EMAIL_ADDRESSES, which is the default.
        //
        // So it proceeds, but never silently: this is a misconfiguration and it
        // is logged as one.
        if ($known === []) {
            $this->logger->error(
                '[ReplyRecipientPolicy] No honeypot identity to check against; the self-reply guard did not run. '
                . 'Set HONEYPOT_EMAIL_ADDRESSES or give the mail account an email address.',
                ['recipient' => $to],
            );

            return $to;
        }

        // Refuse to reply to ourselves. A spoofed `From:` carrying the honeypot
        // address would otherwise make the honeypot mail itself in a loop, and
        // that mail would be ingested as a fresh inbound.
        foreach ($known as $address) {
            if ($this->sameMailbox($to, $address)) {
                throw new ReplyRefusedException(
                    'self_addressed',
                    'Refusing to reply: recipient equals the honeypot address',
                );
            }
        }

        return $to;
    }

    /**
     * Return the header that marks this message as automated, or null to proceed.
     *
     * Deliberately narrow: RFC 3834 `Auto-Submitted` and `List-Id` only.
     * `Precedence: bulk` was in this set and was removed — it marks mass mail,
     * and mass-mailed advance-fee fraud is precisely what this honeypot exists
     * to engage. Refusing on it would have silenced the product against its
     * main input to catch an auto-responder case the other two already cover.
     *
     * This does not overlap the ingest pre-filter, which matches on local-parts
     * and known domains and looks at none of these headers.
     *
     * @param array<string, mixed> $headers
     */
    public function autoSubmittedReason(array $headers): ?string
    {
        $autoSubmitted = $headers['auto-submitted'] ?? $headers['auto_submitted'] ?? null;

        // RFC 3834 §5: `no` is the only value meaning "a human wrote this".
        if (\is_string($autoSubmitted) && trim($autoSubmitted) !== '' && strtolower(trim($autoSubmitted)) !== 'no') {
            return 'auto-submitted';
        }

        if (isset($headers['list-id']) || isset($headers['list_id'])) {
            return 'list-id';
        }

        return null;
    }

    /**
     * Compare two addresses on their mailbox, ignoring display name and case.
     *
     * Parses the way the sender does (Symfony `Address`), so a value that
     * survives this check is the value that reaches SMTP.
     */
    public function sameMailbox(string $a, string $b): bool
    {
        if (trim($a) === '' || trim($b) === '') {
            return false;
        }

        try {
            $left = strtolower(Address::create(trim($a))->getAddress());
            $right = strtolower(Address::create(trim($b))->getAddress());
        } catch (\Throwable) {
            // Unparseable on either side: compare raw rather than silently
            // deciding two addresses are different mailboxes.
            return strcasecmp(trim($a), trim($b)) === 0;
        }

        return $left === $right;
    }
}
