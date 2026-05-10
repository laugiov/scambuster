<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\Audit\AuditLogger;
use App\Application\Communication\Smtp\SmtpTransportResolver;
use App\Domain\Communication\Direction;
use App\Domain\Communication\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Handles reply composition, header building, send-status tracking, and SMTP delivery.
 *
 * Responsibilities:
 * - Compose RFC 5322 threading headers (References, In-Reply-To)
 * - Mark messages as sent with provider metadata
 * - Send emails via Symfony Mailer (SMTP)
 */
class ReplyCompositionService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageHandler $messageHandler,
        private readonly ReplyCadenceService $cadenceService,
        private readonly LoggerInterface $logger,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?MailerInterface $mailer = null,
        private readonly ?SmtpTransportResolver $transportResolver = null,
    ) {
    }

    /**
     * Compose headers for threaded email sending.
     *
     * @return array<string, mixed>|null
     */
    public function composeHeaders(string $msgId): ?array
    {
        $message = $this->messageHandler->getMessage($msgId);

        if (!$message instanceof \App\Domain\Communication\Message) {
            return null;
        }

        $parent = $message->getReplyTo();

        if (!$parent instanceof \App\Domain\Communication\Message) {
            throw new \RuntimeException('Message is not a reply');
        }

        // Build References header according to RFC
        $refs = [];
        $parentHeaders = $parent->getHeaders();

        if (!empty($parentHeaders['references'])) {
            /** @var string $references */
            $references = $parentHeaders['references'];
            $refs = preg_split('/\s+/', trim($references)) ?: [];
        }

        if (!empty($parentHeaders['in_reply_to']) && !in_array($parentHeaders['in_reply_to'], $refs, true)) {
            $refs[] = $parentHeaders['in_reply_to'];
        }

        if (!empty($parentHeaders['message_id'])) {
            $refs[] = $parentHeaders['message_id'];
        }

        // Keep only last 12 unique references
        $refs = array_slice(array_unique(array_filter($refs, 'is_string')), -12);

        $to = $message->getHeaders()['to'] ?? null;
        $from = $message->getHeaders()['from'] ?? null;

        // Fix: if "from" is not a valid email (e.g., IMAP hostname stored during ingestion),
        // resolve it from the parent inbound message's "to" (= the honeypot address)
        $fromStr = \is_string($from) ? $from : '';

        if ($fromStr === '' || !str_contains($fromStr, '@')) {
            $parentHeaders = $parent->getHeaders();
            $from = $parentHeaders['to'] ?? $parentHeaders['delivered-to'] ?? $from;
        }

        if (!$to || !$from) {
            throw new \RuntimeException('Missing to/from headers');
        }

        // Run safety checks
        $checks = [
            'safelist_ok' => $this->cadenceService->checkSafelist(\is_string($to) ? $to : ''),
            'kill_switch_off' => !$this->cadenceService->isKillSwitchActive(),
            'cadence_ok' => $this->cadenceService->checkCadence($message->getConversation()->getConvId()),
            'conversation_open' => $message->getConversation()->getStatus()->value === 'open',
        ];

        $safeToSend = $checks['safelist_ok'] && $checks['kill_switch_off'] && $checks['cadence_ok'] && $checks['conversation_open'];
        $rateLimited = !$checks['cadence_ok'];

        return [
            'msg_id' => $msgId,
            'to' => $to,
            'from' => $from,
            'subject' => $message->getSubject() ?? '',
            'in_reply_to' => $parentHeaders['message_id'] ?? null,
            'references' => implode(' ', $refs),
            'thread_id' => $parentHeaders['thread_id'] ?? null,
            'safe_to_send' => $safeToSend,
            'rate_limited' => $rateLimited,
            'checks' => $checks,
        ];
    }

    /**
     * Mark message as sent and store threading headers.
     *
     * @param array<string, mixed>|null $sentHeaders
     */
    public function markAsSent(
        string $msgId,
        string $provider,
        string $providerMsgId,
        \DateTimeImmutable $tsSent,
        ?array $sentHeaders = null,
        ?string $convId = null
    ): bool {
        $message = $this->messageHandler->getMessage($msgId);

        if (!$message instanceof \App\Domain\Communication\Message) {
            return false;
        }

        // Idempotency check
        if ($message->getSendStatus() === 'sent') {
            throw new \RuntimeException('Message already sent');
        }

        $message->setSendStatus('sent');
        $message->setProviderMsgId($providerMsgId);
        $message->setTsSent($tsSent);

        $conversation = $message->getConversation();

        // If conv_id is provided, verify it matches (security check)
        if ($convId !== null && $conversation->getConvId() !== $convId) {
            $this->logger->warning('[ReplyCompositionService] conv_id mismatch during markAsSent', [
                'expected' => $conversation->getConvId(),
                'received' => $convId,
            ]);
        }

        // Build proper threading headers from conversation context
        $currentHeaders = $message->getHeaders();

        // Get the last INCOMING message from the conversation to reply to
        // Direction is an entity, fetch it first
        $directionIn = $this->em->getRepository(Direction::class)->findOneBy(['code' => 'in']);

        /** @var Message[] $inboundMessages */
        $inboundMessages = $this->em->createQueryBuilder()
            ->select('m')
            ->from(Message::class, 'm')
            ->where('m.conversation = :conversation')
            ->andWhere('m.direction = :direction')
            ->andWhere('m.deletedAt IS NULL')
            ->setParameter('conversation', $conversation)
            ->setParameter('direction', $directionIn)
            ->orderBy('m.tsMsg', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getResult();

        if (count($inboundMessages) > 0) {
            /** @var Message $lastInbound */
            $lastInbound = $inboundMessages[0];
            $parentHeaders = $lastInbound->getHeaders();

            // Headers can be stored with either 'message-id' (with dash) or 'message_id' (with underscore)
            /** @var string|null $parentMessageId */
            $parentMessageId = $parentHeaders['message-id'] ?? $parentHeaders['message_id'] ?? null;
            /** @var string $parentReferences */
            $parentReferences = $parentHeaders['references'] ?? '';

            // Build RFC 5322 compliant headers
            if ($parentMessageId) {
                $currentHeaders['in_reply_to'] = $parentMessageId;

                // Build references: parent's references + parent's message_id
                $referencesArray = array_filter(explode(' ', trim($parentReferences)));
                $referencesArray[] = $parentMessageId;
                $currentHeaders['references'] = implode(' ', array_unique($referencesArray));

                $this->logger->debug('[ReplyCompositionService] Threading headers rebuilt', [
                    'in_reply_to' => $parentMessageId,
                ]);
            } else {
                $this->logger->warning('[ReplyCompositionService] No message_id in parent message headers');
            }
        } else {
            $this->logger->warning('[ReplyCompositionService] No incoming messages found in conversation');
        }

        // Store additional headers from n8n
        if ($sentHeaders !== null) {
            if (isset($sentHeaders['thread_id'])) {
                $currentHeaders['thread_id'] = $sentHeaders['thread_id'];
            }

            // Store the real RFC822 Message-ID if provided by n8n workflow
            if (isset($sentHeaders['message-id'])) {
                $rfc822MessageId = $sentHeaders['message-id'];

                // Clean chevrons if present (e.g., "<message-id>" -> "message-id")
                $rfc822MessageId = trim(is_string($rfc822MessageId) ? $rfc822MessageId : '', '<>');

                $currentHeaders['message-id'] = $rfc822MessageId;
                $this->logger->debug('[ReplyCompositionService] RFC822 Message-ID stored');
            }
        }

        $message->setHeaders($currentHeaders);

        $this->em->flush();

        $this->auditLogger?->log(
            \App\Domain\Audit\AuditEventType::REPLY_SENT,
            $conversation->getConvId(),
            'mark_as_sent',
            'success',
            'message',
            $msgId,
            [
                'provider' => $provider,
                'provider_msg_id' => $providerMsgId,
            ],
        );

        return true;
    }

    /**
     * Send a reply email via Symfony Mailer (SMTP).
     * Stateless: reads draft from DB, sends, returns Message-ID. Does NOT modify message state.
     *
     * @return array{success: bool, message_id: string, ts_sent: string}
     */
    public function sendEmail(string $msgId): array
    {
        if (!$this->mailer instanceof \Symfony\Component\Mailer\MailerInterface) {
            throw new \RuntimeException('Mailer not configured (MAILER_DSN missing or symfony/mailer not installed)');
        }

        $message = $this->messageHandler->getMessage($msgId);

        if (!$message instanceof \App\Domain\Communication\Message) {
            throw new \RuntimeException('Message not found');
        }

        // Verify it's an outbound reply
        if ($message->getDirection()->getCode() !== 'out') {
            throw new \RuntimeException('Cannot send a non-outbound message');
        }

        // Resolve the right mailer based on the conversation's mail account.
        // Falls back to the default mailer (global MAILER_DSN) if no resolver
        // is injected or the account has no per-account SMTP configured.
        $mailer = $this->mailer;

        if ($this->transportResolver !== null) {
            $account = $message->getConversation()->getAccount();
            $mailer = $this->transportResolver->resolveForAccount($account);
        }

        // Get compose/threading data
        $compose = $this->composeHeaders($msgId);

        if (!$compose) {
            throw new \RuntimeException('Cannot compose headers for message');
        }

        // Check safety -- but skip cadence check (n8n human delay already handles timing)
        /** @var array{safelist_ok: bool, kill_switch_off: bool, cadence_ok: bool, conversation_open: bool} $checks */
        $checks = $compose['checks'];

        if (!$checks['safelist_ok']) {
            throw new \RuntimeException('Safety checks failed: recipient not in safelist');
        }

        if (!$checks['kill_switch_off']) {
            throw new \RuntimeException('Safety checks failed: kill switch is active');
        }

        if (!$checks['conversation_open']) {
            throw new \RuntimeException('Safety checks failed: conversation is not open');
        }

        // Generate a local Message-ID
        $generatedMessageId = '<' . bin2hex(random_bytes(16)) . '@scambuster.local>';
        $tsSent = new \DateTimeImmutable();

        // Build the email -- composeHeaders() already resolves correct from/to
        /** @var string $composeFrom */
        $composeFrom = $compose['from'] ?? '';
        /** @var string $composeTo */
        $composeTo = $compose['to'] ?? '';
        /** @var string $composeSubject */
        $composeSubject = $compose['subject'] ?? '';
        $email = (new Email())
            ->from($composeFrom)
            ->to($composeTo)
            ->subject($composeSubject);

        // Set threading headers
        if (!empty($compose['in_reply_to'])) {
            /** @var string $inReplyTo */
            $inReplyTo = $compose['in_reply_to'];
            $email->getHeaders()->addIdHeader('In-Reply-To', $inReplyTo);
        }

        if (!empty($compose['references'])) {
            /** @var string $refs */
            $refs = $compose['references'];
            $email->getHeaders()->addTextHeader('References', $refs);
        }
        // Message-ID must use addIdHeader (IdentificationHeader), not addTextHeader
        // Strip chevrons -- Symfony adds them automatically
        $cleanMessageId = trim($generatedMessageId, '<>');
        $email->getHeaders()->addIdHeader('Message-ID', $cleanMessageId);

        // Set body
        $bodyHtml = $message->getBodyHtml();
        $bodyText = $message->getBodyText();

        if ($bodyHtml) {
            $email->html($bodyHtml);
        }

        if ($bodyText !== '' && $bodyText !== '0') {
            $email->text($bodyText);
        }

        // Send via resolved mailer (per-account or default fallback)
        $mailer->send($email);

        $this->logger->info('[ReplyCompositionService] Email sent via SMTP', [
            'msg_id' => $msgId,
            'to' => $compose['to'],
            'message_id' => $generatedMessageId,
        ]);

        return [
            'success' => true,
            'message_id' => $generatedMessageId,
            'ts_sent' => $tsSent->format(\DateTimeInterface::ATOM),
        ];
    }
}
