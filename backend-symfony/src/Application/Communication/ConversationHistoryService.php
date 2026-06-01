<?php

declare(strict_types=1);

namespace App\Application\Communication;

use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service responsible for generating summaries of previous conversations from the same sender
 *
 * This service is used to enrich LLM context when generating replies by providing
 * a summary of other conversations with the same scammer. This helps the LLM to:
 * - Understand the scammer's previous tactics
 * - Maintain consistency across conversations
 * - Generate more contextually relevant responses
 *
 * The summary is only generated if there are other conversations from the same sender.
 */
final readonly class ConversationHistoryService
{
    private const MAX_CONVERSATIONS_TO_SUMMARIZE = 5;
    private const MAX_MESSAGES_PER_SUMMARY = 20;

    /**
     * @param array<int, string> $excludedEmails List of email addresses to exclude from summary generation
     */
    public function __construct(
        private EntityManagerInterface $em,
        private LLMClientInterface $llmClient,
        private LoggerInterface $logger,
        private array $excludedEmails = []
    ) {
    }

    /**
     * Generate a summary of other conversations from the same sender
     *
     * @param string $currentConvId Conversation ID to exclude from summary
     * @param string $senderEmail   Email of the sender
     *
     * @return string|null Summary text (3-4 sentences) or null if no other conversations found
     */
    public function getSenderHistorySummary(string $currentConvId, string $senderEmail): ?string
    {
        // Check if sender email is in exclusion list (test emails)
        if ($this->isEmailExcluded($senderEmail)) {
            $this->logger->debug('[ConversationHistoryService] Sender email is excluded from summary generation', [
                'sender_email' => $senderEmail,
                'excluded_emails' => $this->excludedEmails,
            ]);

            return null;
        }

        $this->logger->info('[ConversationHistoryService] Attempting to generate sender history summary', [
            'current_conv_id' => $currentConvId,
            'sender_email' => $senderEmail,
        ]);

        // Find other conversations from the same sender (excluding current conversation)
        $otherConversations = $this->findOtherConversationsFromSender($currentConvId, $senderEmail);

        if ($otherConversations === []) {
            $this->logger->debug('[ConversationHistoryService] No other conversations found for sender', [
                'sender_email' => $senderEmail,
            ]);

            return null;
        }

        $this->logger->info('[ConversationHistoryService] Found other conversations, generating summary', [
            'sender_email' => $senderEmail,
            'nb_conversations' => count($otherConversations),
        ]);

        // Get messages from these conversations
        $messages = $this->getMessagesFromConversations($otherConversations);

        if ($messages === []) {
            $this->logger->warning('[ConversationHistoryService] No messages found in other conversations', [
                'sender_email' => $senderEmail,
                'conversation_ids' => array_column($otherConversations, 'conv_id'),
            ]);

            return null;
        }

        $this->logger->debug('[ConversationHistoryService] Retrieved messages from conversations', [
            'nb_messages' => count($messages),
        ]);

        // Generate summary via LLM
        try {
            $summary = $this->generateSummary($messages, $senderEmail);

            $this->logger->info('[ConversationHistoryService] Summary generated successfully', [
                'sender_email' => $senderEmail,
                'summary_length' => strlen($summary),
            ]);

            return $summary;
        } catch (\Throwable $e) {
            $this->logger->error('[ConversationHistoryService] Failed to generate summary', [
                'error' => $e->getMessage(),
                'sender_email' => $senderEmail,
            ]);

            return null;
        }
    }

    /**
     * Find other conversations from the same sender (excluding current conversation)
     *
     * @return list<array{conv_id: string, ts_first: string}> List of conversation IDs and timestamps
     */
    private function findOtherConversationsFromSender(string $currentConvId, string $senderEmail): array
    {
        $sql = <<<'SQL'
            WITH other_conversations AS (
                SELECT DISTINCT c2.conv_id, c2.ts_first
                FROM conversation c2
                JOIN message m2 ON c2.conv_id = m2.conv_id
                JOIN lkp_direction d2 ON m2.direction = d2.dir_id
                WHERE d2.code = 'in'
                  AND m2.headers->>'from' = :senderEmail
                  AND c2.conv_id != :currentConvId
                  AND c2.deleted_at IS NULL
                  AND m2.deleted_at IS NULL
                ORDER BY c2.ts_first DESC
                LIMIT :maxConversations
            )
            SELECT conv_id, ts_first
            FROM other_conversations
            ORDER BY ts_first ASC
        SQL;

        $stmt = $this->em->getConnection()->executeQuery($sql, [
            'senderEmail' => $senderEmail,
            'currentConvId' => $currentConvId,
            'maxConversations' => self::MAX_CONVERSATIONS_TO_SUMMARIZE,
        ]);

        /** @var list<array{conv_id: string, ts_first: string}> */
        return $stmt->fetchAllAssociative();
    }

    /**
     * Get messages from given conversations (up to MAX_MESSAGES_PER_SUMMARY)
     *
     * @param list<array{conv_id: string, ts_first?: string}> $conversations List of conversation data
     *
     * @return list<array{conv_id: string, direction: string, subject: string|null, body: string, ts_msg: string}>
     */
    private function getMessagesFromConversations(array $conversations): array
    {
        if ($conversations === []) {
            return [];
        }

        $convIds = array_column($conversations, 'conv_id');

        $sql = <<<'SQL'
            SELECT
                m.conv_id,
                d.code as direction,
                m.subject,
                m.body_text as body,
                TO_CHAR(m.ts_msg, 'YYYY-MM-DD HH24:MI') as ts_msg
            FROM message m
            JOIN lkp_direction d ON m.direction = d.dir_id
            WHERE m.conv_id = ANY(:convIds)
              AND m.deleted_at IS NULL
            ORDER BY m.ts_msg ASC
            LIMIT :maxMessages
        SQL;

        $stmt = $this->em->getConnection()->executeQuery($sql, [
            'convIds' => '{' . implode(',', $convIds) . '}',
            'maxMessages' => self::MAX_MESSAGES_PER_SUMMARY,
        ]);

        /** @var list<array{conv_id: string, direction: string, subject: string|null, body: string, ts_msg: string}> */
        return $stmt->fetchAllAssociative();
    }

    /**
     * Generate a concise summary of messages using LLM
     *
     * @param list<array{conv_id: string, direction: string, subject: string|null, body: string, ts_msg: string}> $messages
     */
    private function generateSummary(array $messages, string $senderEmail): string
    {
        $formattedMessages = $this->formatMessagesForSummary($messages);

        $systemPrompt = <<<'PROMPT'
You are an assistant that summarizes email conversations between a scammer and a potential victim (an anti-scam honeypot).
Your role is to produce a very concise summary (3-4 sentences maximum) of prior exchanges to provide context to the reply generation system.

Focus on:
- Themes and tactics used by the scammer
- Promises or requests made
- Evolution of tone and urgency
- Key points to remember for the current conversation

Be factual, concise, and relevant. Avoid superfluous detail.
PROMPT;

        $userPrompt = <<<PROMPT
Here are the messages from prior conversations between the scammer ({$senderEmail}) and our honeypot system:

{$formattedMessages}

Generate a concise summary (3-4 sentences maximum) of these prior exchanges.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        // Use GPT-4o-mini for cost-effective summarization
        $options = [
            'max_tokens' => 200,
            'temperature' => 0.3,
            'model' => 'gpt-4o-mini',
            'purpose' => 'history_summary',
        ];

        return $this->llmClient->chat($messages, $options);
    }

    /**
     * Format messages into a readable text format for summarization
     *
     * @param list<array{conv_id: string, direction: string, subject: string|null, body: string, ts_msg: string}> $messages
     */
    private function formatMessagesForSummary(array $messages): string
    {
        $formatted = '';
        $currentConvId = null;
        $convNumber = 0;

        foreach ($messages as $msg) {
            // New conversation group
            if ($msg['conv_id'] !== $currentConvId) {
                $currentConvId = $msg['conv_id'];
                ++$convNumber;
                $formatted .= "\n[Conversation {$convNumber} - {$msg['ts_msg']}]\n";
            }

            $direction = $msg['direction'] === 'in' ? 'Scammer' : 'Victime';
            $subject = $msg['subject'] ? " [Sujet: {$msg['subject']}]" : '';

            // Sanitize UTF-8 encoding and truncate body to 150 characters
            $body = mb_convert_encoding($msg['body'], 'UTF-8', 'UTF-8');
            $body = strlen($body) > 150 ? mb_substr($body, 0, 150) . '...' : $body;
            $body = trim($body);

            $formatted .= "{$direction}{$subject} → {$body}\n";
        }

        return $formatted;
    }

    /**
     * Check if an email address should be excluded from summary generation
     *
     * This is used to exclude test email addresses from generating summaries
     * during manual testing to avoid polluting the summary with test data.
     */
    private function isEmailExcluded(string $email): bool
    {
        if ($this->excludedEmails === []) {
            return false;
        }

        // Normalize email for comparison (lowercase, trim)
        $normalizedEmail = strtolower(trim($email));

        foreach ($this->excludedEmails as $excludedEmail) {
            $normalizedExcluded = strtolower(trim($excludedEmail));

            if ($normalizedEmail === $normalizedExcluded) {
                return true;
            }
        }

        return false;
    }
}
