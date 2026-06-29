<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Spec 116 — block persona outbounds that introduce payment-infrastructure
 * topics (SWIFT/BIC/IBAN/IFSC/bank account/wire transfer/beneficiary) BEFORE
 * the operator has mentioned any of those topics themselves.
 *
 * Rationale: the IOC dataset is only scientifically defensible when the
 * operator's escalation toward payment infrastructure is unprompted. A
 * persona that asks for SWIFT first is just as likely to extract a real
 * vendor's business bank account as a scammer's mule account — both file
 * the details when asked. The discriminator is who opens the topic.
 *
 * v1 (LLM-judge) — see v0 in commit 0397fb60 for the original regex-based
 * implementation. v0 was English-only and missed multilingual / paraphrase
 * cases (FR "virement", ES "transferencia", HI "NEFT/RTGS", paraphrases
 * like "kindly remit"). v1 replaces the regex with an LLM judge call:
 * the LLM sees both the inbound history and the outbound draft and decides
 * whether the outbound is INTRODUCING the payment topic vs FOLLOWING the
 * operator's prior mention — works across all languages and paraphrases the
 * LLM understands.
 *
 * SCOPE: once any inbound message contains any payment-infrastructure
 * concept (in any language), the gate opens permanently for that
 * conversation — persona can then freely follow up. Full IOC-extraction
 * capability preserved after operator-initiated escalation.
 *
 * Fail-open on LLM error or unexpected response (same pattern as
 * OperationalLeakageDetector spec 065d) — we'd rather miss an instigation
 * than stall the entire reply pipeline on infra outages.
 */
