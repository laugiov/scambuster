<?php

declare(strict_types=1);

namespace App\Application\Communication;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Builds the deduplicated set of CTI machine tags describing a conversation's
 * FULL scam-type classification — its primary type plus every already-stored
 * secondary type (`conversation.secondary_scam_types`).
 *
 * Read-only, offline, additive: it surfaces classification the reply path already
 * produced; it never writes and never touches reply generation.
 *
 * For each type it emits (via {@see ScamTaxonomyMapper}) the RSIT taxonomy tag,
 * the MITRE ATT&CK MISP-galaxy tag, and the first-party scam-type tag — deduped,
 * because several scam types legitimately share one RSIT class / ATT&CK technique.
 */
final readonly class ConversationScamTaxonomyProvider
{
    public function __construct(
        private EntityManagerInterface $em,
        private ScamTaxonomyMapper $mapper,
    ) {
    }

    /**
     * @return list<string> unique MISP tag strings (primary type first), empty when the conversation is unknown
     */
    public function tagsForConversation(string $convId): array
    {
        $conn = $this->em->getConnection();

        $head = $conn->executeQuery(
            'SELECT lst.code AS primary_code, c.secondary_scam_types
             FROM conversation c
             JOIN lkp_scam_type lst ON c.scam_type_id = lst.scam_type_id
             WHERE c.conv_id = :cid',
            ['cid' => $convId],
        )->fetchAssociative();

        if ($head === false) {
            return [];
        }

        $codes = [];

        if (\is_string($head['primary_code'] ?? null) && $head['primary_code'] !== '') {
            $codes[] = $head['primary_code'];
        }

        foreach ($this->extractSecondaryCodes($head['secondary_scam_types'] ?? null) as $code) {
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        if ($codes === []) {
            return [];
        }

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $conn->executeQuery(
            'SELECT code, misp_taxonomy, attck_technique FROM lkp_scam_type WHERE code IN (:codes)',
            ['codes' => $codes],
            ['codes' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        // Index by code so we can emit tags in the deterministic primary-first order.
        $byCode = [];

        foreach ($rows as $row) {
            if (\is_string($row['code'] ?? null)) {
                $byCode[$row['code']] = $row;
            }
        }

        $tags = [];
        $add = static function (?string $tag) use (&$tags): void {
            if ($tag !== null && !\in_array($tag, $tags, true)) {
                $tags[] = $tag;
            }
        };

        foreach ($codes as $code) {
            $row = $byCode[$code] ?? null;

            $add($this->mapper->scamTypeTag($code));

            if ($row === null) {
                continue;
            }

            $add($this->mapper->rsitTag(\is_string($row['misp_taxonomy'] ?? null) ? $row['misp_taxonomy'] : null));
            $add($this->mapper->attckGalaxyTag(\is_string($row['attck_technique'] ?? null) ? $row['attck_technique'] : null));
        }

        return $tags;
    }

    /**
     * @return list<string> scam-type codes from the persisted secondary_scam_types jsonb
     */
    private function extractSecondaryCodes(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $codes = [];

        foreach ($raw as $entry) {
            if (\is_array($entry) && \is_string($entry['code'] ?? null) && $entry['code'] !== '') {
                $codes[] = $entry['code'];
            }
        }

        return $codes;
    }
}
