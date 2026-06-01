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
    // Spec 083 — automated-mail pre-filter hit (DMARC, noreply, etc.).
    // Pipeline skipped LLM classification + reply generation.
    case INGEST_PRE_FILTER_HIT = 'INGEST_PRE_FILTER_HIT';
    // Spec 095 Fix #13 — observability for the LLM auto-classification call.
    // Emitted once per autoClassifyScamType invocation (success or failure),
    // so UNKNOWN-rate and confidence distribution are queryable from DB
    // without parsing app.INFO logs.
    case SCAM_CLASSIFIED = 'SCAM_CLASSIFIED';
    // Spec 095 Fix #13 — emitted by RetryCoordinator each time a generation
    // attempt is rejected and the loop continues to the next attempt.
    // Carries the gate name (policy_guard | validator | leak_detector |
    // ioc_threshold | validator_error) and attempt number.
    case REPLY_RETRY = 'REPLY_RETRY';
    // Spec 095 Fix #13 — emitted by RetryCoordinator when all 3 attempts
    // are exhausted at a gate and the canned fallback response is used.
    // Carries the exhausting gate name (policy_guard | validator |
    // leak_detector) and attempts count.
    case REPLY_REJECTED = 'REPLY_REJECTED';
    // Spec 095 Fix #14 — emitted by PersonaOptimizer on every
    // selectPersonaWithStrategy call. Carries the FULL decision context:
    // selected persona + all candidates with UCB1 scores + random_value
    // + epsilon + converged flag. Complements (does not replace)
    // PERSONA_SELECTED — research-grade introspection for the bandit's
    // re-learning window after P4 TRUNCATE.
    case BANDIT_DECISION = 'BANDIT_DECISION';

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
    // Spec 080 — emitted by SignatureStripper when a trailing signature
    // block was removed from a generated reply. Informational (severity
    // defaults to Low via SiemSeverityMap), not a threat indicator.
    case LLM_SIGNATURE_STRIPPED = 'LLM_SIGNATURE_STRIPPED';
    // Spec 065e — emitted when the per-email login rate limiter
    // (login_email) blocks a brute-force attempt.
    case AUTH_BRUTE_FORCE_DETECTED = 'AUTH_BRUTE_FORCE_DETECTED';

    // Export
    case EXPORT_MISP = 'EXPORT_MISP';
    case EXPORT_STIX = 'EXPORT_STIX';

    // System
    case PERSONA_SELECTED = 'PERSONA_SELECTED';
    case CONFIG_CHANGED = 'CONFIG_CHANGED';
}
