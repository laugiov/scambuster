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
            ├──── OpenCTI polls hourly ──────→ auto-import   (verified)
            ├──── MISP ─────────────────────→ see note below (unverified)
            ├──── TheHive / Cortex ─────────→ see note below (unverified)
            └──── SIEM TAXII clients ───────→ see note below (unverified)
```

> **Only the OpenCTI path has been exercised against a live instance.** The
> others are standards-based — any TAXII 2.1 client can read these collections —
> but the procedures below carry no such verification. Treat them as starting
> points and expect to adjust.
>
> Note that OpenCTI imports **Indicators, not Cyber Observables**, so
> observable-enrichment connectors (VirusTotal, AbuseIPDB, Shodan) have nothing
> to act on unless you enable observable creation on the platform side.

### Available Collections

| Collection | ID | Content |
|------------|-----|---------|
| **ScamBuster IOCs** | `a1b2c3d4-0001-4000-8000-000000000001` | All indicators extracted from scam conversations (URLs, domains, IPs, emails, IBANs, crypto wallets, phones, hashes...), each carrying how it was elicited |
| **ScamBuster Campaigns** | `a1b2c3d4-0002-4000-8000-000000000002` | Promoted scam campaigns with attribution data |
| **ScamBuster Threat Actors** | `a1b2c3d4-0003-4000-8000-000000000003` | Consolidated threat-actor clusters, their psychological profile, the scammer **TTPs** as kill-chain attack-patterns, and TTP sightings |

> **Subscribe to both `0001` and `0003`.** The TTP intelligence and the actor
> psychological profiles live in the threat-actor collection; a consumer wired
> only to the IOC collection gets indicators and no tactics.

---

## Choosing a credential

| | JWT (`Authorization: Bearer`) | API key (HTTP Basic or `X-TAXII-API-KEY`) |
|---|---|---|
| Lifetime | **900 seconds** | until rotated |
| Use for | interactive exploration, scripts you run yourself | unattended feeds (OpenCTI, MISP, TheHive, SIEM) |
| Scope | whatever the user is allowed | `ioc:read` on `/api/v1/taxii2` only |

**An unattended consumer must use the API key.** Consumers store one credential
and never refresh it, so a feed configured with a JWT ingests once and then fails
every poll with `401` — silently, since the platform keeps the objects it already
imported. Set it in `.env` and restart the backend:

```env
TAXII_API_KEY=<openssl rand -hex 32>
```

Empty (the default) leaves the feature off and the feed JWT-only. Keys shorter
than 32 characters are ignored. Rotate by changing the value, restarting the
backend, then updating each consumer.

The key is deliberately **not** accepted as `Authorization: Bearer` — that
namespace belongs to the JWT authenticator, and a request carrying both would be
rejected by it. Send it as HTTP Basic (any username, key as password) or in the
`X-TAXII-API-KEY` header.

## Quick Start

### 1. Get a credential

With the API key (what a feed uses):

```bash
KEY=$(grep -m1 '^TAXII_API_KEY=' .env | cut -d= -f2-)
# then: curl -sS -u "taxii:$KEY" ...   (or -H "X-TAXII-API-KEY: $KEY")
```

Or a JWT, for a quick interactive look:

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

Full guide, including what lands where in the platform and the Docker networking
traps: **[OpenCTI Integration](11_opencti_integration.md)**.

In short — create **two** feeds, one per collection, because they carry different
objects and a single feed on the IOC collection leaves the TTP and actor screens
empty:

| Feed | Collection |
|---|---|
| ScamBuster IOCs | `a1b2c3d4-0001-4000-8000-000000000001` |
| ScamBuster Threat Actors & TTPs | `a1b2c3d4-0003-4000-8000-000000000003` |

Point both at the API root `http://<scambuster-host>:8081/api/v1/taxii2/api/`
(version 2.1), authenticate with `TAXII_API_KEY` over HTTP Basic — `taxii:<key>`,
never a JWT — and poll hourly.

### MISP

> **Unverified path.** MISP's own feed formats are *MISP*, *Freetext* and *CSV*;
> whether your MISP build can poll a TAXII 2.1 endpoint as a Feed depends on the
> version and on whether the STIX import module is enabled. If it cannot, use the
> per-conversation MISP Event export instead — it is the supported, tested path:
> see [MISP Integration](13_misp_integration.md).

1. Go to **Sync Actions** → **List Feeds**
2. Click **Add Feed**
3. Configure:
   - **URL**: `http://<scambuster-host>:8081/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/`
   - **Source Format**: STIX 2.1
   - **Headers**: `Authorization: Basic <base64 of taxii:$TAXII_API_KEY>`
   - **Delta Merge**: Enable (uses `added_after` parameter)
   - **Frequency**: Daily

