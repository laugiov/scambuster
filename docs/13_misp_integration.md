# MISP Integration

> Connect ScamBuster to your MISP instance to share threat intelligence from honeypot conversations.

---

## Overview

ScamBuster exports IOCs as **MISP Event JSON**, ready for import into any MISP 2.4+ instance. Each conversation produces one MISP Event containing all extracted indicators (emails, domains, IBANs, crypto wallets, phone numbers, etc.) with proper MISP types, categories, and TLP markings.

### Which path to use

| | This page — MISP Event export | [TAXII feed](16_taxii_server.md) |
|---|---|---|
| Trigger | you call the endpoint, one conversation at a time | the consumer polls on a schedule |
| Format | MISP Event JSON (native) | STIX 2.1 |
| Credential | JWT | `TAXII_API_KEY` (a JWT expires after 900s) |
| Status | supported and tested | depends on your MISP supporting TAXII 2.1 feeds |

There is **no push**: nothing writes into MISP by itself. `MISP_URL` and
`MISP_API_KEY` are read only by `scambuster:misp:test`, which pings the instance.
Automating the export means scripting the endpoint below, or pointing a TAXII
consumer at the feed.

---

## What Gets Exported

Each IOC is mapped to a MISP attribute using the mapping defined in `IocExportMapper.php`:

| IOC Type | MISP Category | MISP Type | to_ids |
|----------|--------------|-----------|--------|
| email | Network activity | email-src | Yes |
| url | Network activity | url | Yes |
| domain | Network activity | domain | Yes |
| ipv4, ipv6 | Network activity | ip-src | Yes |
| iban | Financial fraud | iban | Yes |
| bic | Financial fraud | bic | Yes |
| wallet_btc | Financial fraud | btc | Yes |
| wallet_eth | Financial fraud | crypto-wallet | Yes |
| wallet_xmr | Financial fraud | xmr | Yes |
| phone | Person | phone-number | No |
| sha256 | Payload delivery | sha256 | Yes |
| md5 | Payload delivery | md5 | Yes |
| telegram_username | Social network | telegram-account | No |
| cve | External analysis | vulnerability | No |
| filename | Payload delivery | filename | No |

All IOC types are mapped to MISP attributes. Unmapped types default to `Other / other / to_ids=false`.

### MISP Event Structure

```json
{
  "Event": {
    "info": "ScamBuster conversation <conv_id>",
    "threat_level_id": 2,
    "analysis": 1,
    "distribution": 3,
    "Attribute": [
      {
        "category": "Network activity",
        "type": "email-src",
        "value": "scammer@evil.com",
        "to_ids": true,
        "comment": "Scam type: PHISH_CREDENTIALS | Risk score: 75/100",
        "Tag": [
          {"name": "tlp:amber"},
          {"name": "scam:type=PHISH_CREDENTIALS"}
        ]
      }
    ]
  }
}
```

---

## Export Methods

### Method 1: REST API (Pull)

Export a single conversation as a MISP Event:

```bash
# Get JWT token
TOKEN=$(curl -sS -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Un1que$trongPassword2024"}' \
  | jq -r '.access_token')

# Export conversation IOCs as MISP Event
curl -sS http://localhost:8081/api/v1/conversations/<conv_id>/export/misp \
  -H "Authorization: Bearer $TOKEN" | jq .
```

### Method 2: Import into MISP

1. Export the JSON to a file:
   ```bash
   curl -sS http://localhost:8081/api/v1/conversations/<conv_id>/export/misp \
     -H "Authorization: Bearer $TOKEN" > event.json
   ```

2. Import via MISP API:
   ```bash
   curl -X POST https://your-misp.example.com/events/add \
     -H "Authorization: YOUR_MISP_API_KEY" \
     -H "Content-Type: application/json" \
     -d @event.json
   ```

3. Or import via MISP UI: **Event Actions > Add Event > Import from...**

### Method 3: Test Connectivity

```bash
# Verify MISP is reachable and API key works
make misp-test
```

---

## Configuration

Add to your `.env`:

```
# MISP (optional -- only needed for push/test commands)
MISP_URL=https://your-misp.example.com
MISP_API_KEY=your-misp-api-key
```

These variables are **not required** for the pull export (Method 1). They are only needed for the `misp:test` command and future push capabilities.

---

## Test Connectivity

```bash
php bin/console scambuster:misp:test
```

This command:
1. Checks that `MISP_URL` and `MISP_API_KEY` are set
2. Sends `GET /servers/getVersion` to your MISP instance
3. Reports the MISP version and connection status

### Expected Output

**Success:**
```
MISP Connection Test
  URL:     https://your-misp.example.com
  Version: 2.4.178
  Status:  OK
```

**Not configured:**
```
MISP is not configured.
Set MISP_URL and MISP_API_KEY in your .env file.
```

**Connection error:**
```
MISP Connection Test
  URL:     https://your-misp.example.com
  Status:  FAILED
  Error:   Connection refused
```

---

## Troubleshooting

| Problem | Solution |
|---------|----------|
| 401 Unauthorized | Check your MISP API key is valid and has read/write permissions |
| Connection refused | Verify MISP_URL is correct and MISP is reachable from the Docker network |
| No IOCs in export | The conversation may not have extracted IOCs yet. Check `/api/v1/communication/conversation/{id}/iocs` |
| **Event returns with `"Attribute": []` although the conversation has IOCs** | Those observations carry no export metadata — they were written outside the ingest path (seeded demo dataset, or stored before the metadata step existed). The export now derives the mapping on the fly, so this should no longer happen; to also fix the stored rows (the IOC Explorer reads them for its export mapping), run `docker compose exec backend-dev bin/console app:migrate-iocs-export-metadata`. It is idempotent and skips already-enriched rows. `make demo-load` runs it automatically. |
| Missing MISP types | IOC types not in the mapping table default to `Other / other`. Check `IocExportMapper.php` for supported types |
| TLS certificate error | If using self-signed certificates, set `MISP_VERIFY_SSL=false` in `.env` |

---

## STIX 2.1 Export

For STIX 2.1 bundle export (alternative to MISP), see the Campaign Radar endpoints:

```bash
# Export campaign as STIX 2.1 bundle
curl -X POST http://localhost:8081/api/v1/campaign/<campaign_id>/export/stix \
  -H "Authorization: Bearer $TOKEN" | jq .

# Export conversation as STIX 2.1 bundle (includes threat-actor objects)
curl -sS http://localhost:8081/api/v1/conversations/<conv_id>/export/stix \
  -H "Authorization: Bearer $TOKEN" | jq .
```

STIX SCO type mappings are also defined in `IocExportMapper.php`.
