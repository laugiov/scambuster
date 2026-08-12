<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * The provenance a results document has to carry next to its figures: which seed
 * drew the sample, which taxonomy and codebook versions the scoring ran under, and
 * when.
 *
 * A figure without this block is not reviewable, so the renderer prints the table
 * first and marks any missing field "_not recorded_" rather than omitting the row.
 */
final readonly class AuditRunContext
{
    /**
     * @param list<string> $taxonomyCodes Full closed taxonomy, so the report can name the codes the sample never touched
     */
    public function __construct(
        public string $seed,
        public string $draw,
        public string $taxonomyVersion,
        public string $codebookVersion,
        public string $sheetName,
        public string $scoredOn,
        public array $taxonomyCodes = [],
    ) {
    }
}
