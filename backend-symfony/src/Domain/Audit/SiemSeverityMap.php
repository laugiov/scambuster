<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Maps AuditEventType to CEF/ECS severity (0-10 scale).
 *
 * Severity follows industry standards:
 * - 1-3: Low (informational, routine operations)
 * - 4-6: Medium (security-relevant, requires attention)
 * - 7-8: High (active threat, immediate investigation)
 * - 9-10: Critical (system compromise, emergency response)
 *
 * Reference: ArcSight CEF v25, Elastic Common Schema 8.x
 */
final class SiemSeverityMap
{
    /** @var array<string, int> */
    private const MAP = [
        // Authentication (1-5)
        'AUTH_SUCCESS' => 1,
        'AUTH_FAILURE' => 5,
        'AUTH_TOKEN_EXPIRED' => 2,
        'AUTH_LOGOUT' => 1,

        // Data operations (2-4)
        'MESSAGE_INGESTED' => 3,
        'REPLY_GENERATED' => 3,
        'REPLY_SENT' => 3,
        'IOC_EXTRACTED' => 4,
        'CONVERSATION_CLOSED' => 2,

        // Security events (6-9)
        'INJECTION_DETECTED' => 8,
        'RATE_LIMIT_EXCEEDED' => 6,
        'KILL_SWITCH_TOGGLED' => 9,
        // Spec 065b — soft warning at 80% of monthly LLM cap
        'BUDGET_THRESHOLD_REACHED' => 5,
        // Spec 065d — operational identifier leak attempt blocked by validator
        'LLM_LEAK_BLOCKED' => 7,
        // Spec 065e — per-email brute force detected
        'AUTH_BRUTE_FORCE_DETECTED' => 7,

        // Export (2)
        'EXPORT_MISP' => 2,
        'EXPORT_STIX' => 2,

        // System (1-7)
        'PERSONA_SELECTED' => 1,
        'CONFIG_CHANGED' => 7,
    ];

    private const DEFAULT_SEVERITY = 3;

    public static function getSeverity(AuditEventType $eventType): int
    {
        if (\array_key_exists($eventType->value, self::MAP)) {
            return self::MAP[$eventType->value];
        }

        return self::DEFAULT_SEVERITY;
    }

    /**
     * CEF severity label for human-readable output.
     */
    public static function getLabel(int $severity): string
    {
        return match (true) {
            $severity >= 9 => 'Critical',
            $severity >= 7 => 'High',
            $severity >= 4 => 'Medium',
            default => 'Low',
        };
    }

    /**
     * ECS event.category mapping.
     */
    public static function getEcsCategory(AuditEventType $eventType): string
    {
        return match ($eventType) {
            AuditEventType::AUTH_SUCCESS,
            AuditEventType::AUTH_FAILURE,
            AuditEventType::AUTH_TOKEN_EXPIRED,
            AuditEventType::AUTH_LOGOUT => 'authentication',

            AuditEventType::MESSAGE_INGESTED,
            AuditEventType::REPLY_SENT => 'email',

            AuditEventType::INJECTION_DETECTED,
            AuditEventType::RATE_LIMIT_EXCEEDED => 'intrusion_detection',

            AuditEventType::IOC_EXTRACTED => 'threat',

            AuditEventType::KILL_SWITCH_TOGGLED,
            AuditEventType::CONFIG_CHANGED => 'configuration',

            default => 'process',
        };
    }
}
