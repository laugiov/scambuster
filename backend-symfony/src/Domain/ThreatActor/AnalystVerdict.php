<?php

declare(strict_types=1);

namespace App\Domain\ThreatActor;

/**
 * An analyst's verdict on an IOC in the intelligence-lifecycle feedback loop.
 */
enum AnalystVerdict: string
{
    case Confirmed = 'confirmed';
    case FalsePositive = 'false_positive';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $v): string => $v->value, self::cases());
    }
}
