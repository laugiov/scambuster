<?php

declare(strict_types=1);

namespace App\Application\Audit;

use App\Application\LLM\Port\LLMClientInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Audits a single conversation's data quality using an independent LLM.
 *
 * Uses gpt-4o (NOT gpt-4o-mini) with a contradictory prompt designed
 * to find errors in the automated pipeline outputs. This provides
 * independent cross-validation of classification, IOC extraction,
 * urgency scoring, semantic roles, and risk scoring.
 *
 * NOT final -- must be mockable in tests.
 */
class ConversationQualityAuditor
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
You are an independent security intelligence analyst performing a quality
audit of automatically extracted threat intelligence data. Your role is
to verify that the automated system's outputs are accurate and consistent
with the raw evidence.

You must be critical and objective. If you disagree with any automated
assessment, explain WHY with specific evidence from the message text.

Respond in JSON format only.
PROMPT;

    public function __construct(
        private readonly LLMClientInterface $llmClient,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Audit a single conversation's data quality using an independent LLM.
     *
     * @return array{classification: array<string, mixed>, ioc_completeness: array<string, mixed>, urgency: array<string, mixed>, semantic_roles: array<string, mixed>, risk_score: array<string, mixed>, overall_agreement: float}|null
     */
    public function audit(string $convId): ?array
    {
        try {
            $conversation = $this->fetchConversation($convId);

            if ($conversation === null) {
                $this->logger->warning('[QualityAuditor] Conversation not found', ['conv_id' => $convId]);

                return null;
            }

            $firstMessage = $this->fetchFirstInboundMessage($convId);

            if ($firstMessage === null) {
                $this->logger->debug('[QualityAuditor] No inbound messages for conversation', ['conv_id' => $convId]);

                return null;
            }

            $msgId = \is_string($firstMessage['msg_id']) ? $firstMessage['msg_id'] : '';
            $iocs = $this->fetchIocsForMessage($msgId);

            $userPrompt = $this->buildUserPrompt($conversation, $firstMessage, $iocs);

            $messages = [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $options = [
                'model' => 'gpt-4o',
                'temperature' => 0.2,
                'max_tokens' => 1000,
                'purpose' => 'quality_audit',
            ];

            $this->logger->debug('[QualityAuditor] Calling LLM for audit', ['conv_id' => $convId]);

            $response = $this->llmClient->chat($messages, $options);

            $jsonText = $this->extractJson($response);
            $data = json_decode($jsonText, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                $this->logger->warning('[QualityAuditor] LLM response is not a JSON object', [
                    'conv_id' => $convId,
                    'response' => substr($response, 0, 200),
                ]);

                return null;
            }

            /** @var array<string, mixed> $data */

            return $this->normalizeResult($data);
        } catch (\Throwable $e) {
            $this->logger->warning('[QualityAuditor] Audit failed', [
                'conv_id' => $convId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchConversation(string $convId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.conv_id, st.code AS scam_type, c.score_risk, c.status
             FROM conversation c
             LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
             WHERE c.conv_id = :convId AND c.deleted_at IS NULL',
            ['convId' => $convId],
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchFirstInboundMessage(string $convId): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT m.msg_id, LEFT(m.body_text, 500) AS body_text
             FROM message m
             WHERE m.conv_id = :convId AND m.direction = 'in'
             ORDER BY m.ts_received ASC
             LIMIT 1",
            ['convId' => $convId],
        );

        return \is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchIocsForMessage(string $msgId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT i.type, i.value_norm AS value, ic.semantic_role, ic.urgency_score, ic.stimulus_type
             FROM observed_ioc oi
             JOIN indicator i ON oi.indicator_id = i.indicator_id
             LEFT JOIN ioc_context ic ON oi.obs_id = ic.obs_id
             WHERE oi.msg_id = :msgId',
            ['msgId' => $msgId],
        );
    }

    /**
     * @param array<string, mixed>             $conversation
     * @param array<string, mixed>             $firstMessage
     * @param array<int, array<string, mixed>> $iocs
     */
    private function buildUserPrompt(array $conversation, array $firstMessage, array $iocs): string
    {
        $scamType = \is_string($conversation['scam_type'] ?? null) ? $conversation['scam_type'] : 'UNKNOWN';
        $riskScore = \is_numeric($conversation['score_risk'] ?? null) ? (int) $conversation['score_risk'] : 0;
        $bodyText = \is_string($firstMessage['body_text'] ?? null) ? $firstMessage['body_text'] : '(empty)';

        $iocLines = '';

        foreach ($iocs as $ioc) {
            $type = \is_string($ioc['type'] ?? null) ? $ioc['type'] : 'unknown';
            $value = \is_string($ioc['value'] ?? null) ? $ioc['value'] : '';
            $role = \is_string($ioc['semantic_role'] ?? null) ? $ioc['semantic_role'] : 'N/A';
            $urgency = \is_numeric($ioc['urgency_score'] ?? null) ? round((float) $ioc['urgency_score'], 2) : 'N/A';
            $iocLines .= "- type: {$type}, value: {$value}, semantic_role: {$role}, urgency_score: {$urgency}\n";
        }

        if ($iocLines === '') {
            $iocLines = "(no IOCs extracted)\n";
        }

        $urgencyScore = 'N/A';

        foreach ($iocs as $ioc) {
            if (\is_numeric($ioc['urgency_score'] ?? null)) {
                $urgencyScore = (string) round((float) $ioc['urgency_score'], 2);

                break;
            }
        }

        return <<<PROMPT
## Conversation to audit

Scam type assigned: {$scamType}
Risk score: {$riskScore}/100

## First scammer message:
{$bodyText}

## Extracted IOCs:
{$iocLines}
## Your audit (JSON):
{
  "classification": {
    "verdict": "AGREE" or "DISAGREE",
    "assigned": "{$scamType}",
    "suggested": "your suggestion if DISAGREE",
    "reasoning": "why"
  },
  "ioc_completeness": {
    "verdict": "COMPLETE" or "INCOMPLETE",
    "missed_iocs": ["list of IOCs visible in message but not extracted"],
    "reasoning": "why"
  },
  "urgency": {
    "verdict": "AGREE" or "DISAGREE",
    "assigned_score": {$urgencyScore},
    "suggested_score": "your suggestion",
    "reasoning": "why"
  },
  "semantic_roles": {
    "verdict": "AGREE" or "DISAGREE",
    "issues": ["list of role assignment issues"],
    "reasoning": "why"
  },
  "risk_score": {
    "verdict": "AGREE" or "DISAGREE",
    "assigned": {$riskScore},
    "suggested": "your suggestion",
    "reasoning": "why"
  }
}
PROMPT;
    }

    /**
     * Extract JSON from LLM response (handles markdown code blocks).
     */
    private function extractJson(string $response): string
    {
        $response = trim($response);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $response, $matches)) {
            return $matches[1];
        }

        if (preg_match('/(\{.*\})/s', $response, $matches)) {
            return $matches[1];
        }

        return $response;
    }

    /**
     * Normalize the LLM audit result into a structured array with 5 dimensions + overall agreement.
     *
     * @param array<string, mixed> $data
     *
     * @return array{classification: array<string, mixed>, ioc_completeness: array<string, mixed>, urgency: array<string, mixed>, semantic_roles: array<string, mixed>, risk_score: array<string, mixed>, overall_agreement: float}
     */
    private function normalizeResult(array $data): array
    {
        $dimensions = ['classification', 'ioc_completeness', 'urgency', 'semantic_roles', 'risk_score'];
        $agreementVerdicts = ['AGREE', 'COMPLETE'];
        $agreeCount = 0;

        $result = [];

        foreach ($dimensions as $dim) {
            $dimData = \is_array($data[$dim] ?? null) ? $data[$dim] : ['verdict' => 'UNKNOWN', 'reasoning' => 'Missing from LLM response'];
            $result[$dim] = $dimData;

            $verdict = \is_string($dimData['verdict'] ?? null) ? strtoupper($dimData['verdict']) : 'UNKNOWN';

            if (\in_array($verdict, $agreementVerdicts, true)) {
                ++$agreeCount;
            }
        }

        $result['overall_agreement'] = round($agreeCount / \count($dimensions), 2);

        /** @var array{classification: array<string, mixed>, ioc_completeness: array<string, mixed>, urgency: array<string, mixed>, semantic_roles: array<string, mixed>, risk_score: array<string, mixed>, overall_agreement: float} $result */

        return $result;
    }
}
