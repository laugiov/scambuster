<?php

declare(strict_types=1);

namespace App\Application\LLM;

use App\Application\LLM\Port\LLMClientInterface;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Block persona outbounds that introduce payment-infrastructure
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
 * FAILURE SEMANTICS (risk-scoped). On LLM error or unparseable/empty verdict,
 * check() consults a deterministic in-process scan of the outbound draft:
 *   - draft contains obvious payment-infrastructure tokens → fail CLOSED
 *     (block), because we cannot confirm the operator anchored the topic and
 *     the risky content is demonstrably present;
 *   - draft contains none → fail OPEN (approve), so an LLM outage never stalls
 *     the common, payment-free reply.
 * The judge is still the primary, multilingual decision-maker; the scan is only
 * the failure-path backstop, so detection quality is not regressed.
 */
// Non-final so unit tests can stub ::check() without a DB or LLM call.
readonly class PaymentInstigationGuard
{
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

    /**
     * Deterministic backstop for the failure path only: obvious
     * payment-infrastructure tokens across the common languages the judge
     * covers. Over-inclusion is safe here — a match only ever redirects an
     * ALREADY-failed judge verdict toward blocking, never toward approving.
     *
     * @var array<string>
     */
    private const PAYMENT_INFRA_TOKEN_PATTERNS = [
        '/\b(?:swift|bic|iban|ifsc)\b/i',
        '/\b(?:neft|rtgs|aba)\b/i',
        '/\bsort code\b/i',
        '/\brouting number\b/i',
        '/\baccount number\b/i',
        '/\b(?:wire|bank) transfer\b/i',
        '/\bbeneficiary\b/i',
        '/\bwallet address\b/i',
        '/\bremit(?:tance)?\b/i',
        '/\bvirement\b/i',                 // FR wire transfer
        '/\btransferencia\b/i',            // ES transfer
        '/\b(?:ü|u)berweisung\b/iu',       // DE transfer (u flag: match accented form)
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private LLMClientInterface $llmClient,
        private LoggerInterface $logger,
        // Provider-configured model (%llm.model%); default is the OpenAI backstop.
        private string $model = 'gpt-4o-mini',
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
                'model' => $this->model,
                'temperature' => self::TEMPERATURE,
                'max_tokens' => self::MAX_TOKENS,
                'purpose' => self::PURPOSE,
            ]);
        } catch (\Throwable $e) {
            return $this->failureVerdict($outboundText, $convId, 'llm_error:' . $e->getMessage());
        }

        $verdict = $this->parseVerdict($response);

        if ($verdict === null) {
            return $this->failureVerdict($outboundText, $convId, 'unparseable:' . substr($response, 0, 200));
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
     * Decide the outcome when the judge could not give a usable verdict.
     * Fail CLOSED if the draft carries payment-infrastructure tokens (risky
     * content present, anchoring unconfirmable), otherwise fail OPEN. The
     * deterministic scan runs only here — never on the nominal (judge-OK) path.
     *
     * @return array{approved: bool, reason: ?string}
     */
    private function failureVerdict(string $outboundText, string $convId, string $cause): array
    {
        if ($this->containsPaymentInfraTokens($outboundText)) {
            $this->logger->warning('[PaymentInstigationGuard] ❌ judge unusable + payment tokens in draft → failing CLOSED', [
                'conv_id' => $convId,
                'cause' => $cause,
            ]);

            return ['approved' => false, 'reason' => 'payment_instigation_blocked'];
        }

        $this->logger->warning('[PaymentInstigationGuard] judge unusable, no payment tokens in draft → failing open', [
            'conv_id' => $convId,
            'cause' => $cause,
        ]);

        return ['approved' => true, 'reason' => null];
    }

    /**
     * Deterministic scan for obvious payment-infrastructure tokens. Used only
     * as the failure-path backstop in {@see self::check()}.
     */
    private function containsPaymentInfraTokens(string $text): bool
    {
        foreach (self::PAYMENT_INFRA_TOKEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Answer only the anchoring half of the question: has the operator
     * (scammer) already raised payment-infrastructure topics in this
     * conversation? Computable before any outbound draft exists, so the
     * prompt's stage objectives can consume the SAME state this guard
     * enforces — prompt guidance and enforcement can never contradict
     * each other.
     *
     * Once true, true forever for the conversation (same permanent-gate
     * semantics as check()). Callers evaluate this once per generation.
     *
     * Failure semantics are the OPPOSITE of check(): fail CLOSED
     * (false) on LLM error or unparseable verdict. A false negative
     * only makes the prompt conservatively money-free; a false positive
     * would instruct the persona to instigate. check() keeps its
     * fail-open enforcement so an LLM outage never stalls the pipeline.
     */
    public function isPaymentTopicAnchored(string $convId): bool
    {
        $inboundBodies = $this->fetchInboundBodies($convId);

        if ($inboundBodies === []) {
            return false;
        }

        $messages = [
            ['role' => 'system', 'content' => $this->anchoringSystemPrompt()],
            ['role' => 'user', 'content' => $this->anchoringUserPrompt($inboundBodies)],
        ];

        try {
            $response = $this->llmClient->chat($messages, [
                'model' => $this->model,
                'temperature' => self::TEMPERATURE,
                'max_tokens' => self::MAX_TOKENS,
                'purpose' => self::PURPOSE,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('[PaymentInstigationGuard] anchoring LLM call failed, failing closed (not anchored)', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $clean = strtoupper(trim($response));
        $clean = trim($clean, "\"'.,;: \t\n\r");

        // Check the negative token first: it shares no prefix with the
        // positive one, but explicit ordering keeps the intent obvious.
        if (str_starts_with($clean, 'OPERATOR_NOT_MENTIONED')) {
            return false;
        }

        if (str_starts_with($clean, 'OPERATOR_MENTIONED')) {
            return true;
        }

        $this->logger->warning('[PaymentInstigationGuard] unparseable anchoring verdict, failing closed (not anchored)', [
            'conv_id' => $convId,
            'response_preview' => substr($response, 0, 200),
        ]);

        return false;
    }

    /**
     * Fetch the most recent inbound message bodies for this conversation.
     *
     * @return list<string>
     */
    private function fetchInboundBodies(string $convId): array
    {
        // Resolve the inbound direction by its code, not a hardcoded FK
        // id: the id is assigned by a sequence and is not guaranteed to be
        // 1 in every environment (fixtures seed higher values), so a magic
        // integer would silently return no inbound bodies and make both the
        // anchoring judge and check() no-op wherever the id differs.
        $rows = $this->em->createQueryBuilder()
            ->select('m.bodyText')
            ->from(Message::class, 'm')
            ->join('m.direction', 'd')
            ->where('m.conversation = :convId')
            ->andWhere('d.code = :dirCode')
            ->andWhere('m.deletedAt IS NULL')
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults(self::MAX_INBOUND_MESSAGES_IN_PROMPT)
            ->setParameter('convId', $convId)
            ->setParameter('dirCode', 'in')
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

    private function anchoringSystemPrompt(): string
    {
        return <<<'PROMPT'
            You are a strict text classifier. Your job is to decide whether the operator (the inbound
            counter-party in an email conversation) has mentioned financial / payment-infrastructure topics
            in at least one of their messages.

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

            Answer with EXACTLY ONE of these two tokens, nothing else:
            - OPERATOR_MENTIONED      — At least one inbound message mentions payment-infrastructure topics.
            - OPERATOR_NOT_MENTIONED  — No inbound message mentions payment-infrastructure topics.

            No explanation. No quotes. Just the token.
            PROMPT;
    }

    /**
     * @param list<string> $inboundBodies
     */
    private function anchoringUserPrompt(array $inboundBodies): string
    {
        $historyBlock = '';

        foreach ($inboundBodies as $i => $body) {
            $historyBlock .= '--- INBOUND #' . ($i + 1) . " ---\n" . $body . "\n";
        }

        return <<<PROMPT
            CONVERSATION HISTORY (operator inbound messages, chronological — oldest first):

            {$historyBlock}

            Your verdict (one token only):
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
