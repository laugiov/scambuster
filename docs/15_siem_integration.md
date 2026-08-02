# SIEM Integration Guide

> Connect ScamBuster security events to your SIEM/SOAR platform.

---

## Overview

ScamBuster generates 33 types of security events (authentication, IOC extraction, prompt injection detection, rate limiting, etc.) via its structured audit trail. The SIEM connector exports these events in real-time to enterprise platforms.

### Architecture

```
AuditLogger (Application layer)
    │
    ├── PostgreSQL audit_log table (always, primary storage)
    │
    └── SiemExporterInterface (port)
            │
            ├── NullSiemExporter    (default — disabled, zero overhead)
            ├── FileSiemExporter    (NDJSON file — air-gapped / testing)
            └── SyslogSiemExporter  (UDP/TCP — QRadar, ArcSight, etc.)
```

### Supported Formats

| Format | Standard | Use Case |
|--------|----------|----------|
| **CEF** | Common Event Format v25 | Splunk, ArcSight, QRadar |
| **ECS** | Elastic Common Schema 8.x | Elastic Security, Filebeat |
| **JSON** | Plain NDJSON | File export, generic webhooks |

---

## Quick Start

### 1. Choose your provider

| Provider | SIEM_PROVIDER | Best for |
|----------|--------------|----------|
| Disabled | `none` (default) | No SIEM needed |
| File | `file` | Testing, air-gapped environments |
| Syslog | `syslog` | QRadar, ArcSight, any syslog receiver |

### 2. Set environment variables

Add to your `.env` file:

```bash
# File export (simplest — great for testing)
SIEM_PROVIDER=file
SIEM_ENDPOINT=/var/log/scambuster/siem-events.ndjson

# OR Syslog (UDP to QRadar/ArcSight)
SIEM_PROVIDER=syslog
SIEM_ENDPOINT=udp://10.0.0.1:514
SIEM_FORMAT=cef

# OR Syslog (TCP)
SIEM_PROVIDER=syslog
SIEM_ENDPOINT=tcp://siem.company.com:514
SIEM_FORMAT=cef
```

### 3. Restart the application

```bash
docker compose restart backend-dev
```

### 4. Verify connectivity

```bash
docker compose exec backend-dev php bin/console app:siem:test
```

Expected output:
```
SIEM Connector Test
===================

 Provider: file
 Health check: OK

 [OK] Test event sent successfully to file provider.
```

---

## Configuration Reference

### Environment Variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `SIEM_PROVIDER` | No | `none` | Provider type: `none`, `file`, `syslog` |
| `SIEM_ENDPOINT` | When provider != none | — | Target: file path or `udp://host:port` / `tcp://host:port` |
| `SIEM_FORMAT` | No | Auto-detected | Format override: `cef`, `ecs`, `json` |

### Format Auto-Detection

| Provider | Default Format |
|----------|---------------|
| `file` | `json` (NDJSON) |
| `syslog` | `cef` |

Override with `SIEM_FORMAT` if needed. For example, to send LEEF to QRadar via syslog:
```bash
SIEM_PROVIDER=syslog
SIEM_ENDPOINT=udp://qradar:514
SIEM_FORMAT=cef  # QRadar also supports CEF
```

---

## Event Types & Severity Mapping

ScamBuster exports 33 audit event types (30 with explicit severity mappings; the remaining 3 fall back to the default severity of 3):

### Severity Scale (CEF 0-10)

