<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\Communication\IocHandler;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ObservedIoc;
use App\Domain\Scambaiting\ConversationMetrics;
use Psr\Log\LoggerInterface;

/**
 * Service pour collecter les métriques d'une conversation terminée.
 * Utilise les services existants (IocHandler) pour éviter duplication.
 */
class ConversationMetricsCollector
{
    public function __construct(
        private readonly IocHandler $iocHandler,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Collecte les métriques d'une conversation et retourne un Value Object.
     *
     * @param Conversation $conversation Conversation terminée
     *
     * @throws \InvalidArgumentException Si les métriques sont invalides
     *
     * @return ConversationMetrics Value Object avec métriques calculées
     */
    /**
     * Collect metrics for a closed conversation and return a Value Object.
     *
     * engagement_duration_sec and turns_count must be set on the Conversation
     * entity BEFORE calling this method (done by ConversationClosureService).
     *
     * @param Conversation $conversation Conversation to collect metrics for
     * @param bool         $isCompleted  Whether the conversation ended naturally (true) or by timeout (false)
     */
    public function collect(Conversation $conversation, bool $isCompleted = true): ConversationMetrics
    {
        $convId = $conversation->getConvId();
        $durationSec = $conversation->getEngagementDurationSec();

        $iocs = $this->iocHandler->getConversationIocs($convId);
        $iocsTotal = count($iocs);
        $iocsSensibles = $this->countSensitiveIocs($iocs);

        $this->logger->debug('Conversation metrics collected', [
            'conv_id' => $convId,
            'duration_sec' => $durationSec,
            'iocs_total' => $iocsTotal,
            'iocs_sensibles' => $iocsSensibles,
            'is_completed' => $isCompleted,
        ]);

        return new ConversationMetrics(
            durationSec: $durationSec,
            iocsTotal: $iocsTotal,
            iocsSensibles: $iocsSensibles,
            isCompleted: $isCompleted,
        );
    }

    /**
     * Compte le nombre d'IOCs sensibles dans une liste d'ObservedIoc.
     * Méthode utilitaire pour éviter duplication de logique.
     *
     * Le type d'IOC est extrait du context JSON (clé 'type').
     * Types sensibles: IBAN, phone, crypto_wallet, telegram_username, url
     *
     * @param array<ObservedIoc> $iocs Liste d'IOCs
     *
     * @return int Nombre d'IOCs sensibles
     */
    private function countSensitiveIocs(array $iocs): int
    {
        $sensitiveTypes = ['IBAN', 'phone', 'crypto_wallet', 'telegram_username', 'url'];
        $count = 0;

        foreach ($iocs as $ioc) {
            $context = $ioc->getContext();
            $type = $context['type'] ?? null;

            if ($type !== null && in_array($type, $sensitiveTypes, true)) {
                $count++;
            }
        }

        return $count;
    }
}
