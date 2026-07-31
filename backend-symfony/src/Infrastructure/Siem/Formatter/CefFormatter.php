<?php

declare(strict_types=1);

namespace App\Infrastructure\Siem\Formatter;

use App\Application\Audit\Port\SiemEventFormatterInterface;
use App\Domain\Audit\SiemEvent;
use App\Domain\Audit\SiemSeverityMap;

/**
 * Formats SiemEvents as CEF (Common Event Format) v25.
 *
 * CEF is the de facto standard for ArcSight, Splunk, and QRadar.
 * Format: CEF:Version|Device Vendor|Device Product|Device Version|Event ID|Name|Severity|Extensions
 *
 * Reference: https://www.microfocus.com/documentation/arcsight/arcsight-smartconnectors/cef-implementation-standard/
 */
final class CefFormatter implements SiemEventFormatterInterface
{
    private const CEF_VERSION = 0;
    private const VENDOR = 'ScamBuster';
    private const PRODUCT = 'HoneypotPlatform';
    private const PRODUCT_VERSION = '1.0';

    public function format(SiemEvent $event): string
    {
        $header = sprintf(
            'CEF:%d|%s|%s|%s|%s|%s|%d',
            self::CEF_VERSION,
            $this->escape(self::VENDOR),
            $this->escape(self::PRODUCT),
            self::PRODUCT_VERSION,
            $event->eventType->value,
            $this->escape($this->getEventName($event)),
            $event->severity,
        );

        $extensions = $this->buildExtensions($event);

        return $header . '|' . $extensions;
    }

    public function getFormatName(): string
    {
        return 'cef';
    }

    private function getEventName(SiemEvent $event): string
    {
        return match ($event->eventType->value) {
            'AUTH_SUCCESS' => 'Authentication Success',
            'AUTH_FAILURE' => 'Authentication Failure',
            'AUTH_TOKEN_EXPIRED' => 'Token Expired',
            'AUTH_LOGOUT' => 'User Logout',
            'AUTH_TOKEN_REFRESHED' => 'Refresh Token Rotated',
            'AUTH_TOKEN_REUSE_DETECTED' => 'Refresh Token Reuse Detected',
            'MESSAGE_INGESTED' => 'Scam Email Ingested',
            'REPLY_GENERATED' => 'LLM Reply Generated',
            'REPLY_SENT' => 'Reply Sent to Scammer',
            'IOC_EXTRACTED' => 'Threat Indicator Extracted',
            'IOC_FEEDBACK' => 'Analyst IOC Feedback',
            'TTP_EXTRACTED' => 'Scammer TTP Observed',
            'CONVERSATION_CLOSED' => 'Conversation Closed',
            'CONVERSATION_REOPENED' => 'Conversation Reopened',
            'INJECTION_DETECTED' => 'Prompt Injection Detected',
            'RATE_LIMIT_EXCEEDED' => 'Rate Limit Exceeded',
            'KILL_SWITCH_TOGGLED' => 'Emergency Kill Switch',
            'BUDGET_THRESHOLD_REACHED' => 'LLM Budget Threshold Reached',
            'LLM_LEAK_BLOCKED' => 'LLM Operational Leak Blocked',
            'LLM_SIGNATURE_STRIPPED' => 'LLM Reply Signature Stripped',
            'AUTH_BRUTE_FORCE_DETECTED' => 'Authentication Brute Force Detected',
            'EXPORT_MISP' => 'MISP Export',
            'EXPORT_STIX' => 'STIX Export',
            'PERSONA_SELECTED' => 'Persona Selected',
            'CONFIG_CHANGED' => 'Configuration Changed',
            'INGEST_PRE_FILTER_HIT' => 'Automated Mail Pre-Filtered',
            'SCAM_CLASSIFIED' => 'Scam Type Classified',
            'REPLY_RETRY' => 'Reply Generation Retry',
            'REPLY_REJECTED' => 'Reply Final Rejection',
            'BANDIT_DECISION' => 'Bandit Persona Selection Decision',
            'USER_CREATED' => 'User Account Created',
            'USER_PASSWORD_RESET' => 'User Password Reset',
            'USER_ROLE_CHANGED' => 'User Role Changed',
        };
    }

    private function buildExtensions(SiemEvent $event): string
    {
        $parts = [];

        $parts[] = 'rt=' . $event->timestamp->format('U') . '000';
        $parts[] = 'cat=' . SiemSeverityMap::getEcsCategory($event->eventType);
        $parts[] = 'outcome=' . $this->escape($event->outcome);
        $parts[] = 'suser=' . $this->escape($event->actorId);
        $parts[] = 'suid=' . $this->escape($event->actorType);

        if ($event->ipAddress !== null) {
            $parts[] = 'src=' . $event->ipAddress;
        }

        if ($event->traceId !== null) {
            $parts[] = 'cs1=' . $this->escape($event->traceId);
            $parts[] = 'cs1Label=TraceID';
        }

        if ($event->resourceType !== null) {
            $parts[] = 'cs2=' . $this->escape($event->resourceType);
            $parts[] = 'cs2Label=ResourceType';
        }

        if ($event->resourceId !== null) {
            $parts[] = 'cs3=' . $this->escape($event->resourceId);
            $parts[] = 'cs3Label=ResourceID';
        }

        $details = $event->details;

        if ($details !== []) {
            $parts[] = 'msg=' . $this->escape(json_encode($details, JSON_UNESCAPED_UNICODE) ?: '{}');
        }

        return implode(' ', $parts);
    }

    /**
     * Escape CEF special characters: backslash, pipe, equals, newline.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '|', '=', "\n", "\r"],
            ['\\\\', '\\|', '\\=', '\\n', '\\r'],
            $value,
        );
    }
}
