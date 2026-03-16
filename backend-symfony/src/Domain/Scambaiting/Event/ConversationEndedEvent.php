<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Domain Event déclenché quand une conversation se termine.
 * Permet de calculer le reward et mettre à jour les stats de performance.
 *
 * ⚠️ EXCEPTION : Cet event hérite de Symfony\Event pour être compatible
 * avec le système d'event dispatching du projet. C'est un compromis acceptable
 * car l'Event Dispatcher est une abstraction standard (PSR-14 compatible).
 */
final class ConversationEndedEvent extends Event
{
    /**
     * @param string      $conversationId UUID de la conversation terminée
     * @param string      $scamTypeCode   Code du scam type (ex: 'PHISHING')
     * @param string|null $personaCode    Code du persona utilisé (null si aucun)
     * @param int         $durationSec    Durée de la conversation en secondes
     * @param int         $turnsCount     Nombre de tours de parole
     * @param int         $iocsTotal      Nombre total d'IOCs capturés
     * @param int         $iocsSensibles  Nombre d'IOCs haute valeur
     * @param bool        $isCompleted    True si terminée normalement (vs timeout/erreur)
     */
    public function __construct(
        private readonly string $conversationId,
        private readonly string $scamTypeCode,
        private readonly ?string $personaCode,
        private readonly int $durationSec,
        private readonly int $turnsCount,
        private readonly int $iocsTotal,
        private readonly int $iocsSensibles,
        private readonly bool $isCompleted
    ) {
    }

    public function getConversationId(): string
    {
        return $this->conversationId;
    }

    public function getScamTypeCode(): string
    {
        return $this->scamTypeCode;
    }

    public function getPersonaCode(): ?string
    {
        return $this->personaCode;
    }

    public function getDurationSec(): int
    {
        return $this->durationSec;
    }

    public function getTurnsCount(): int
    {
        return $this->turnsCount;
    }

    public function getIocsTotal(): int
    {
        return $this->iocsTotal;
    }

    public function getIocsSensibles(): int
    {
        return $this->iocsSensibles;
    }

    public function isCompleted(): bool
    {
        return $this->isCompleted;
    }

    /**
     * Vérifie si l'événement concerne une conversation avec persona assigné.
     * Les conversations sans persona ne participent pas à l'apprentissage.
     */
    public function hasPersona(): bool
    {
        return $this->personaCode !== null;
    }

    /**
     * Représentation textuelle pour logging.
     */
    public function __toString(): string
    {
        return sprintf(
            'ConversationEndedEvent(conv=%d, scamType=%s, persona=%s, duration=%ds, turns=%d, iocs=%d/%d, completed=%s)',
            $this->conversationId,
            $this->scamTypeCode,
            $this->personaCode ?? 'null',
            $this->durationSec,
            $this->turnsCount,
            $this->iocsSensibles,
            $this->iocsTotal,
            $this->isCompleted ? 'yes' : 'no'
        );
    }
}
