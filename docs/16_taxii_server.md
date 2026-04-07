# TAXII 2.1 Server

> Automated threat intelligence feed for OpenCTI, MISP, TheHive, and SIEM platforms.

---

## Overview

ScamBuster includes a built-in **TAXII 2.1 server** (read-only) that enables automated consumption of IOCs and campaign data by external threat intelligence platforms. Instead of manually exporting STIX bundles, consumers poll the TAXII server for new data on their own schedule.

IOC indicators include the **`x_scambuster_context` STIX extension** with contextual metadata: scam type, persona, revelation turn, extraction method, engagement duration, and (when LLM-enriched) semantic role, stimulus type, urgency score, and a PII-free context excerpt.

Conversation STIX exports include **`threat-actor`** objects with behavioral profiling (sophistication, goals, MITRE ATT&CK technique), linked to indicators via `indicates` relationships. The `x_scambuster_actor` extension provides engagement metrics and persona information.

The **TAXII IOC collection** also includes threat-actor objects: for each batch of indicators, the server enriches the response with threat-actors from the conversations behind those IOCs, along with `attack-pattern` and `indicates` relationships. This allows TAXII consumers (OpenCTI, MISP) to automatically ingest attribution data alongside IOCs.

### Architecture

```
ScamBuster (TAXII Server)
    │
    ├── /api/v1/taxii2/                          Discovery
    ├── /api/v1/taxii2/api/                      API Root
    ├── /api/v1/taxii2/api/collections/          Collections list
    └── /api/v1/taxii2/api/collections/{id}/objects/   STIX objects (delta sync)
            │
            ├──── OpenCTI polls every hour ──────────→ auto-import + enrichment
            ├──── MISP polls daily ──────────────────→ auto-import to events
            ├──── TheHive polls on demand ───────────→ case creation
            └──── SIEM (Splunk, QRadar) ────────────→ IOC watchlist update
```

### Available Collections

| Collection | ID | Content |
|------------|-----|---------|
| **ScamBuster IOCs** | `a1b2c3d4-0001-4000-8000-000000000001` | All indicators extracted from scam conversations (URLs, domains, IPs, emails, IBANs, crypto wallets, phones, hashes...) |
| **ScamBuster Campaigns** | `a1b2c3d4-0002-4000-8000-000000000002` | Promoted scam campaigns with attribution data |

---

## Quick Start

### 1. Get a JWT token

```bash
TOKEN=$(curl -sS -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Un1que$trongPassword2024"}' \
  | jq -r '.access_token')
```

### 2. Discover the server

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/taxii2/ | jq .
```

Response:
```json
{
  "title": "ScamBuster TAXII Server",
  "description": "TAXII 2.1 server for ScamBuster threat intelligence",
  "contact": "scambuster@localhost",
  "default": "/api/v1/taxii2/api/",
  "api_roots": ["/api/v1/taxii2/api/"]
}
```

### 3. List collections

```bash
curl -sS -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/taxii2/api/collections/ | jq .
```

### 4. Get IOC indicators

```bash
# Get the latest 10 IOCs
curl -sS -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8081/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/?limit=10" \
  | jq .
```

### 5. Delta sync (only new IOCs since last poll)

```bash
# Get only IOCs added after a specific date
curl -sS -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8081/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/?added_after=2026-04-01T00:00:00Z" \
  | jq .
```

The response includes pagination headers:
- `X-TAXII-Date-Added-First`: timestamp of the oldest object in the response
- `X-TAXII-Date-Added-Last`: timestamp of the newest object -- use this as `added_after` for the next poll

### 6. Filter by IOC type

```bash
# Get only URL indicators
curl -sS -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8081/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/?type=url&limit=5" \
  | jq .
```

---

## Integration Guides

### OpenCTI

1. Go to **Data** → **Ingestion** → **TAXII Feeds**
2. Click **Add TAXII Feed**
3. Configure:
   - **Name**: ScamBuster IOCs
   - **TAXII Server URL**: `http://<scambuster-host>:8081/api/v1/taxii2/api/`
   - **Collection**: `a1b2c3d4-0001-4000-8000-000000000001`
   - **Authentication**: Bearer token (use a long-lived JWT or service account)
   - **Polling interval**: 1 hour (recommended)
4. OpenCTI will automatically import STIX indicators and enrich them via its configured connectors (VirusTotal, AbuseIPDB, Shodan, etc.)

### MISP

1. Go to **Sync Actions** → **List Feeds**
2. Click **Add Feed**
3. Configure:
   - **URL**: `http://<scambuster-host>:8081/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/`
   - **Source Format**: STIX 2.1
   - **Headers**: `Authorization: Bearer <TOKEN>`
   - **Delta Merge**: Enable (uses `added_after` parameter)
   - **Frequency**: Daily

### TheHive

1. Configure a **TAXII connector** in Cortex
2. Set the TAXII server URL to `http://<scambuster-host>:8081/api/v1/taxii2/`
3. TheHive can create cases automatically from high-confidence indicators

### Splunk / QRadar / Elastic

Use the TAXII 2.1 client integration available in your SIEM:
- **Splunk**: Splunk Add-on for TAXII (TA-taxii2)
- **QRadar**: Threat Intelligence app with TAXII feed
- **Elastic**: Threat Intel module with TAXII indicator feed

