<?php

declare(strict_types=1);

namespace App\Application\LLM;

/**
 * Value Object representing the result of LLM contextual enrichment.
 *
 * Produced by ContextualEnricher, consumed by IngestPostProcessor to
 * update ioc_context rows with semantic fields.
 */
final readonly class ContextualEnrichmentResult
{
    /** @var list<string> Valid semantic roles for IOCs */
    public const VALID_ROLES = [
        'PAYMENT_DESTINATION',
        'PAYMENT_REDIRECT_URL',
        'PHISHING_CREDENTIAL_URL',
        'MALWARE_DOWNLOAD_URL',
        'CONTACT_CHANNEL',
        'IDENTITY_DOCUMENT',
        'VERIFICATION_CODE_URL',
        'INFRASTRUCTURE_DOMAIN',
        'MONEY_MULE_ACCOUNT',
        'TRACKING_REFERENCE',
        'UNKNOWN',
    ];

    /** @var list<string> Valid stimulus types */
    public const VALID_STIMULUS_TYPES = [
        'URGENCY_PRESSURE',
        'TRUST_BUILDING',
        'DIRECT_REQUEST',
        'DOCUMENT_REQUEST',
        'PAYMENT_INITIATION',
        'PASSIVE',
        'UNKNOWN',
    ];

    /**
     * @param string               $stimulusType         What triggered the IOC revelation
     * @param float                $urgencyScore         Scammer urgency score [0.0, 1.0]
     * @param bool                 $languageSwitch       Whether a language switch was detected
     * @param bool                 $hesitationDetected   Whether hesitation was detected in the scammer
     * @param string               $contextExcerpt       Short narrative excerpt
     * @param float                $enrichmentConfidence LLM confidence in its enrichment [0.0, 1.0]
     * @param array<string,string> $iocRoles             Map of IOC type => semantic role
     */
    public function __construct(
        public string $stimulusType,
        public float $urgencyScore,
        public bool $languageSwitch,
        public bool $hesitationDetected,
        public string $contextExcerpt,
        public float $enrichmentConfidence,
        public array $iocRoles,
    ) {
    }

    /**
     * Build from raw LLM JSON response with validation and defaults.
     *
     * @param array<string,mixed> $data              Decoded JSON from LLM
     * @param list<string>        $iocTypes          IOC types to map roles for
     * @param int                 $availableMessages How many of the 3-message window were available (1-3)
     */
    public static function fromLlmResponse(array $data, array $iocTypes, int $availableMessages = 3): self
    {
        // Validate stimulus_type
        $stimulusType = \is_string($data['stimulus_type'] ?? null) ? $data['stimulus_type'] : 'UNKNOWN';

        if (!\in_array($stimulusType, self::VALID_STIMULUS_TYPES, true)) {
            $stimulusType = 'UNKNOWN';
        }

        // Clamp urgency_score to [0.0, 1.0]
        $urgencyScore = \is_numeric($data['scammer_urgency_score'] ?? null)
            ? (float) $data['scammer_urgency_score']
            : 0.0;
        $urgencyScore = max(0.0, min(1.0, $urgencyScore));

        // Boolean fields
        $languageSwitch = (bool) ($data['language_switch_detected'] ?? false);
        $hesitationDetected = (bool) ($data['hesitation_detected'] ?? false);

        // Context excerpt
        $contextExcerpt = \is_string($data['context_excerpt'] ?? null)
            ? mb_substr($data['context_excerpt'], 0, 295)
            : '';

        // Enrichment confidence clamped to [0.0, 1.0]
        $enrichmentConfidence = \is_numeric($data['enrichment_confidence'] ?? null)
            ? (float) $data['enrichment_confidence']
            : 0.0;
        $enrichmentConfidence = max(0.0, min(1.0, $enrichmentConfidence));

        // Cap confidence based on available context window + richness bonuses.
        // Base cap by message count (lowered from 0.60/0.80/1.0):
        $baseCap = match (true) {
            $availableMessages <= 1 => 0.50,
            $availableMessages === 2 => 0.70,
            default => 0.90,
        };

        // Richness bonuses (up to +0.30)
        $richness = 0.0;

        // Message length bonus
        $stimulusMessage = \is_string($data['stimulus_message'] ?? null) ? $data['stimulus_message'] : '';
        $messageLength = mb_strlen($stimulusMessage);

        if ($messageLength > 200) {
            $richness += 0.10;
        }

        // IOC count bonus (from the ioc_types field)
        /** @var list<string> $iocTypesFromData */
        $iocTypesFromData = \is_array($data['ioc_types'] ?? null) ? $data['ioc_types'] : [];
        $iocCount = \count($iocTypesFromData);

        if ($iocCount > 3) {
            $richness += 0.10;
        }

        // Urgency pattern bonus (deadline/threat detected)
        $hasUrgencyPattern = (bool) preg_match(
            '/\b(deadline|expires?|urgent|immediate|hours?|legal action|suspend|closure)\b/i',
            $stimulusMessage,
        );

        if ($hasUrgencyPattern) {
            $richness += 0.10;
        }

        $maxConfidence = min($baseCap + $richness, 1.0);
        $enrichmentConfidence = min($enrichmentConfidence, $maxConfidence);

        // Map ioc_roles from LLM format [{"type": "url", "role": "..."}] to associative
        $iocRoles = self::mapIocRoles($data['ioc_roles'] ?? [], $iocTypes);

        return new self(
            stimulusType: $stimulusType,
            urgencyScore: $urgencyScore,
            languageSwitch: $languageSwitch,
            hesitationDetected: $hesitationDetected,
            contextExcerpt: $contextExcerpt,
            enrichmentConfidence: $enrichmentConfidence,
            iocRoles: $iocRoles,
        );
    }

    /**
     * Map LLM ioc_roles array to associative type => role, with validation.
     *
     * @param mixed        $rawRoles Raw ioc_roles from LLM (expected: array of {type, role})
     * @param list<string> $iocTypes All IOC types that need a role
     *
     * @return array<string,string>
     */
    private static function mapIocRoles(mixed $rawRoles, array $iocTypes): array
    {
        $mapped = [];

        if (\is_array($rawRoles)) {
            foreach ($rawRoles as $entry) {
                if (!\is_array($entry)) {
                    continue;
                }

                $type = \is_string($entry['type'] ?? null) ? $entry['type'] : '';
                $role = \is_string($entry['role'] ?? null) ? $entry['role'] : 'UNKNOWN';

                if ($type === '') {
                    continue;
                }

                if (!\in_array($role, self::VALID_ROLES, true)) {
                    $role = 'UNKNOWN';
                }

                $mapped[$type] = $role;
            }
        }

        // Fill in missing IOC types with UNKNOWN
        foreach ($iocTypes as $type) {
            if (!isset($mapped[$type])) {
                $mapped[$type] = 'UNKNOWN';
            }
        }

        return $mapped;
    }
}
