<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the deduplicated set of MISP machine tags describing a conversation's
 * confirmed scammer-side TTPs.
 *
 * Read-only, offline, additive: it surfaces confirmed ttp_observation rows the
 * extractor already produced; it never writes and never touches evidence text —
 * only the first-party TTP code and, where a TTP carries a verified MITRE ATT&CK
 * reference, the corresponding MISP galaxy tag (via {@see ScamTaxonomyMapper}).
 *
 * For each confirmed TTP it emits:
 * - the first-party tag `scambuster:ttp="SB-Txxx"`;
 * - the MITRE ATT&CK galaxy tag when the TTP's mitre-attack external reference is
 *   in the verified allowlist — an unmapped id (e.g. T1598) yields NO tag,
 *   never a fabricated string.
 */
final readonly class TtpMispTagProvider
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScamTaxonomyMapper $mapper,
    ) {
    }

    /**
     * @return list<string> unique MISP tag strings (TTP code order), empty when the conversation has no confirmed TTPs
     */
    public function tagsForConversation(string $convId): array
    {
        $conn = $this->em->getConnection();

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $conn->executeQuery(
            "SELECT t.code, t.external_refs
             FROM ttp_observation o
             JOIN lkp_ttp t ON t.ttp_id = o.ttp_id
             JOIN message m ON m.msg_id = o.msg_id AND m.deleted_at IS NULL
             WHERE o.conv_id = :cid AND o.status = 'confirmed'
             GROUP BY t.code, t.external_refs
             ORDER BY t.code ASC",
            ['cid' => $convId],
        )->fetchAllAssociative();

        $tags = [];
        $add = static function (?string $tag) use (&$tags): void {
            if ($tag !== null && !\in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        };

        foreach ($rows as $row) {
            $code = \is_string($row['code'] ?? null) ? $row['code'] : '';

            if ($code === '') {
                continue;
            }

            $add('scambuster:ttp="' . strtoupper($code) . '"');

            foreach ($this->mitreExternalIds($row['external_refs'] ?? null) as $externalId) {
                $add($this->mapper->attckGalaxyTag($externalId));
            }
        }

        return $tags;
    }

    /**
     * MITRE ATT&CK technique ids from the lkp_ttp.external_refs JSONB column.
     *
     * @return list<string>
     */
    private function mitreExternalIds(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $ids = [];

        foreach ($raw as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $sourceName = \is_string($entry['source_name'] ?? null) ? $entry['source_name'] : '';
            $externalId = \is_string($entry['external_id'] ?? null) ? $entry['external_id'] : '';

            if ($sourceName === 'mitre-attack' && $externalId !== '') {
                $ids[] = $externalId;
            }
        }

        return $ids;
    }
}
