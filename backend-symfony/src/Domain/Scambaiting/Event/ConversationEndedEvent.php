<?php

declare(strict_types=1);

namespace App\Domain\Scambaiting\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Domain Event dispatched when a conversation ends.
 * Allows computing the reward and updating performance stats.
 *
 * EXCEPTION: This event extends Symfony\Event for compatibility
 * with the project's event dispatching system. This is an acceptable tradeoff
 * car l'Event Dispatcher est une abstraction standard (PSR-14 compatible).
 */
final class ConversationEndedEvent extends Event implements \Stringable
{
    /**
     * @param string      $conversationId UUID of the ended conversation
     * @param string      $scamTypeCode   Code du scam type (ex: 'PHISHING')
     * @param string|null $personaCode    Code of the persona used (null if none)
     * @param int         $durationSec    Conversation duration in seconds
     * @param int         $turnsCount     Nombre de tours de parole
     * @param int         $iocsTotal      Total number of captured IOCs
     * @param int         $iocsSensibles  Nombre d'IOCs haute valeur
     * @param bool        $isCompleted    True if completed normally (vs timeout/error)
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
     * Checks if the event concerns a conversation with an assigned persona.
     * Conversations without a persona do not participate in learning.
     */
    public function hasPersona(): bool
    {
        return $this->personaCode !== null;
    }

    /**
     * String representation for logging.
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