| Event Type | CEF Severity | Category | Description |
|------------|-------------|----------|-------------|
| `AUTH_SUCCESS` | 1 (Low) | Authentication | Successful login |
| `AUTH_FAILURE` | 5 (Medium) | Authentication | Failed login attempt |
| `AUTH_TOKEN_EXPIRED` | 2 (Low) | Authentication | JWT token expired |
| `AUTH_LOGOUT` | 1 (Low) | Authentication | User logged out |
| `AUTH_TOKEN_REFRESHED` | 2 (Low) | Authentication | Refresh token rotated (routine) |
| `AUTH_TOKEN_REUSE_DETECTED` | 8 (High) | Authentication | Rotated refresh token replayed (session-theft signal) |
| `AUTH_BRUTE_FORCE_DETECTED` | 7 (High) | Authentication | Per-email login brute-force blocked |
| `MESSAGE_INGESTED` | 3 (Low) | Email | Scam email received and processed |
| `INGEST_PRE_FILTER_HIT` | 3 (Low, default) | Email | Automated-mail pre-filter hit (DMARC/noreply); LLM steps skipped |
| `REPLY_GENERATED` | 3 (Low) | Process | LLM-generated reply created |
| `REPLY_SENT` | 3 (Low) | Email | Reply sent to scammer |
| `REPLY_RETRY` | 2 (Low) | Process | Reply generation attempt rejected, loop retried |
| `REPLY_REJECTED` | 4 (Medium) | Process | All reply attempts exhausted, canned fallback used |
| `IOC_EXTRACTED` | 4 (Medium) | Threat | Threat indicator extracted from message |
| `IOC_FEEDBACK` | 3 (Low, default) | Threat | Analyst confirmed / false-positive verdict on an IOC |
| `TTP_EXTRACTED` | 4 (Medium) | Threat | Scammer TTP observation persisted for an inbound message |
| `SCAM_CLASSIFIED` | 2 (Low) | Process | LLM scam-type classification recorded |
| `BANDIT_DECISION` | 2 (Low) | Process | Persona bandit decision context recorded |
| `CONVERSATION_CLOSED` | 2 (Low) | Process | Scambaiting conversation ended |
| `CONVERSATION_REOPENED` | 2 (Low) | Process | Analyst reopened a closed/abandoned conversation |
| `INJECTION_DETECTED` | 8 (High) | Intrusion Detection | Prompt injection attempt detected |
| `RATE_LIMIT_EXCEEDED` | 6 (Medium) | Intrusion Detection | Rate limit triggered |
| `LLM_LEAK_BLOCKED` | 7 (High) | Intrusion Detection | Operational information leak blocked in a reply |
| `LLM_SIGNATURE_STRIPPED` | 3 (Low, default) | Process | Trailing signature block removed from a reply (informational) |
| `KILL_SWITCH_TOGGLED` | 9 (Critical) | Configuration | Emergency stop activated |
| `BUDGET_THRESHOLD_REACHED` | 5 (Medium) | Configuration | Monthly LLM spend crossed 80% of cap |
| `EXPORT_MISP` | 2 (Low) | Process | MISP export triggered |
| `EXPORT_STIX` | 2 (Low) | Process | STIX 2.1 export triggered |
| `PERSONA_SELECTED` | 1 (Low) | Process | Persona assigned to conversation |
| `CONFIG_CHANGED` | 7 (High) | Configuration | System configuration modified |
| `USER_CREATED` | 6 (Medium) | User Management | Operator account created |
| `USER_PASSWORD_RESET` | 6 (Medium) | User Management | Operator password reset |
| `USER_ROLE_CHANGED` | 7 (High) | User Management | Operator role changed |

---

## Format Examples

### CEF (Common Event Format)

```
CEF:0|ScamBuster|HoneypotPlatform|1.0|AUTH_FAILURE|Authentication Failure|5|rt=1711360200000 cat=authentication outcome=failure suser=admin@example.com suid=user src=192.168.1.100 cs1=trace-abc123 cs1Label=TraceID
```

**Fields:**
- `rt` — Event timestamp (milliseconds since epoch)
- `cat` — Event category (authentication, threat, email, etc.)
- `outcome` — Event outcome (success/failure)
- `suser` — Source user (actor ID)
- `src` — Source IP address
- `cs1` — Trace ID for request correlation
- `cs2` — Resource type (conversation, message, etc.)
- `cs3` — Resource ID

### ECS (Elastic Common Schema)

```json
{
  "@timestamp": "2026-03-25T10:30:00+00:00",
  "event": {
    "kind": "event",
    "category": ["authentication"],
    "type": ["start"],
    "action": "login",
    "outcome": "failure",
    "severity": 5,
    "module": "scambuster",
    "dataset": "scambuster.audit",
    "original": "AUTH_FAILURE"
  },
  "source": { "ip": "192.168.1.100" },
  "user": { "id": "admin@example.com", "type": "user" },
  "trace": { "id": "trace-abc123" },
  "message": "[AUTH_FAILURE] login by user:admin@example.com — failure"
}
```

### JSON (NDJSON)

