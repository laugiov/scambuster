<?php

declare(strict_types=1);

namespace App\Application\Ttp\Audit;

/**
 * A parsed scored sheet: the verdict rows plus every structural problem the reader
 * found while parsing them.
 *
 * Problems do not stop the computation. A sheet with three unadjudicated rows still
 * produces figures — but the report prints the problems above them, so nobody
 * publishes a number off a sheet that is not finished.
 *
 * @phpstan-import-type ScoredRow from AuditScoreCalculator
 */
final readonly class AuditSheet
{
    /**
     * @param list<ScoredRow> $rows
     * @param list<string>    $problems
     */
    public function __construct(
        public array $rows,
        public array $problems,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->problems === [];
    }
}
