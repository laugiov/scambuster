<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

use App\Application\Communication\IocHandler;
use App\Domain\Communication\Conversation;
use App\Domain\Communication\ConversationStatus;
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
    ) {}

    /**
     * Collecte les métriques d'une conversation et retourne un Value Object.
     *
     * @param Conversation $conversation Conversation terminée
     * @return ConversationMetrics Value Object avec métriques calculées
     * @throws \InvalidArgumentException Si les métriques sont invalides
     */
    public function collect(Conversation $conversation): ConversationMetrics
    {
        $convId = $conversation->getConvId();

        // 1. Récupérer la durée (depuis conversation.engagement_duration_sec)
        $durationSec = $conversation->getEngagementDurationSec();

        // 2. Récupérer le nombre de tours de parole (depuis conversation.turns_count)
        $turnsCount = $conversation->getTurnsCount();

        // 3. Récupérer les IOCs via IocHandler
        $iocs = $this->iocHandler->getConversationIocs($convId);
        $iocsTotal = count($iocs);

        // 4. Compter les IOCs sensibles (IBAN, phone, crypto_wallet)
        $iocsSensibles = $this->countSensitiveIocs($iocs);

        // 5. Déterminer si la conversation est "completed" (vs timeout/erreur)
        // Une conversation fermée manuellement via l'API est toujours "completed"
        // (vs timeout/erreur qui seraient gérés différemment - non implémenté actuellement)
        // NOTE: Ne pas utiliser $conversation->getStatus() car il n'est pas encore à CLOSED
        // au moment de l'appel depuis ConversationClosureService
        $isCompleted = true;

        $this->logger->debug('Conversation metrics collected', [
            'conv_id' => $convId,
            'duration_sec' => $durationSec,
            'turns_count' => $turnsCount,
            'iocs_total' => $iocsTotal,
            'iocs_sensibles' => $iocsSensibles,
            'is_completed' => $isCompleted,
        ]);

        // 6. Créer le Value Object (validation automatique)
        return new ConversationMetrics(
            durationSec: $durationSec,
            iocsTotal: $iocsTotal,
            iocsSensibles: $iocsSensibles,
            isCompleted: $isCompleted
        );
    }

    /**
     * Compte le nombre d'IOCs sensibles dans une liste d'ObservedIoc.
     * Méthode utilitaire pour éviter duplication de logique.
     *
     * Le type d'IOC est extrait du context JSON (clé 'type').
     * Types sensibles: IBAN, phone, crypto_wallet
     *
     * @param array<ObservedIoc> $iocs Liste d'IOCs
     * @return int Nombre d'IOCs sensibles
     */
    private function countSensitiveIocs(array $iocs): int
    {
        $sensitiveTypes = ['IBAN', 'phone', 'crypto_wallet'];
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
