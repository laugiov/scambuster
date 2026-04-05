<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Value Object representing a contextual enrichment request for LLM analysis.
 *
 * Contains the 3-message window (previous outbound, stimulus/revelation inbound,
 * and optionally the previous inbound) plus conversation metadata.
 */
final class ContextualEnrichmentRequest
{
    /**
     * @param list<string> $iocTypes              IOC types found in the revelation message (e.g., ['url', 'iban'])
     * @param string       $scamType              Scam type code of the conversation
     * @param string       $personaCode           Persona code used by the honeypot
     * @param int          $revelationTurn        Turn index where IOCs were revealed
     * @param int          $totalTurns            Total turns in conversation so far
     * @param string       $revelationMessageText Body text of the message containing the IOCs
     * @param string|null  $stimulusMessageText   Body text of our outbound that triggered the IOC reveal
     * @param string|null  $previousInboundText   Body text of the previous inbound before our stimulus
     */
    public function __construct(
        public readonly array $iocTypes,
        public readonly string $scamType,
        public readonly string $personaCode,
        public readonly int $revelationTurn,
        public readonly int $totalTurns,
        public readonly string $revelationMessageText,
        public readonly ?string $stimulusMessageText,
        public readonly ?string $previousInboundText,
    ) {
    }
}
