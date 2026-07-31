<?php

declare(strict_types=1);

namespace App\Application\Ttp;

use App\Application\Audit\AuditLoggerInterface;
use App\Application\Communication\TtpManager;
use App\Application\LLM\TtpExtractor;
use App\Application\Ttp\Exception\OutgoingMessageException;
use App\Application\Ttp\Exception\TtpExtractionDisabledException;
use App\Domain\Audit\AuditEventType;
use App\Domain\Communication\Message;
use App\Domain\Communication\Policy\TtpExtractionPolicy;
use App\Domain\Communication\Ttp;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates TTP extraction for one message: loads the message, enforces the
 * inbound-only policy, runs the LLM extractor against the active taxonomy and
 * persists the resulting observations idempotently.
 *
 * Observations at or above the confidence threshold are stored as 'confirmed',
 * the rest as 'review' — nothing is silently dropped. Evidence verbatims are
 * persisted to the database only and never included in the returned payload,
 * so API responses cannot echo scammer-controlled text.
 */
final readonly class TtpHandler
{
    private const MODEL = 'gpt-4o-mini';
    private const PROMPT_VERSION = 'v1';

    public function __construct(
        private EntityManagerInterface $em,
        private TtpManager $ttpManager,
        private TtpExtractor $extractor,
        private TtpObservationUpsertService $upsert,
        private TtpExtractionPolicy $policy,
        private LoggerInterface $logger,
        private ?AuditLoggerInterface $auditLogger = null,
        private bool $enabled = true,
        private float $confidenceThreshold = 0.55,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Extract TTP observations from one message, optionally persisting them.
     *
     * @throws TtpExtractionDisabledException when the module is switched off
     * @throws OutgoingMessageException       when the message is not inbound
     * @throws \RuntimeException              when the message does not exist or is soft-deleted
     *
     * Evidence offsets (both here and in ttp_observation) are UTF-8 character
     * offsets into the analysed text, which is the concatenation
     * subject + "\n\n" + body — NOT the body alone. Any consumer highlighting
     * inside body_text must subtract mb_strlen(subject) + 2, or simply relocate
     * the verbatim evidence string, which remains the source of truth.
     *
     * @return array{
     *     msg_id: string,
     *     ttps_found: int,
     *     persisted: int,
     *     observations: list<array{ttp_code: string, confidence: float, status: string, evidence_start: int|null, evidence_end: int|null}>
     * }
     */
    public function extractForMessage(string $msgId, bool $persist = true): array
    {
        if (!$this->enabled) {
            throw new TtpExtractionDisabledException();
        }

        $message = $this->em->getRepository(Message::class)->find($msgId);

        if (!$message instanceof Message || $message->getDeletedAt() !== null) {
            throw new \RuntimeException("Message not found: {$msgId}");
        }

        if (!$this->policy->allows($message)) {
            throw new OutgoingMessageException($msgId, $message->getDirection()->getCode());
        }

        // One taxonomy query feeds both the extractor vocabulary and FK resolution.
        $taxonomy = [];
        /** @var array<string, Ttp> $ttpsByCode */
        $ttpsByCode = [];

        foreach ($this->ttpManager->allActive() as $ttp) {
            $taxonomy[] = ['code' => $ttp->getCode(), 'definition' => $ttp->getDefinition()];
            $ttpsByCode[$ttp->getCode()] = $ttp;
        }

        $text = ($message->getSubject() ?? '') . "\n\n" . $message->getBodyText();
        $items = $this->extractor->extract($text, $taxonomy);

        $convId = $message->getConversation()->getConvId();
        $observations = [];
        $persisted = 0;

        foreach ($items as $item) {
            $status = $item['confidence'] >= $this->confidenceThreshold ? 'confirmed' : 'review';

            // Evidence text stays out of the return payload: verbatims live in DB only.
            $observations[] = [
                'ttp_code' => $item['ttp_code'],
                'confidence' => $item['confidence'],
                'status' => $status,
                'evidence_start' => $item['evidence_start'],
                'evidence_end' => $item['evidence_end'],
            ];

            if (!$persist) {
                continue;
            }

            $ttp = $ttpsByCode[$item['ttp_code']] ?? null;

            if (!$ttp instanceof Ttp) {
                // The extractor post-validates against the taxonomy, so this is
                // a defensive branch only (e.g. a row deactivated mid-flight).
                $this->logger->warning('[TtpHandler] Extracted code has no active taxonomy row, skipping persistence', [
                    'msg_id' => $msgId,
                    'ttp_code' => $item['ttp_code'],
                ]);

                continue;
            }

            try {
                $inserted = $this->upsert->upsert([
                    'msg_id' => $message->getMsgId(),
                    'conv_id' => $convId,
                    'ttp_id' => $ttp->getTtpId(),
                    'confidence' => $item['confidence'],
                    'evidence' => $item['evidence'],
                    'evidence_start' => $item['evidence_start'],
                    'evidence_end' => $item['evidence_end'],
                    'status' => $status,
                    'taxonomy_version' => Ttp::TAXONOMY_VERSION,
                    'extraction_model' => self::MODEL,
                    'prompt_version' => self::PROMPT_VERSION,
                ]);
            } catch (\Throwable $e) {
                // One bad row must never abort the whole batch.
                $this->logger->error('[TtpHandler] Failed to persist TTP observation, continuing', [
                    'msg_id' => $msgId,
                    'ttp_code' => $item['ttp_code'],
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (!$inserted) {
                continue;
            }

            ++$persisted;

            $this->auditLogger?->log(
                AuditEventType::TTP_EXTRACTED,
                $convId,
                'ttp_extracted',
                'success',
                'ttp_observation',
                $message->getMsgId(),
                [
                    'ttp_code' => $item['ttp_code'],
                    'confidence' => $item['confidence'],
                    'status' => $status,
                    'source' => 'ttp_extraction',
                ],
            );
        }

        return [
            'msg_id' => $message->getMsgId(),
            'ttps_found' => \count($observations),
            'persisted' => $persisted,
            'observations' => $observations,
        ];
    }
}
