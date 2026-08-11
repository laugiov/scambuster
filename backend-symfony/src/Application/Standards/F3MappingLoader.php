<?php

declare(strict_types=1);

namespace App\Application\Standards;

/**
 * Loads and validates the ScamBuster -> MITRE F3 mapping file (Spec 002).
 *
 * The JSON file is the source of truth for the mapping decisions; the markdown
 * document under docs/ is rendered from it, so a reviewer reading the document and a
 * consumer reading the STIX export can never see two different answers.
 *
 * Validation is strict on purpose. A mapping that claims a relation it cannot cite,
 * or that cites an F3 id no `external_refs` row carries, would put an unverified
 * external-framework claim into a public export — exactly what Constitution II
 * forbids.
 */
final class F3MappingLoader
{
    public const SOURCE_NAME = 'mitre-f3';

    public const RELATION_EQUIVALENT = 'equivalent';
    public const RELATION_NARROWER = 'narrower-than';
    public const RELATION_BROADER = 'broader-than';
    public const RELATION_RELATED = 'related';
    public const RELATION_NONE = 'none';
    public const RELATION_PENDING = 'pending';

    /** @var list<string> */
    public const RELATIONS = [
        self::RELATION_EQUIVALENT,
        self::RELATION_NARROWER,
        self::RELATION_BROADER,
        self::RELATION_RELATED,
        self::RELATION_NONE,
        self::RELATION_PENDING,
    ];

    /**
     * Relations that become a published STIX external reference.
     *
     * `broader-than` is deliberately excluded: a ScamBuster entry that covers more
     * ground than the F3 technique it points at would tell a consumer the entry is
     * scoped to that technique, which is false. `related` is excluded for the same
     * reason at a weaker strength. This closes the open question in Spec 002's
     * edge-case list in the direction the spec proposed.
     *
     * @var list<string>
     */
    public const CONFIRMED_RELATIONS = [
        self::RELATION_EQUIVALENT,
        self::RELATION_NARROWER,
    ];

    /**
     * Relations that constitute a decision. `pending` is not one: it records that
     * nobody has read the F3 description yet.
     *
     * @var list<string>
     */
    public const DECIDED_RELATIONS = [
        self::RELATION_EQUIVALENT,
        self::RELATION_NARROWER,
        self::RELATION_BROADER,
        self::RELATION_RELATED,
        self::RELATION_NONE,
    ];

