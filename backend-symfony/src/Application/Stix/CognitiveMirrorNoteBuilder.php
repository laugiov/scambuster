<?php

declare(strict_types=1);

namespace App\Application\Stix;

use App\Application\Scambaiting\PersonaMirrorReaderInterface;

/**
 * Spec 105 P3 — builds a STIX 2.1 Note SDO (OASIS §4.13) that carries the
 * Cognitive Mirror analysis (spec 104) for a (persona, scam type) pairing
 * deployed against a given threat-actor.
 *
 * The Note SDO is the natural STIX surface for "analyst editorial framing
 * attached to another SDO" — consumers (OpenCTI, MISP) render it as a free-
 * text annotation linked to the threat-actor via object_refs.
 *
 * Returns null when no persona_scam_mirror row exists for the pairing — a
 * threat-actor without a cached cognitive mirror is exported without a note,
 * not an error.
 */
final readonly class CognitiveMirrorNoteBuilder
{
    public function __construct(
        private PersonaMirrorReaderInterface $mirrors,
    ) {
    }

    /**
     * @return array<string, mixed>|null STIX Note SDO, or null if no mirror exists
     */
    public function build(string $threatActorId, string $personaCode, string $scamTypeCode): ?array
    {
        if ($threatActorId === '' || $personaCode === '' || $scamTypeCode === '') {
            return null;
        }

        $match = null;

        foreach ($this->mirrors->getByPersona($personaCode) as $mirror) {
            if (strcasecmp($mirror['scam_type_code'], $scamTypeCode) === 0) {
                $match = $mirror;

                break;
            }
        }

        if ($match === null) {
            return null;
        }

        $generatedAt = $match['generated_at'] !== '' ? $match['generated_at'] : '';
        $createdIso = $this->parseTimestampToIso($generatedAt);

        // Deterministic note id keyed on (threat-actor-id, scam-type-code) so
        // re-exporting the same threat-actor produces a stable note id for
        // diff-based consumers (dedupable across bundles).
        $noteId = 'note--' . $this->deterministicUuid(sprintf(
            'cognitive-mirror|%s|%s',
            $threatActorId,
            strtoupper($scamTypeCode),
        ));

        $abstract = sprintf(
            'ScamBuster Cognitive Mirror — persona "%s" against %s',
            $personaCode,
            $match['scam_type_label'] !== '' ? $match['scam_type_label'] : $scamTypeCode,
        );

        $content = sprintf(
            "Hunted victim profile: %s\n\nCognitive lever exploited: %s\n\nMirror analysis: %s",
            $match['hunted_victim_profile'],
            $match['cognitive_lever'],
            $match['mirror_explanation'],
        );

        return [
            'type' => 'note',
            'spec_version' => '2.1',
            'id' => $noteId,
            'created' => $createdIso,
            'modified' => $createdIso,
            'abstract' => $abstract,
            'content' => $content,
            'object_refs' => [$threatActorId],
            'labels' => ['scambuster-cognitive-mirror'],
            'x_scambuster_mirror' => [
                'schema_version' => '1.0',
                'persona_code' => $personaCode,
                'scam_type_code' => strtoupper($scamTypeCode),
                'hunted_victim_profile' => $match['hunted_victim_profile'],
                'cognitive_lever' => $match['cognitive_lever'],
                'mirror_explanation' => $match['mirror_explanation'],
                'generated_at' => $generatedAt,
                'generated_by_model' => $match['generated_by_model'],
                'prompt_version' => $match['prompt_version'],
            ],
        ];
    }

    /**
     * Mirror of StixBundleBuilder::deterministicUuid — kept private here to
     * preserve "all STIX ids derived in the Stix namespace" without a hard
     * dependency on the bundle builder.
     */
    private function deterministicUuid(string $input): string
    {
        $hash = md5($input);
        $hash[12] = '4';
        $hash[16] = dechex(hexdec($hash[16]) & 0x3 | 0x8);

        return substr($hash, 0, 8) . '-' . substr($hash, 8, 4) . '-'
            . substr($hash, 12, 4) . '-' . substr($hash, 16, 4) . '-' . substr($hash, 20, 12);
    }

    private function parseTimestampToIso(string $value): string
    {
        if ($value === '') {
            return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Exception) {
            return (new \DateTimeImmutable())->format('Y-m-d\TH:i:s.v\Z');
        }
    }
}
