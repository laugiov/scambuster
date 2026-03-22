# API Quick Reference

> All endpoints grouped by domain. Paths are prefixed with `/api/v1` unless noted.
> Full interactive documentation: Swagger UI at `GET /api/doc`

---

## Authentication

All protected endpoints require a JWT Bearer token in the `Authorization` header.

```bash
# 1. Login
TOKEN=$(curl -sS -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"Un1que$trongPassword2024"}' \
  | jq -r '.access_token')

# 2. Use the token
curl -H "Authorization: Bearer $TOKEN" http://localhost:8081/api/v1/communication/conversation
```

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/auth/login` | No | Obtain JWT access + refresh tokens |
| POST | `/auth/refresh` | No | Refresh expired access token |
| POST | `/auth/logout` | No | Invalidate refresh token |
| GET | `/me` | Yes | Current user profile (id, email, roles) |

---

## Health & Monitoring

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/healthz` | No | Liveness probe (`{"status":"ok"}`) |
| GET | `/api/health` | No | Dependency checks (database, Redis) with latency |
| GET | `/api/metrics` | No | Prometheus text format (conversations, IOCs, health) |

---

## Conversations

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/communication/conversation` | Yes | List conversations (paginated) |
| POST | `/communication/conversation` | Yes | Create conversation |
| GET | `/communication/conversation/{convId}` | Yes | Get conversation detail |
| PATCH | `/communication/conversation/{convId}` | Yes | Update status, score, scam type |
| DELETE | `/communication/conversation/{convId}` | Yes | Soft delete |
| GET | `/communication/conversation/{convId}/messages` | Yes | List messages (paginated) |
| GET | `/communication/conversation/{convId}/iocs` | Yes | List deduplicated IOCs |
| POST | `/communication/conversation/{convId}/add-channel` | Yes | Add channel to conversation |
| POST | `/communication/conversation/{convId}/classify` | Yes | Manual classification |
| POST | `/communication/conversation/{convId}/auto-classify` | Yes | LLM auto-classification |

```bash
# Example: list conversations
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/communication/conversation | jq '.[0:3]'
```

---

## Messages

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/communication/message` | Yes | Create message (RFC822) |
| GET | `/communication/message/{msgId}` | Yes | Get message detail |
| PATCH | `/communication/message/{msgId}` | Yes | Update message |
| DELETE | `/communication/message/{msgId}` | Yes | Soft delete |
| GET | `/communication/message/{msgId}/attachments` | Yes | List attachments |
| POST | `/communication/message/{msgId}/attachments` | Yes | Upload attachment (max 5MB) |
| GET | `/communication/message/{msgId}/iocs` | Yes | List IOCs for message |
| POST | `/communication/message/{msgId}/extract-iocs` | Yes | Extract IOCs (regex/LLM/hybrid) |
| GET | `/communication/message/{msgId}/risk` | Yes | Calculate risk score |
| GET | `/communication/message/by-message-id/{messageId}` | Yes | Find by RFC822 Message-ID |

---

## Ingestion

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/communication/ingest/raw` | Yes | Ingest raw RFC822 email (used by n8n) |

---

## Reply Generation

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/communication/conversation/{convId}/context` | Yes | Get context for reply |
| POST | `/communication/reply/generate` | Yes | Generate LLM reply draft |
| POST | `/communication/reply/draft` | Yes | Save reply draft |
| GET | `/communication/reply/{msgId}` | Yes | Get reply detail |
| GET | `/communication/reply/{msgId}/compose` | Yes | Get threading headers |
| POST | `/communication/reply/{msgId}/sent` | Yes | Mark reply as sent |

---

## IOCs

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/iocs/enriched` | Yes | Ingest enriched IOC (from n8n) |
| PATCH | `/iocs/{obsId}/enrich` | Yes | Update IOC enrichment data |

---

## Attachments

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/communication/attachment/{id}/download` | Yes | Download file |
| DELETE | `/communication/attachment/{id}` | Yes | Soft delete |
| GET | `/communication/attachment/conversation/{convId}/attachments` | Yes | List all in conversation |

---

## Scam Types

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/communication/scam-types` | Yes | List all scam types |

---

## Export

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/conversations/{id}/export/misp` | ROLE_USER | Export as MISP Event JSON |

---

## Adaptive Scambaiting

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/scambaiting/select-persona` | ROLE_USER | Select persona (epsilon-greedy) |
| GET | `/scambaiting/stats` | No | Aggregated stats (all types) |
| GET | `/scambaiting/stats/{scamTypeCode}` | No | Stats for specific scam type |
| GET | `/scambaiting/persona/{personaCode}/performance` | No | Persona performance breakdown |
| POST | `/scambaiting/conversation/{convId}/close` | ROLE_USER | Close and calculate reward |

```bash
# Example: get stats for phishing
curl http://localhost:8081/api/v1/scambaiting/stats/PHISH_CREDENTIALS | jq .
```

---

## Campaign Radar

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/campaign/hunt` | ROLE_ADMIN | Execute shadow rules, compute PPV |
| GET | `/campaign/candidates` | No | List promotion candidates |
| GET | `/campaign/{id}/messages` | No | Campaign messages (max 100) |
| POST | `/campaign/{id}/profile` | No | LLM campaign profiling |
| POST | `/campaign/transpile` | No | Transpile DSL to SQL |
| POST | `/campaign/{id}/rules/compile` | ROLE_ADMIN | Compile rules from profile |
| POST | `/campaign/rule` | No | Store compiled rule |
| POST | `/campaign/rule/{ruleId}/promote` | ROLE_ADMIN | Promote to active detection |
| POST | `/campaign/cluster/assign` | No | Assign message to cluster |
| POST | `/campaign/{id}/export/stix` | ROLE_USER | Export STIX 2.1 bundle |

---

## Monitoring

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/monitoring/autonomy` | Yes | System status, kill switch, convergence |
| GET | `/monitoring/llm-cost` | Yes | Monthly LLM cost, per-purpose breakdown, daily trend |

```bash
# Example: check system status
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/autonomy | jq '.kill_switch_active'

# Example: check LLM costs
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/llm-cost | jq '.current_month'
```

---

## Meta (Frontend Config)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/meta/config` | Yes | Personas, scam types, IOC types, bandit config |

---

## Internal

Not intended for external use. Used by n8n and internal services.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/internal/mail-account/active` | ROLE_ADMIN | List active mail accounts |
| GET | `/api/v1/internal/mail-account/resolve-secret/{hash}` | ROLE_ADMIN | Resolve IMAP credentials via Vault |

---

## Error Responses

All endpoints return JSON errors:

```json
{
  "error": "Conversation not found",
  "code": 404
}
```

| Status | Meaning |
|--------|---------|
| 400 | Bad request (validation error) |
| 401 | Unauthorized (missing/expired JWT) |
| 403 | Forbidden (insufficient role) |
| 404 | Resource not found |
| 429 | Rate limited |
| 500 | Internal server error |

---

## Swagger UI

For full interactive documentation with request/response schemas:

```
http://localhost:8081/api/doc
```

OpenAPI JSON export:

```
http://localhost:8081/api/doc.json
```
