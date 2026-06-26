<?php

declare(strict_types=1);

namespace App\Application\LLM;

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
 * SCOPE: this guard ONLY blocks the FIRST mention of payment-infrastructure
 * keywords in a conversation. Once any inbound message contains any of those
 * keywords, the gate opens permanently for that conversation — the persona
 * can then freely follow up, ask for SWIFT, request beneficiary details, etc.
 * Full IOC-extraction capability preserved after operator-initiated escalation.
 *
 * Does NOT cover the generic words "payment" or "invoice" — these are common
 * in legitimate buyer due-diligence (see spec 117 for the legitimate buyer
 * pushback behavior). We only block the banking-infrastructure-specific
 * vocabulary that creates IOC contamination.
 */
final readonly class PaymentInstigationGuard
{
    /**
     * Payment-infrastructure keywords. Match in BOTH outbound (to detect
     * persona instigation) and inbound (to detect operator anchoring).
     *
     * Limited to banking-infrastructure language (SWIFT, BIC, IBAN, bank
     * account, wire transfer, beneficiary). The generic terms "payment"
     * and "invoice" are deliberately NOT included — see class docblock.
     */
    private const PAYMENT_TOPIC_PATTERN = '/\b('
        . 'swift|bic|iban|ifsc|beneficiary|'
        . 'bank\s+account|routing\s+(?:number|code)|'
        . 'wire\s+transfer|transfer\s+the\s+funds|send\s+the\s+money|'
        . 'initiate\s+(?:the\s+)?payment|process\s+(?:the\s+)?payment'
        . ')\b/i';

    public function __construct(
        private EntityManagerInterface $em,
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
        $outboundMentionsPayment = (bool) preg_match(self::PAYMENT_TOPIC_PATTERN, $outboundText);

        if (!$outboundMentionsPayment) {
            return ['approved' => true, 'reason' => null];
        }

        if ($this->inboundHistoryMentionsPayment($convId)) {
            $this->logger->debug('[PaymentInstigationGuard] Outbound mentions payment; conv is already operator-anchored', [
                'conv_id' => $convId,
            ]);

            return ['approved' => true, 'reason' => null];
        }

        $this->logger->warning('[PaymentInstigationGuard] ❌ Persona is first to mention payment-infrastructure in this conv', [
            'conv_id' => $convId,
            'outbound_preview' => substr($outboundText, 0, 200),
        ]);

        return ['approved' => false, 'reason' => 'payment_instigation_blocked'];
    }

    /**
     * Has the operator (any direction=1 inbound, non-deleted) mentioned any
     * payment-infrastructure keyword in this conversation? Body text only —
     * subject lines and headers don't count for anchoring.
     */
    private function inboundHistoryMentionsPayment(string $convId): bool
    {
        $bodies = $this->em->createQueryBuilder()
            ->select('m.bodyText')
            ->from(Message::class, 'm')
            ->where('m.conversation = :convId')
            ->andWhere('m.direction = :dir')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('convId', $convId)
            ->setParameter('dir', 1)
            ->getQuery()
            ->getArrayResult();

        foreach ($bodies as $row) {
            $body = is_array($row) ? ($row['bodyText'] ?? '') : '';

            if (is_string($body) && preg_match(self::PAYMENT_TOPIC_PATTERN, $body)) {
                return true;
            }
        }

        return false;
    }
}