// Non-final so unit tests can stub ::check() without a DB or LLM call.
readonly class PaymentInstigationGuard
{
    private const MODEL = 'gpt-4o-mini';
    private const TEMPERATURE = 0.0;
    private const MAX_TOKENS = 30;
    private const PURPOSE = 'payment_instigation_check';

    /**
     * Maximum IN messages to feed the judge. Limits prompt size and cost
     * on very long conversations. Most-recent messages are the most
     * relevant for anchoring; if a payment topic was mentioned earlier
     * in a 100-turn conv but never re-discussed, the operator has likely
     * forgotten it and re-anchoring is harmless.
     */
    private const MAX_INBOUND_MESSAGES_IN_PROMPT = 20;

    /**
     * Max chars per inbound body. Long bodies (forwarded threads, signatures)
     * are truncated to keep prompt size sane.
     */
    private const MAX_INBOUND_BODY_CHARS = 1500;

    public function __construct(
        private EntityManagerInterface $em,
        private LLMClientInterface $llmClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check whether the outbound draft instigates the payment topic
     * without operator anchoring.
     *
     * @return array{approved: bool, reason: ?string}
     *                                                approved=false + reason='payment_instigation_blocked' on instigation;
     *                                                approved=true + reason=null otherwise.
     */
    public function check(string $outboundText, string $convId): array
    {
        $inboundBodies = $this->fetchInboundBodies($convId);

        // Defensive trivial path: persona replying with no inbound history
        // at all (shouldn't happen in production, but covers test fixtures
        // and avoids an LLM call on an empty conv). Pass through.
        if ($inboundBodies === []) {
            return ['approved' => true, 'reason' => null];
        }

        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($inboundBodies, $outboundText)],
        ];

        try {
            $response = $this->llmClient->chat($messages, [
                'model' => self::MODEL,
                'temperature' => self::TEMPERATURE,
                'max_tokens' => self::MAX_TOKENS,
                'purpose' => self::PURPOSE,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[PaymentInstigationGuard] LLM call failed, failing open', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
            ]);

            return ['approved' => true, 'reason' => null];
        }

        $verdict = $this->parseVerdict($response);

        if ($verdict === null) {
            $this->logger->warning('[PaymentInstigationGuard] unparseable verdict, failing open', [
                'conv_id' => $convId,
                'response_preview' => substr($response, 0, 200),
            ]);

            return ['approved' => true, 'reason' => null];
        }

        if ($verdict === 'YES_PERSONA_INSTIGATES') {
            $this->logger->warning('[PaymentInstigationGuard] ❌ Persona is first to mention payment-infrastructure', [
                'conv_id' => $convId,
                'outbound_preview' => substr($outboundText, 0, 200),
            ]);

            return ['approved' => false, 'reason' => 'payment_instigation_blocked'];
        }

        // verdict is one of the NO_* values → approved
        return ['approved' => true, 'reason' => null];
    }

    /**
     * Fetch the most recent inbound message bodies for this conversation.
     *
     * @return list<string>
     */
    private function fetchInboundBodies(string $convId): array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('m.bodyText')
            ->from(Message::class, 'm')
            ->where('m.conversation = :convId')
            ->andWhere('m.direction = :dir')
            ->andWhere('m.deletedAt IS NULL')
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults(self::MAX_INBOUND_MESSAGES_IN_PROMPT)
            ->setParameter('convId', $convId)
            ->setParameter('dir', 1)
            ->getQuery()
            ->getArrayResult();

        $bodies = [];

        foreach ($rows as $row) {
            $body = is_array($row) ? ($row['bodyText'] ?? '') : '';

            if (is_string($body) && $body !== '') {
                $bodies[] = mb_substr($body, 0, self::MAX_INBOUND_BODY_CHARS);
            }
        }

        // Reverse so the prompt presents them chronologically (oldest first),
        // which matches how a human reads a thread.
        return array_reverse($bodies);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
            You are a strict text classifier. Your job is to decide whether a candidate outbound email reply is
            introducing financial / payment-infrastructure topics into a conversation FOR THE FIRST TIME, or
            whether the operator (the inbound counter-party) has already mentioned such topics.

            Payment-infrastructure topics include (across ALL languages and paraphrases):
            - Bank-routing identifiers: SWIFT, BIC, IBAN, IFSC, ABA routing number, sort code, account number,
              IBAN-equivalent in any country (NEFT/RTGS in India, virement bancaire / RIB in France,
              transferencia bancaria in Spanish, Überweisung in German, etc.)
            - Wire transfer / bank transfer / "send the funds" / "remit the payment" / "transférer", "virer",
              "remesar", any verb describing the act of moving money through a bank
            - Banking party identifiers: beneficiary, account holder, bank name + branch, banking address
            - Cryptographic-asset wires: wallet address, transfer to wallet

            DOES NOT include:
            - Generic commercial words: "payment", "invoice", "fee", "price", "quote", "estimate", "deposit"
            - "Send me a contract" / "send me a quote" / "let's talk about pricing"

            Read the inbound history (operator's messages, chronological) and the outbound draft. Decide:

            Answer with EXACTLY ONE of these three tokens, nothing else:
            - YES_PERSONA_INSTIGATES                  — Outbound draft mentions payment-infrastructure AND no prior operator inbound has.
            - NO_OPERATOR_ALREADY_MENTIONED          — Operator has already mentioned payment-infrastructure (any language) in at least one prior inbound.
            - NO_OUTBOUND_DOES_NOT_MENTION_PAYMENT   — Outbound draft itself does not mention payment-infrastructure at all.

            No explanation. No quotes. Just the token.
            PROMPT;
    }

    /**
     * @param list<string> $inboundBodies
     */
    private function userPrompt(array $inboundBodies, string $outboundDraft): string
    {
        $historyBlock = '';

        foreach ($inboundBodies as $i => $body) {
            $historyBlock .= '--- INBOUND #' . ($i + 1) . " ---\n" . $body . "\n";
        }

        if ($historyBlock === '') {
            $historyBlock = "(no inbound messages yet)\n";
        }

        return <<<PROMPT
            CONVERSATION HISTORY (operator inbound messages, chronological — oldest first):

            {$historyBlock}

            OUTBOUND DRAFT (the message the bot is about to send to the operator):
            ---
            {$outboundDraft}
            ---

            Your verdict (one token only):
            PROMPT;
    }

    /**
     * Parse the LLM response, looking for one of the three expected tokens.
     * Returns null on unparseable response (caller fails open).
     */
    private function parseVerdict(string $response): ?string
    {
        $clean = strtoupper(trim($response));

        // Strip surrounding quotes / punctuation the model might add.
        $clean = trim($clean, "\"'.,;: \t\n\r");

        $known = [
            'YES_PERSONA_INSTIGATES',
            'NO_OPERATOR_ALREADY_MENTIONED',
            'NO_OUTBOUND_DOES_NOT_MENTION_PAYMENT',
        ];

        foreach ($known as $token) {
            // Token may appear standalone or as the first word of a sentence.
            if (str_starts_with($clean, $token)) {
                return $token;
            }
        }

        return null;
    }
}
