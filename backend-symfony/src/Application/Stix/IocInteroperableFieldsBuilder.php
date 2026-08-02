<?php

declare(strict_types=1);

namespace App\Application\Stix;

/**
 * Projects ScamBuster's IOC context onto the STIX properties consumers keep.
 *
 * The elicitation story — which stimulus produced the revelation, at which turn,
 * what role the IOC plays in the scam narrative — lived only in the custom
 * `x_scambuster_context` property-extension. That is valid STIX, but platforms
 * persist the properties they model and drop unknown extensions: ingested into
 * OpenCTI, those indicators arrived with a null description and a single
 * `malicious-activity` label, i.e. stripped of everything that distinguishes
 * ScamBuster from any other IOC feed.
 *
 * This builder mirrors that context into `description`, `labels` and
 * `external_references`, which every STIX consumer stores. The extension is
 * still emitted alongside for consumers that do understand it.
 */
final class IocInteroperableFieldsBuilder
{
    /** Kept first for backward compatibility with existing consumers. */
    private const BASE_LABEL = 'malicious-activity';

    /** Marks the producer in platforms whose label filter is the quickest pivot. */
    private const SOURCE_LABEL = 'scambuster';

    /** Excerpts are PII-free by construction, but stay defensive about length. */
    private const MAX_EXCERPT = 400;

    /**
     * Human-readable account of how the IOC was obtained, or null when the
     * context carries nothing worth saying.
     *
     * @param array<string, mixed> $context row from TaxiiService::extractContextRow()
     */
    public static function description(array $context): ?string
    {
        $sentences = [];

        $turn = self::intOrNull($context['revelation_turn'] ?? null);
        $totalTurns = self::intOrNull($context['total_turns'] ?? null);
        $scamType = self::stringOrNull($context['scam_type_code'] ?? null);

        // "turn 5 of 4" reads as a bug to an analyst, and ~37% of stored contexts
        // currently have revelation_turn > total_turns. Fall back to the turn
        // alone rather than publish a contradiction we cannot resolve here.
        $turnIsConsistent = $turn !== null && $totalTurns !== null && $totalTurns > 0 && $turn <= $totalTurns;

        if ($turnIsConsistent) {
            $sentences[] = $scamType !== null
                ? \sprintf('Revealed by the scammer at turn %d of %d of %s %s engagement.', $turn, $totalTurns, self::article($scamType), $scamType)
                : \sprintf('Revealed by the scammer at turn %d of %d.', $turn, $totalTurns);
        } elseif ($turn !== null) {
            $sentences[] = $scamType !== null
                ? \sprintf('Revealed by the scammer at turn %d of %s %s engagement.', $turn, self::article($scamType), $scamType)
                : \sprintf('Revealed by the scammer at turn %d.', $turn);
        } elseif ($scamType !== null) {
            $sentences[] = \sprintf('Revealed by the scammer during %s %s engagement.', self::article($scamType), $scamType);
        }

        $stimulus = self::stringOrNull($context['stimulus_type'] ?? null);
        $persona = self::stringOrNull($context['persona_label'] ?? null);

        if ($stimulus !== null && $persona !== null) {
            $sentences[] = \sprintf('Elicited by a %s stimulus from persona "%s".', $stimulus, $persona);
        } elseif ($stimulus !== null) {
            $sentences[] = \sprintf('Elicited by a %s stimulus.', $stimulus);
        } elseif ($persona !== null) {
            $sentences[] = \sprintf('Elicited by persona "%s".', $persona);
        }

        $role = self::stringOrNull($context['semantic_role'] ?? null);

        if ($role !== null) {
            $sentences[] = \sprintf('Role in the scam narrative: %s.', $role);
        }

        $urgency = self::floatOrNull($context['urgency_score'] ?? null);

        if ($urgency !== null) {
            $sentences[] = \sprintf('Urgency score %.2f.', $urgency);
        }

        $excerpt = self::stringOrNull($context['context_excerpt'] ?? null);

        if ($excerpt !== null) {
            $excerpt = self::truncate($excerpt);
            // Excerpts are free text and rarely end with punctuation; without
            // this the next sentence runs straight into them.
            $sentences[] = 'Context: ' . (preg_match('/[.!?…]$/u', $excerpt) === 1 ? $excerpt : $excerpt . '.');
        }

        $method = self::stringOrNull($context['extraction_method'] ?? null);

        if ($method !== null) {
            $sentences[] = \sprintf('Extraction method: %s.', $method);
        }

        return $sentences === [] ? null : implode(' ', $sentences);
    }

    /**
     * Labels a consumer can pivot and filter on. Always starts with the two
     * stable ones so existing filters keep matching.
     *
     * @param array<string, mixed>|null $context
     *
     * @return list<string>
     */
    public static function labels(?array $context, ?string $analystVerdict = null): array
    {
        $labels = [self::BASE_LABEL, self::SOURCE_LABEL];

        if ($context !== null) {
            $map = [
                'scam-type' => self::stringOrNull($context['scam_type_code'] ?? null),
                'ioc-role' => self::stringOrNull($context['semantic_role'] ?? null),
                'stimulus' => self::stringOrNull($context['stimulus_type'] ?? null),
                'persona' => self::stringOrNull($context['persona_code'] ?? null),
            ];

            foreach ($map as $prefix => $value) {
                if ($value !== null) {
                    $labels[] = $prefix . ':' . strtolower($value);
                }
            }
        }

        // An analyst verdict is authoritative downstream (see docs/24_analyst_feedback.md),
        // so it must be visible to consumers that only read labels.
        $verdict = self::stringOrNull($analystVerdict);

        if ($verdict !== null) {
            $labels[] = 'analyst:' . strtolower($verdict);
        }

        return array_values(array_unique($labels));
    }

    /**
     * External references for the taxonomies the context already resolves.
     *
     * @param array<string, mixed> $context
     *
     * @return list<array<string, string>>
     */
    public static function externalReferences(array $context): array
    {
        $refs = [];

        $attck = self::stringOrNull($context['scam_type_attck'] ?? null);

        if ($attck !== null && preg_match('/^T\d{4}(\.\d{3})?$/', $attck) === 1) {
            $refs[] = [
                'source_name' => 'mitre-attack',
                'external_id' => $attck,
                'url' => 'https://attack.mitre.org/techniques/' . str_replace('.', '/', $attck) . '/',
            ];
        }

        $misp = self::stringOrNull($context['scam_type_misp'] ?? null);

        if ($misp !== null) {
            $refs[] = [
                'source_name' => 'misp-taxonomy',
                'external_id' => $misp,
            ];
        }

        return $refs;
    }

    /** "an INVESTMENT engagement" vs "a ROMANCE engagement". */
    private static function article(string $word): string
    {
        return \in_array(strtoupper(mb_substr($word, 0, 1)), ['A', 'E', 'I', 'O', 'U'], true) ? 'an' : 'a';
    }

    private static function truncate(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        if (mb_strlen($value) <= self::MAX_EXCERPT) {
            return $value;
        }

        return mb_substr($value, 0, self::MAX_EXCERPT - 1) . '…';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
