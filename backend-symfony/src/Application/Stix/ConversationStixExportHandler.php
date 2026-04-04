<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Application\Communication\IocHandler;
use App\Domain\Communication\Conversation;
use Doctrine\ORM\EntityManagerInterface;

final class ConversationStixExportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IocHandler $iocHandler,
        private readonly StixBundleBuilder $bundleBuilder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function export(string $convId): array
    {
        $conversation = $this->em->getRepository(Conversation::class)->find($convId);

        if (!$conversation) {
            throw new \RuntimeException('Conversation not found');
        }

        // Get conversation IOCs (deduplicated)
        $observedIocs = $this->iocHandler->getConversationIocs($convId);

        if (empty($observedIocs)) {
            return $this->bundleBuilder->buildBundle(
                [],
                [],
                $conversation->getTlp(),
                'ScamBuster - ' . $convId,
            );
        }

        // Build IOC data array for the builder
        $iocs = [];

        foreach ($observedIocs as $observedIoc) {
            $context = $observedIoc->getContext();
            $indicatorId = $observedIoc->getIndicatorId();

            $iocs[] = [
                'indicator_id' => $indicatorId,
                'type' => is_string($context['type'] ?? null) ? $context['type'] : 'unknown',
                'value' => is_string($context['value'] ?? null) ? $context['value'] : '',
                'value_norm' => is_string($context['value_norm'] ?? null) ? $context['value_norm'] : '',
                'first_seen' => $observedIoc->getTsObserved()->format('Y-m-d H:i:s'),
                'last_seen' => $observedIoc->getTsObserved()->format('Y-m-d H:i:s'),
                'confidence' => $observedIoc->getConfidenceScore(),
                'extraction_method' => is_string($context['extraction_method'] ?? null) ? $context['extraction_method'] : (is_string($context['source'] ?? null) ? $context['source'] : 'unknown'),
                'score' => is_array($context['score'] ?? null) ? $context['score'] : [],
                'scam_type' => $conversation->getScamType()->getCode(),
            ];
        }

        // Build relationships from co-occurrence (IOCs in same conversation are related)
        $relationships = [];

        for ($i = 0; $i < \count($iocs); ++$i) {
            for ($j = $i + 1; $j < \count($iocs); ++$j) {
                $headerTypes = ['message_id', 'subject', 'spf_result', 'dkim_result', 'dmarc_result', 'x_mailer', 'return_path'];

                if (\in_array($iocs[$i]['type'], $headerTypes, true) || \in_array($iocs[$j]['type'], $headerTypes, true)) {
                    continue;
                }

                $relationships[] = [
                    'source_indicator_id' => $iocs[$i]['indicator_id'],
                    'target_indicator_id' => $iocs[$j]['indicator_id'],
                    'source_type' => $iocs[$i]['type'],
                    'source_value_norm' => $iocs[$i]['value_norm'],
                    'target_type' => $iocs[$j]['type'],
                    'target_value_norm' => $iocs[$j]['value_norm'],
                    'weight' => 1,
                ];
            }
        }

        $scamType = $conversation->getScamType()->getCode();

        return $this->bundleBuilder->buildBundle(
            $iocs,
            $relationships,
            $conversation->getTlp(),
            "ScamBuster - {$scamType} conversation {$convId}",
            "IOCs extracted from {$scamType} scam conversation tracked by ScamBuster honeypot",
        );
    }
}
