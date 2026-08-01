<?php

declare(strict_types=1);

namespace App\Application\ThreatActor;

use App\Domain\ThreatActor\AnalystVerdict;

/**
 * Read port for analyst IOC verdicts. Consumed by the STIX export handler to
 * feed the verdict back into indicator confidence.
 */
interface IocFeedbackReaderInterface
{
    /**
     * @param list<string> $indicatorIds
     *
     * @return array<string, AnalystVerdict> indicator_id → current verdict
     */
    public function getVerdicts(array $indicatorIds): array;
}
