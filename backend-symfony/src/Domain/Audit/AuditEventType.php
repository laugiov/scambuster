<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Taxonomy of security-relevant events tracked in the audit log.
 *
 * Reference: security-by-design framework (AUDIT_LOGGING.md).
 */
enum AuditEventType: string
{
    // Authentication
    case AUTH_SUCCESS = 'AUTH_SUCCESS';
    case AUTH_FAILURE = 'AUTH_FAILURE';
    case AUTH_TOKEN_EXPIRED = 'AUTH_TOKEN_EXPIRED';
    case AUTH_LOGOUT = 'AUTH_LOGOUT';

    // Data operations
    case MESSAGE_INGESTED = 'MESSAGE_INGESTED';
    case REPLY_GENERATED = 'REPLY_GENERATED';
    case REPLY_SENT = 'REPLY_SENT';
    case IOC_EXTRACTED = 'IOC_EXTRACTED';
    case CONVERSATION_CLOSED = 'CONVERSATION_CLOSED';

    // Security events
    case INJECTION_DETECTED = 'INJECTION_DETECTED';
    case RATE_LIMIT_EXCEEDED = 'RATE_LIMIT_EXCEEDED';
    case KILL_SWITCH_TOGGLED = 'KILL_SWITCH_TOGGLED';
    // Spec 065b — emitted by BudgetThresholdNotifier when monthly LLM
    // spend crosses 80% of the configured cap. Deduplicated per day.
    case BUDGET_THRESHOLD_REACHED = 'BUDGET_THRESHOLD_REACHED';
    // Spec 065d — emitted when the OperationalLeakageDetector or the
    // PolicyGuard regex deny-list catches an attempted operational
    // information leak in a generated reply.
    case LLM_LEAK_BLOCKED = 'LLM_LEAK_BLOCKED';

    // Export
    case EXPORT_MISP = 'EXPORT_MISP';
    case EXPORT_STIX = 'EXPORT_STIX';

    // System
    case PERSONA_SELECTED = 'PERSONA_SELECTED';
    case CONFIG_CHANGED = 'CONFIG_CHANGED';
}