### TheHive — unverified

> No TheHive or Cortex instance has been tested against this server, and
> ScamBuster ships no TheHive-specific code. What follows is the standards-based
> path; if you make it work, a PR correcting this section is welcome.

1. Configure a **TAXII connector** in Cortex
2. Set the TAXII server URL to `http://<scambuster-host>:8081/api/v1/taxii2/`
3. Authenticate with `TAXII_API_KEY` (HTTP Basic, key as the password)
4. TheHive can then create cases from high-confidence indicators

### Splunk / QRadar / Elastic — unverified

> Same caveat: never tested against a live instance. For pushing ScamBuster's
> **audit events** to a SIEM there is a supported, implemented path — CEF, ECS or
> JSON over file or syslog, see [SIEM Integration](15_siem_integration.md). The
> TAXII route below is for pulling **IOCs** and is untested.

Use the TAXII 2.1 client integration available in your SIEM, authenticating with
`TAXII_API_KEY` over HTTP Basic:
- **Splunk**: Splunk Add-on for TAXII (TA-taxii2)
- **QRadar**: Threat Intelligence app with TAXII feed
- **Elastic**: Threat Intel module with TAXII indicator feed

> Every consumer on this page polls unattended, so all of them need the API key
> rather than a JWT — a JWT expires after 900 seconds and the feed then fails
> every poll with 401. See [Choosing a credential](#choosing-a-credential).

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

All endpoints require a credential. Two are accepted — see
[Choosing a credential](#choosing-a-credential):

```
Authorization: Bearer <access_token>       # JWT, expires after 900s
Authorization: Basic <base64(user:key)>    # TAXII_API_KEY, does not expire
X-TAXII-API-KEY: <key>                     # same key, header form
```

A JWT user must hold the `ioc:read` permission. The API key grants exactly that
permission, on `/api/v1/taxii2` only — it cannot reach any other endpoint.

### Query Parameters (Objects Endpoint)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `added_after` | ISO 8601 datetime | none | Only return objects added after this date |
| `limit` | integer | 100 | Maximum objects to return (max: 1000) |
| `type` | string | none | Filter by IOC type (e.g., `url`, `domain`, `email`, `ipv4`) |
| `next` | string (opaque) | none | Pagination cursor echoed from the previous response envelope. See [Pagination](#pagination) below. |

### Response Format

The objects endpoint returns a STIX 2.1 envelope. When more objects remain,
`more` is `true` and `next` carries an opaque cursor to fetch the following page:

```json
{
  "more": true,
  "next": "eyJ0IjoiMjAyNi0wMi0wNiAxNzozODo0MyIsImkiOiI2ODZmNjExMC1mNjgzLTQ2Y2EtYjgwOS02ZWViOTc5ZWVhNjkifQ",
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

`next` is absent (and `more` is `false`) on the final page.

### Pagination

Two complementary mechanisms:

- **Cursor (`next`)** — the robust way to walk a full collection. Each response with
  `more: true` includes an opaque `next` token; pass it back as `?next=<token>` to get
  the following page. The cursor is a total order over `(updated_at, indicator_id)`, so
  pagination is **skip-free even when many indicators share the same `updated_at`
  second** — the failure mode a naive timestamp boundary suffers from. Treat the token
  as opaque; do not parse or fabricate it.

  ```bash
  # Page 1
  curl -sS -H "Authorization: Bearer $TOKEN" \
    "$BASE/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/?limit=500" | jq '{more, next}'

  # Page 2 — feed the cursor back verbatim
  curl -sS -H "Authorization: Bearer $TOKEN" \
    "$BASE/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/?limit=500&next=<token>" | jq '{more, next}'
  ```

- **Delta sync (`added_after`)** — for incremental polling: persist the
  `X-TAXII-Date-Added-Last` header and pass it as `added_after` on the next poll to pull
  only what changed. `added_after` and `next` compose (the cursor paginates within an
  `added_after` window).

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

### Threat-actor intelligence SDOs

The per-conversation STIX bundle (`GET /conversations/{id}/export/stix`) carries first-party
threat-actor intelligence beyond the indicators:

| Object | What it conveys |
|--------|-----------------|
| `sighting` (one per indicator) | The "seen N times" evidence made explicit: `count` (from indicator occurrences), `first_seen` / `last_seen`, and `where_sighted_refs` = the ScamBuster honeypot identity. Previously folded into indicator confidence. |
| `observed-data` + SCO | For standard-observable IOC types (email-addr, domain-name, url, ipv4/6-addr, file) the raw observation layer: `first_observed` / `last_observed` / `number_observed` pointing to a Cyber Observable Object. Financial/contact types (IBAN, wallet, phone) keep indicator + sighting only (no standard SCO). |
| `threat-actor` extension `x_scambuster_actor_psych` | On a **clustered** actor: the psychological fingerprint — dominant + secondary Cialdini levers, behavioural summary, escalation pattern, victim targeting, behavioural signals. See [Threat-Actor Profiling](21_threat_actor_profiling.md). |
| `note` `x_scambuster_mirror` | The Cognitive Mirror framing for the conversation's persona × scam type. |

Analyst verdicts (see [feedback loop](12_api_quick_reference.md#threat-actor-intelligence)) are
folded into each indicator's `confidence` at export time: a confirmed IOC exports high, a
false-positive near-zero.

### Flat feed export (CSV / NDJSON)

Not every consumer wants STIX. For quick spreadsheet triage or line-based SIEM ingestion,
a selection of IOCs can be pulled as a flat feed:

```
POST /api/v1/iocs/export/feed        (permission: ioc:export)
{ "indicator_ids": ["<uuid>", ...], "format": "csv" | "ndjson" }
```

- **`csv`** (default) — RFC 4180, one header row + one row per IOC (`Content-Type: text/csv`).
- **`ndjson`** — one JSON object per line, for streaming ingestion / `jq` (`Content-Type: application/x-ndjson`).

Both carry the same authoritative columns straight from the database:
`indicator_id, type, value, value_norm, tlp, score, occurrences, first_seen, last_seen, scam_type`.
Selection is capped at 500 indicators, mirroring the STIX bundle export. Like that export
(and unlike the shared TAXII feed) this is an authenticated analyst pull, so it does **not**
strip TLP:RED — the caller is trusted via `ioc:export`.

```bash
# Stream a selection straight into a SIEM as NDJSON
curl -sS -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"indicator_ids":["<uuid1>","<uuid2>"],"format":"ndjson"}' \
  "http://localhost:8081/api/v1/iocs/export/feed"
```

### Multi-label scam taxonomy & MISP galaxies

A conversation is classified with one **primary** scam type and, when the classifier
is confident enough, one or more **secondary** types (`conversation.secondary_scam_types`).
Both surface in the exports so downstream CTI sees the full picture, not just the headline label:

- **STIX** — the per-conversation bundle (`GET /conversations/{id}/export/stix`) adds each
  secondary scam-type code to the indicator `labels`, alongside the primary (deduped).
- **MISP** — the MISP Event (`GET /conversations/{id}/export/misp`) carries event-level `Tag[]`
  for the primary **and** every secondary type, each mapped to standard machine tags:

  | Tag | Source | Example |
  |-----|--------|---------|
  | RSIT taxonomy | `lkp_scam_type.misp_taxonomy` (verbatim) | `rsit:fraud="phishing"` |
  | MITRE ATT&CK galaxy | `lkp_scam_type.attck_technique` → verified MISP cluster value | `misp-galaxy:mitre-attack-pattern="Phishing - T1566"` |
  | First-party scam type | scam-type code | `scambuster:scam-type="PHISHING"` |

Tags are **deduplicated** — several scam types legitimately share one RSIT class or ATT&CK
technique (e.g. ROMANCE and TECH_SUPPORT both map to `rsit:fraud="scam"` / `T1656`), so the
shared tag appears once. The ATT&CK galaxy value uses only the small set of **authoritatively
verified** technique names present in the taxonomy; any unmapped technique emits **no** galaxy
tag rather than a guessed one.

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
- Preferred: feed the `next` cursor from the envelope back as `?next=<token>` to fetch the
  following page (skip-free; see [Pagination](#pagination))
- Or increase `limit` (max 1000), or use `added_after` from the `X-TAXII-Date-Added-Last`
  header for incremental delta polling

---

## Conformance

What this server and its exports are tested to do — and, separately, what is claimed
but not yet proven — is in the
[interoperability conformance statement](standards/interoperability-conformance.md).
Each claim there sits next to the automated test that proves it.

Two things worth reading before integrating:

- The server is **publish-only**. It implements discovery, collections and object
  retrieval, and no write path at all.
- Cluster bundles reference indicators published in the IOC collection rather than
  duplicating them, so a consumer reading a cluster bundle standalone will see those
  references unresolved. Subscribe to both collections.