    public function __construct(
        private readonly string $mappingPath,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function load(): array
    {
        if (!is_file($this->mappingPath)) {
            throw new \RuntimeException(sprintf('F3 mapping file not found: %s', $this->mappingPath));
        }

        $raw = file_get_contents($this->mappingPath);

        if ($raw === false) {
            throw new \RuntimeException(sprintf('Unable to read the F3 mapping file: %s', $this->mappingPath));
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            throw new \RuntimeException(sprintf('F3 mapping file is not a JSON object: %s', $this->mappingPath));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Every structural problem in the mapping, as human-readable lines. An empty
     * list means the file is internally consistent — not that the mapping is done.
     *
     * @param list<string> $taxonomyCodes The closed taxonomy, in canonical order
     *
     * @return list<string>
     */
    public function validate(array $taxonomyCodes): array
    {
        $mapping = $this->load();
        $problems = [];

        $entries = $mapping['entries'] ?? null;

        if (!\is_array($entries)) {
            return ['mapping file has no "entries" list'];
        }

        $seen = [];

        foreach ($entries as $position => $entry) {
            if (!\is_array($entry)) {
                $problems[] = sprintf('entry #%s is not an object', (string) $position);

                continue;
            }

            $code = \is_string($entry['code'] ?? null) ? $entry['code'] : '';
            $relation = \is_string($entry['relation'] ?? null) ? $entry['relation'] : '';
            $rationale = \is_string($entry['rationale'] ?? null) ? trim($entry['rationale']) : '';
            $f3Ids = \is_array($entry['f3_ids'] ?? null) ? $entry['f3_ids'] : null;

            if ($code === '') {
                $problems[] = sprintf('entry #%s has no code', (string) $position);

                continue;
            }

            if (isset($seen[$code])) {
                $problems[] = sprintf('%s appears more than once', $code);

                continue;
            }
            $seen[$code] = true;

            if (!\in_array($code, $taxonomyCodes, true)) {
                $problems[] = sprintf('%s is not a taxonomy code', $code);
            }

            if (!\in_array($relation, self::RELATIONS, true)) {
                $problems[] = sprintf('%s carries unknown relation "%s"', $code, $relation);
            }

            if ($rationale === '') {
                $problems[] = sprintf('%s has no rationale', $code);
            }

            if ($f3Ids === null) {
                $problems[] = sprintf('%s has no f3_ids list', $code);

                continue;
            }

            foreach ($f3Ids as $id) {
                if (!\is_string($id) || trim($id) === '') {
                    $problems[] = sprintf('%s carries a non-string or empty F3 id', $code);
                }
            }

            // A decision that names a technique has to name it; a decision that
            // says F3 covers nothing must not name one.
            if (\in_array($relation, [self::RELATION_EQUIVALENT, self::RELATION_NARROWER, self::RELATION_BROADER, self::RELATION_RELATED], true) && $f3Ids === []) {
                $problems[] = sprintf('%s is "%s" but cites no F3 id', $code, $relation);
            }

            if ($relation === self::RELATION_NONE && $f3Ids !== []) {
                $problems[] = sprintf('%s is "none" but cites F3 id(s); use "related" if a nearby technique exists', $code);
            }

            if ($relation === self::RELATION_PENDING && $f3Ids !== []) {
                $problems[] = sprintf('%s is still "pending" but already cites F3 id(s)', $code);
            }
        }

        foreach ($taxonomyCodes as $code) {
            if (!isset($seen[$code])) {
                $problems[] = sprintf('%s has no mapping entry', $code);
            }
        }

        // FR-006: a mapping whose decisions are made must say which F3 version it
        // was made against, so a future F3 release invalidates it visibly.
        if ($this->isDecided($mapping) && !\is_string($mapping['framework_version'] ?? null)) {
            $problems[] = 'decisions are recorded but framework_version is not set';
        }

        if ($this->isDecided($mapping) && !\is_string($mapping['checked_on'] ?? null)) {
            $problems[] = 'decisions are recorded but checked_on is not set';
        }

        return $problems;
    }

    /**
     * The confirmed mappings, keyed by taxonomy code: the ones that must appear in
     * `external_refs` and therefore in the STIX export.
     *
     * @return array<string, list<string>>
     */
    public function confirmedReferences(): array
    {
        $mapping = $this->load();
        $entries = \is_array($mapping['entries'] ?? null) ? $mapping['entries'] : [];
        $confirmed = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $code = \is_string($entry['code'] ?? null) ? $entry['code'] : '';
            $relation = \is_string($entry['relation'] ?? null) ? $entry['relation'] : '';
            $f3Ids = \is_array($entry['f3_ids'] ?? null) ? $entry['f3_ids'] : [];

            if ($code === '' || !\in_array($relation, self::CONFIRMED_RELATIONS, true)) {
                continue;
            }

            $ids = [];

            foreach ($f3Ids as $id) {
                if (\is_string($id) && trim($id) !== '') {
                    $ids[] = trim($id);
                }
            }

            if ($ids !== []) {
                $confirmed[$code] = $ids;
            }
        }

        return $confirmed;
    }

    /**
     * Whether any entry carries an actual decision (as opposed to `pending`).
     *
     * @param array<string, mixed> $mapping
     */
    private function isDecided(array $mapping): bool
    {
        $entries = \is_array($mapping['entries'] ?? null) ? $mapping['entries'] : [];

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $relation = \is_string($entry['relation'] ?? null) ? $entry['relation'] : '';

            if (\in_array($relation, self::DECIDED_RELATIONS, true)) {
                return true;
            }
        }

        return false;
    }
}
