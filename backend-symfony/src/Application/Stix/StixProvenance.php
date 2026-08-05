<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Producer identity and TLP marking definitions shared by the STIX emitters.
 *
 * STIX requires that `created_by_ref` and `object_marking_refs` resolve inside
 * the bundle or envelope that carries them. The TAXII feed used to reference
 * both without ever shipping the SDOs, so consumers received dangling pointers:
 * on a fresh OpenCTI the producer is simply lost and the feed's objects cannot
 * be attributed to ScamBuster or filtered by TLP.
 *
 * The UUIDs are the canonical OASIS TLP marking-definition ids, matching what
 * {@see StixBundleBuilder} emits for the per-conversation export, so the same
 * object ingested through either path deduplicates instead of forking.
 */
final class StixProvenance
{
    public const IDENTITY_ID = 'identity--f431f809-377b-45e0-aa1c-6a4751cae5ff';

    /** Canonical OASIS TLP marking-definition ids, keyed by normalised label. */
    public const TLP_MARKING = [
        'WHITE' => 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9',
        'CLEAR' => 'marking-definition--613f2e26-407d-48c7-9eca-b8e91df99dc9',
        'GREEN' => 'marking-definition--34098fce-860f-48ae-8e50-ebd3cc5e41da',
        'AMBER' => 'marking-definition--f88d31f6-486f-44da-b317-01333bde0b82',
        'AMBER+STRICT' => 'marking-definition--939a9414-2ddd-4d32-a0cd-375ea402b003',
        'RED' => 'marking-definition--5e57c739-391a-4eb3-b6be-7d15ca92d5ed',
    ];

    /** Default when a row carries no usable TLP. Matches the feed's shared-by-default posture. */
    private const DEFAULT_TLP = 'AMBER';

    /**
     * Normalise a stored TLP ("TLP:amber", "tlp_green", "AMBER") to its label.
     */
    public static function normaliseTlp(?string $tlp): string
    {
        if (!\is_string($tlp) || trim($tlp) === '') {
            return self::DEFAULT_TLP;
        }

        $label = strtoupper(trim(preg_replace('/^TLP[_:]/i', '', trim($tlp)) ?? $tlp));

        return isset(self::TLP_MARKING[$label]) ? $label : self::DEFAULT_TLP;
    }

    /**
     * The marking-definition id for a stored TLP value.
     */
    public static function markingRefFor(?string $tlp): string
    {
        return self::TLP_MARKING[self::normaliseTlp($tlp)];
    }

    /**
     * The producer identity SDO. Must travel with anything that points at it.
     *
     * @return array<string, mixed>
     */
    public static function identitySdo(): array
    {
        return [
            'type' => 'identity',
            'spec_version' => '2.1',
            'id' => self::IDENTITY_ID,
            'created' => '2025-12-01T00:00:00.000Z',
            'modified' => '2025-12-01T00:00:00.000Z',
            'name' => 'ScamBuster Threat Intelligence',
            'description' => 'Automated scambaiting honeypot for threat intelligence collection',
            'identity_class' => 'system',
        ];
    }

    /**
     * The marking-definition SDO for a marking id already referenced elsewhere.
     *
     * @return array<string, mixed>|null null when the id is not a TLP we emit
     */
    public static function markingSdo(string $markingRef): ?array
    {
        $label = null;

        foreach (self::TLP_MARKING as $candidate => $id) {
            // WHITE and CLEAR share an id; CLEAR is the TLP 2.0 spelling and wins.
            if ($id === $markingRef && ($label === null || $candidate === 'CLEAR')) {
                $label = $candidate;
            }
        }

        if ($label === null) {
            return null;
        }

        return [
            'type' => 'marking-definition',
            'spec_version' => '2.1',
            'id' => $markingRef,
            'created' => '2017-01-20T00:00:00.000Z',
            'definition_type' => 'tlp',
            'name' => 'TLP:' . $label,
            'definition' => ['tlp' => strtolower($label)],
        ];
    }

    /**
     * Append the identity and marking-definition SDOs that the given objects
     * reference, so every `created_by_ref` / `object_marking_refs` resolves
     * within the envelope. Idempotent: SDOs already present are not duplicated.
     *
     * Appended rather than prepended: STIX defines no ordering, but existing
     * consumers (and tests) read `objects[0]` as the first real object.
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return list<array<string, mixed>>
     */
    public static function withReferencedSdos(array $objects): array
    {
        if ($objects === []) {
            return $objects;
        }

        $present = [];
        $needsIdentity = false;
        $markingRefs = [];

        foreach ($objects as $object) {
            if (\is_string($object['id'] ?? null)) {
                $present[$object['id']] = true;
            }

            if (($object['created_by_ref'] ?? null) === self::IDENTITY_ID) {
                $needsIdentity = true;
            }

            $refs = $object['object_marking_refs'] ?? null;

            if (\is_array($refs)) {
                foreach ($refs as $ref) {
                    if (\is_string($ref)) {
                        $markingRefs[$ref] = true;
                    }
                }
            }
        }

        $extra = [];

        foreach (array_keys($markingRefs) as $ref) {
            if (isset($present[$ref])) {
                continue;
            }

            $sdo = self::markingSdo($ref);

            if ($sdo !== null) {
                $extra[] = $sdo;
            }
        }

        if ($needsIdentity && !isset($present[self::IDENTITY_ID])) {
            // Left unmarked on purpose: the producer identity is not
            // intelligence, and marking it TLP:CLEAR would drag an extra
            // marking-definition into envelopes that share nothing at that level.
            $extra[] = self::identitySdo();
        }

        return [...$objects, ...$extra];
    }
}