---

## API Reference

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/taxii2/` | Server discovery |
| GET | `/api/v1/taxii2/api/` | API root information |
| GET | `/api/v1/taxii2/api/collections/` | List available collections |
| GET | `/api/v1/taxii2/api/collections/{id}/objects/` | Get STIX objects from a collection |

### Authentication

All endpoints require a valid JWT Bearer token:
```
Authorization: Bearer <access_token>
```

The user must have the `ioc:read` permission.

### Query Parameters (Objects Endpoint)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `added_after` | ISO 8601 datetime | none | Only return objects added after this date |
| `limit` | integer | 100 | Maximum objects to return (max: 1000) |
| `type` | string | none | Filter by IOC type (e.g., `url`, `domain`, `email`, `ipv4`) |

### Response Format

The objects endpoint returns a STIX 2.1 envelope:

```json
{
  "more": false,
  "objects": [
    {
      "type": "indicator",
      "spec_version": "2.1",
      "id": "indicator--686f6110-f683-46ca-b809-6eeb979eea69",
      "created": "2026-02-06T17:38:43+00:00",
      "modified": "2026-02-06T17:38:43+00:00",
      "name": "url: https://malicious-site.com/phishing",
      "pattern": "[url:value = 'https://malicious-site.com/phishing']",
      "pattern_type": "stix",
      "valid_from": "2026-02-06T17:38:43+00:00",
      "confidence": 87,
      "labels": ["malicious-activity"]
    }
  ]
}
```

### STIX Pattern Mapping

| IOC Type | STIX Pattern |
|----------|-------------|
| domain | `[domain-name:value = '...']` |
| url | `[url:value = '...']` |
| ipv4 | `[ipv4-addr:value = '...']` |
| ipv6 | `[ipv6-addr:value = '...']` |
| email | `[email-addr:value = '...']` |
| sha256 | `[file:hashes.'SHA-256' = '...']` |
| md5 | `[file:hashes.MD5 = '...']` |
| Other | `[x-scambuster:value = '...']` |

### Response Headers

| Header | Description |
|--------|-------------|
| `Content-Type` | `application/taxii+json;version=2.1` |
| `X-TAXII-Date-Added-First` | Timestamp of oldest object in response |
| `X-TAXII-Date-Added-Last` | Timestamp of newest object -- use for next `added_after` |

### Error Responses

| Status | Description |
|--------|-------------|
| 401 | Missing or invalid JWT token |
| 403 | User lacks `ioc:read` permission |
| 404 | Unknown collection ID |

---

## Automated Polling Script

Example script for periodic IOC collection:

```bash
#!/bin/bash
# poll-scambuster.sh -- Poll ScamBuster TAXII for new IOCs

SCAMBUSTER_URL="http://localhost:8081"
EMAIL="admin@example.com"
PASSWORD="Un1que\$trongPassword2024"
COLLECTION="a1b2c3d4-0001-4000-8000-000000000001"
STATE_FILE="/tmp/scambuster-taxii-last-poll.txt"

# Get JWT token
TOKEN=$(curl -sS -X POST "$SCAMBUSTER_URL/api/v1/auth/login" \
  -H "Content-Type: application/json" \
  -d "{\"email\":\"$EMAIL\",\"password\":\"$PASSWORD\"}" \
  | jq -r '.access_token')

# Read last poll timestamp
ADDED_AFTER=""
if [ -f "$STATE_FILE" ]; then
  ADDED_AFTER="&added_after=$(cat $STATE_FILE)"
fi

# Poll for new objects
RESPONSE=$(curl -sS -D /tmp/taxii-headers.txt \
  -H "Authorization: Bearer $TOKEN" \
  "$SCAMBUSTER_URL/api/v1/taxii2/api/collections/$COLLECTION/objects/?limit=1000$ADDED_AFTER")

# Count objects
COUNT=$(echo "$RESPONSE" | jq '.objects | length')
echo "[$(date)] Received $COUNT new IOCs"

# Save last poll timestamp for next run
LAST_ADDED=$(grep -i "X-TAXII-Date-Added-Last" /tmp/taxii-headers.txt | tr -d '\r' | awk '{print $2}')
if [ -n "$LAST_ADDED" ]; then
  echo "$LAST_ADDED" > "$STATE_FILE"
fi

# Process objects (pipe to jq, save to file, or send to another tool)
echo "$RESPONSE" | jq '.objects[]' > /tmp/scambuster-iocs-latest.json
```

Schedule with cron:
```
# Poll every hour
0 * * * * /opt/scripts/poll-scambuster.sh >> /var/log/scambuster-poll.log 2>&1
```

---

## Troubleshooting

### 401 Unauthorized

- Verify your JWT token is valid (tokens expire after 15 minutes)
- Use the refresh endpoint to get a new token: `POST /api/v1/auth/refresh`
- For automated polling, refresh the token before each poll

### Empty results

- Check that indicators exist in the database: `GET /api/v1/iocs`
- The `added_after` parameter filters by `updated_at` -- if all indicators are older than the specified date, the result will be empty
- Reset by removing the `added_after` parameter

### `more: true` in response

- The response was truncated at the `limit` parameter
- Increase `limit` (max 1000) or use `added_after` from the `X-TAXII-Date-Added-Last` header to paginate
