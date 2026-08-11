<?php

declare(strict_types=1);

namespace App\Application\Standards;

use App\Domain\Communication\TtpTaxonomySeed;

/**
 * Renders the F3 mapping decisions as the markdown table that lives inside
 * docs/standards/f3-mapping.md, between the generated-block markers.
 *
 * The document is generated rather than hand-written so a reviewer reading it and a
 * consumer reading the STIX export are looking at the same decisions. Editing the
 * table by hand is how the two drift; a CI check regenerates and diffs.
 */
final class F3MappingRenderer
{
    public const BEGIN_MARKER = '<!-- BEGIN GENERATED MAPPING TABLE -->';
    public const END_MARKER = '<!-- END GENERATED MAPPING TABLE -->';

    public function __construct(
        private readonly F3MappingLoader $loader,
    ) {
    }

    public function render(): string
    {
        $mapping = $this->loader->load();

        /** @var array<string, array{label: string, phase: string}> $taxonomy */
        $taxonomy = [];

        foreach (TtpTaxonomySeed::ENTRIES as $entry) {
            $taxonomy[$entry['code']] = ['label' => $entry['label'], 'phase' => $entry['phase']];
        }

        $lines = [self::BEGIN_MARKER, ''];

        $version = \is_string($mapping['framework_version'] ?? null) ? $mapping['framework_version'] : null;
        $checkedOn = \is_string($mapping['checked_on'] ?? null) ? $mapping['checked_on'] : null;

        $lines[] = sprintf('**F3 version checked**: %s', $version ?? '_not yet checked_');
        $lines[] = sprintf('**Date of the check**: %s', $checkedOn ?? '_not yet checked_');
        $lines[] = '';

        $blockedReason = \is_string($mapping['blocked_reason'] ?? null) ? $mapping['blocked_reason'] : null;

        if (($mapping['status'] ?? null) === 'blocked' && $blockedReason !== null) {
            $lines[] = '> **Blocked.** ' . $blockedReason;
            $lines[] = '';
        }

        $lines[] = '| Code | Label | Phase | Relation | F3 id(s) | Rationale |';
        $lines[] = '|------|-------|-------|----------|----------|-----------|';

        $entries = \is_array($mapping['entries'] ?? null) ? $mapping['entries'] : [];
        $counts = array_fill_keys(F3MappingLoader::RELATIONS, 0);

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $code = \is_string($entry['code'] ?? null) ? $entry['code'] : '';
            $relation = \is_string($entry['relation'] ?? null) ? $entry['relation'] : '';
            $rationale = \is_string($entry['rationale'] ?? null) ? $entry['rationale'] : '';
            $f3Ids = \is_array($entry['f3_ids'] ?? null) ? $entry['f3_ids'] : [];

            if (isset($counts[$relation])) {
                ++$counts[$relation];
            }

            $ids = [];

            foreach ($f3Ids as $id) {
                if (\is_string($id)) {
                    $ids[] = $id;
                }
            }

            $lines[] = sprintf(
                '| %s | %s | %s | `%s` | %s | %s |',
                $code,
                $taxonomy[$code]['label'] ?? '_unknown code_',
                $taxonomy[$code]['phase'] ?? '—',
                $relation,
                $ids === [] ? '—' : implode(', ', $ids),
                $this->escapePipes($rationale),
            );
        }

        $lines[] = '';
        $lines[] = '### Decision counts';
        $lines[] = '';
        $lines[] = '| Relation | Entries |';
        $lines[] = '|----------|---------|';

        foreach (F3MappingLoader::RELATIONS as $relation) {
            $lines[] = sprintf('| `%s` | %d |', $relation, $counts[$relation]);
        }

        $lines[] = '';
        $lines[] = sprintf(
            'Entries written to `external_refs` (relations %s): **%d**.',
            implode(' and ', array_map(static fn (string $r): string => '`' . $r . '`', F3MappingLoader::CONFIRMED_RELATIONS)),
            \count($this->loader->confirmedReferences()),
        );
        $lines[] = '';

        $lines[] = '### Reverse direction';
        $lines[] = '';

        $reverse = \is_array($mapping['reverse_check'] ?? null) ? $mapping['reverse_check'] : [];
        $techniques = \is_array($reverse['techniques'] ?? null) ? $reverse['techniques'] : [];

        if ($techniques === []) {
            $note = \is_string($reverse['note'] ?? null) ? $reverse['note'] : '';
            $lines[] = sprintf(
                'Not yet recorded (status: `%s`). %s',
                \is_string($reverse['status'] ?? null) ? $reverse['status'] : 'unknown',
                $note,
            );
        } else {
            $lines[] = '| F3 id | Name | Why it is not in the ScamBuster taxonomy |';
            $lines[] = '|-------|------|------------------------------------------|';

            foreach ($techniques as $technique) {
                if (!\is_array($technique)) {
                    continue;
                }

                $lines[] = sprintf(
                    '| %s | %s | %s |',
                    \is_string($technique['id'] ?? null) ? $technique['id'] : '',
                    \is_string($technique['name'] ?? null) ? $technique['name'] : '',
                    $this->escapePipes(\is_string($technique['note'] ?? null) ? $technique['note'] : ''),
                );
            }
        }

        $lines[] = '';
        $lines[] = self::END_MARKER;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Replace the block between the markers in an existing document, so regenerating
     * never touches the hand-written prose around it.
     */
    public function replaceBlock(string $document): string
    {
        $begin = strpos($document, self::BEGIN_MARKER);
        $end = strpos($document, self::END_MARKER);

        if ($begin === false || $end === false || $end < $begin) {
            throw new \RuntimeException(sprintf(
                'The mapping document must contain the "%s" and "%s" markers, in that order.',
                self::BEGIN_MARKER,
                self::END_MARKER,
            ));
        }

        return substr($document, 0, $begin)
            . $this->render()
            . substr($document, $end + \strlen(self::END_MARKER) + 1);
    }

    private function escapePipes(string $text): string
    {
        return str_replace('|', '\\|', $text);
    }
}
