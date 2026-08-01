<?php

declare(strict_types=1);

namespace App\Application\Scambaiting;

/**
 * Read-only contract used by STIX export builders to fetch
 * cached Cognitive Mirror analyses. Extracted so the export-side builders
 * can be unit-tested without the final concrete query service.
 */
interface PersonaMirrorReaderInterface
{
    /**
     * @return list<array{
     *   scam_type_code: string,
     *   scam_type_label: string,
     *   hunted_victim_profile: string,
     *   cognitive_lever: string,
     *   mirror_explanation: string,
     *   generated_at: string,
     *   generated_by_model: string,
     *   prompt_version: string,
     * }>
     */
    public function getByPersona(string $personaCode): array;
}