```json
{"timestamp":"2026-03-25T10:30:00+00:00","event_type":"AUTH_FAILURE","severity":5,"severity_label":"Medium","category":"authentication","actor_type":"user","actor_id":"admin@example.com","action":"login","outcome":"failure","resource_type":null,"resource_id":null,"ip_address":"192.168.1.100","trace_id":"trace-abc123","details":{},"source":"scambuster"}
```

---

## Testing Procedures

### Test 1: File Export (No external dependencies)

```bash
# 1. Configure file export
echo 'SIEM_PROVIDER=file' >> .env
echo 'SIEM_ENDPOINT=/tmp/siem-events.ndjson' >> .env

# 2. Restart
docker compose restart backend-dev

# 3. Send test event
docker compose exec backend-dev php bin/console app:siem:test

# 4. Verify output
docker compose exec backend-dev cat /tmp/siem-events.ndjson
```

Expected: One JSON line per event with all fields populated.

### Test 2: Syslog with Netcat (Fake SIEM receiver)

```bash
# Terminal 1: Start a fake syslog receiver
nc -luk 5514

# Terminal 2: Configure syslog
# In .env:
# SIEM_PROVIDER=syslog
# SIEM_ENDPOINT=udp://host.docker.internal:5514
# SIEM_FORMAT=cef

docker compose restart backend-dev
docker compose exec backend-dev php bin/console app:siem:test
```

Expected: CEF-formatted syslog message appears in Terminal 1.

### Test 3: Batch Export (Historical events)

```bash
# Export last 24 hours of audit events
docker compose exec backend-dev php bin/console app:siem:export --since=24h

# Export last 7 days
docker compose exec backend-dev php bin/console app:siem:export --since=7d

# Dry run (count only, no export)
docker compose exec backend-dev php bin/console app:siem:export --since=30d --dry-run
```

### Test 4: Verify via API

Generate events by using the application normally (login, browse conversations, etc.), then check:

```bash
# Check audit log in database
curl -s -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/audit | python3 -m json.tool

# If using file export, check the file
docker compose exec backend-dev wc -l /tmp/siem-events.ndjson
```

---

## CLI Commands

### `app:siem:test`

Test SIEM connectivity and send a test event.

```bash
docker compose exec backend-dev php bin/console app:siem:test
```

### `app:siem:export`

Batch export historical audit events to the configured SIEM.

```bash
# Options
--since=24h       # Time window (24h, 7d, 30m, or YYYY-MM-DD)
--batch-size=100  # Events per batch
--dry-run         # Count only, don't export
```

---

## Troubleshooting

### "SIEM export is disabled"

The default provider is `none`. Set `SIEM_PROVIDER` in your `.env` file.

### "SIEM target is not reachable"

- Check `SIEM_ENDPOINT` format: `udp://host:port` or `tcp://host:port`
- Verify network connectivity from the Docker container
- For UDP: the health check opens a socket but can't verify the receiver is listening

### Events not appearing in SIEM

1. Check the application logs: `docker compose logs backend-dev | grep SIEM`
2. Verify the file/syslog target is writable
3. Run `app:siem:test` to send a test event
4. Check if the SIEM is configured to accept the format (CEF/ECS/JSON)

### Performance concerns

- The `NullSiemExporter` (default) has zero overhead
- File and syslog exports are synchronous but fast (< 1ms per event)
- For high-volume deployments (> 1000 events/min), consider a message queue (roadmap item)

---

## Adding a New Provider

To add support for a new SIEM platform (e.g., Splunk HEC, Elastic Bulk API):

1. Create an adapter implementing `SiemExporterInterface` in `src/Infrastructure/Siem/Adapter/`
2. Add the provider to `SiemCompilerPass::process()` with its factory method
3. Add format mapping in `SiemCompilerPass::FORMAT_DEFAULTS`
4. Write unit tests for the adapter
5. Update this documentation

The interface requires 4 methods:
- `export(SiemEvent $event): void`
- `exportBatch(array $events): void`
- `isHealthy(): bool`
- `getProviderName(): string`

---

## Security Considerations

- **PII masking**: Email addresses and IP addresses in exported events follow the same masking rules as application logs
- **Transport security**: Use TLS for TCP syslog (`tcp+tls://`, planned) or HTTPS for webhook-based providers
- **Authentication tokens**: Store SIEM tokens in environment variables (same security model as LLM API keys)
- **Audit of audit**: SIEM export failures are logged to the application log (Monolog) for monitoring

---

[← Back to Main](../README.md)
