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
| POST | `/communication/message/{msgId}/extract-ttps` | `conversation:write` | Extract scammer TTPs (inbound-only). `400` for an outgoing message; `503` when `TTP_EXTRACTION_ENABLED=false`. Evidence text is never returned. |
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
| POST | `/communication/reply/generate` | Yes | Generate LLM reply draft. `force` waives the reply spacing only — not the ceilings, the kill switch, the budget cap, or the alternation invariant. `bypass_rate_limits` is the operator override that waives the three ceilings; leave it unset for automated flows. |
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
| GET | `/api/v1/iocs` | Yes | List all IOCs with confidence score, decay factor, effective score, `has_context` flag |
| GET | `/iocs/{indicatorId}/context` | Yes | Contextual enrichment: structural (turn, scam type, persona, co-revealed) + semantic (role, stimulus, urgency, excerpt) |
| GET | `/conversations/{conv_id}/export/stix` | Yes | STIX 2.1 bundle with indicators + threat-actor + sightings + observed-data + attack-pattern + relationships. `?include_threat_actor=false` for IOCs only |
| POST | `/iocs/export/stix` | `ioc:export` | Bulk STIX 2.1 bundle for a selection: `{"indicator_ids":[...]}` (max 500) |
| POST | `/iocs/export/feed` | `ioc:export` | Flat feed for a selection: `{"indicator_ids":[...],"format":"csv"\|"ndjson"}` (max 500). CSV for spreadsheets/grep, NDJSON for streaming SIEM ingestion. See [TAXII / STIX guide](16_taxii_server.md#flat-feed-export-csv--ndjson). |

---

## Threat-Actor Intelligence

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/clusters/{id}/psych-profile` | `ioc:read` | Per-actor psychological profile: dominant + secondary Cialdini levers, behavioural summary, escalation pattern, victim targeting, behavioural signals. `404` when not generated yet. See [Threat-Actor Profiling](21_threat_actor_profiling.md). |
| GET | `/clusters/{id}/temporal` | `ioc:read` | Per-actor temporal analysis (computed on-read from inbound messages): activity window, hour-of-day + day-of-week cadence, busiest day, burst days (≥ 2× median daily volume), longest dormancy. `404` when the cluster has no inbound activity. |
| GET | `/clusters/{id}/abuse-report` | `ioc:export` | Factual abuse / takedown report combining actor identity, actionable indicators (each routed to its standard abuse desk — bank / exchange / registrar / …), temporal activity and the psychological profile, plus a ready-to-send plain-text rendering (`text`). First-party only, with a provenance disclaimer. Indicators are filtered by the same export policy as STIX and TAXII: analyst false-positives are omitted, and financial indicators are withheld until an analyst confirms them — so a report may legitimately carry no indicators. `404` for an unknown cluster. |
| POST | `/iocs/{indicatorId}/feedback` | `ioc:feedback` | Analyst verdict `{"verdict":"confirmed"\|"false_positive","note":"..."}`. Overrides export confidence (confirmed → high, false-positive → near-zero). Audited (`IOC_FEEDBACK`). |

---

## TTP Intelligence

Scammer tactics tagged on inbound messages against a closed 27-entry taxonomy. Verbatim
evidence is stored internally only and never appears in any response (consumers see offsets).
See [High-Level Architecture](03_high_level_architecture.md#3-llm-pipeline) and
[Reading the TTP screens](26_reading_the_ttp_screens.md).

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/ttps` | `ioc:read` | The taxonomy (27 TTPs: code, label, phase, definition, example formulations, external ATT&CK references) with observation counts. |
| GET | `/conversations/{id}/ttps` | `conversation:read` | Ordered per-conversation TTP sequence and the stimulus → TTP → IOC elicitation timeline. |
| GET | `/clusters/{id}/ttps` | `ioc:read` | Per-cluster TTP profile: frequencies, first/last seen, top sequences. No evidence text. |
| GET | `/ttps/cluster-matrix` | `ioc:read` | Cluster × TTP overlap matrix for the TTP Explorer. |
| GET | `/ttps/{code}/iocs` | `ioc:read` | IOCs co-observed with a given TTP (IOC ↔ TTP pivot). |
| GET | `/iocs/{id}/ttps` | `ioc:read` | TTPs co-observed with a given IOC (IOC ↔ TTP pivot). |
| GET | `/ttps/review-queue` | `ioc:read` | Observations awaiting analyst review, newest message first (500 most recent; `total` reports the full queue). Offsets only — no evidence text. |
| GET | `/ttps/phase-trend` | `ioc:read` | Weekly confirmed-observation counts per kill-chain phase over the last 8 weeks, bucketed on message time, zero-filled. |
| GET | `/ttps/{code}/clusters` | `ioc:read` | Live clusters with confirmed observations of a TTP (top 50, explicit `truncated` flag). `404` for an unknown code. |
| GET | `/ttps/{code}/conversations` | `ioc:read` | Paginated conversations where a TTP was observed (`limit` default 20, max 100; `offset`), with the per-row confirmed/review split. `404` for an unknown code. |
| GET | `/ttps/sequences` | `ioc:read` | Top cross-message tactic bigrams (`A → B`) per group, `?group=cluster\|scam_type`. Confirmed only, folded across message boundaries; `min_support` is the minimum distinct conversations a pair must appear in (each pair reports its occurrence `count` and `conversation_count`). `400` for an invalid group. |
| GET | `/ttps/phase-transitions` | `ioc:read` | Kill-chain phase-transition aggregate: the same cross-message bigrams grouped by the from/to kill-chain phase, plus `total_pairs`. |
| GET | `/ttps/persona-matrix` | `ioc:read` | Persona × TTP matrix (confirmed observations). Cells carry raw `observation_count` and per-conversation `conversation_count`; null-persona conversations are excluded and reported in `null_persona_conversations`; personas are capped with a `truncated` flag. |
| GET | `/ttps/stimulus-matrix` | `ioc:read` | Stimulus × TTP matrix scoped to revelation messages carrying **both** an enriched stimulus and a confirmed TTP; `population_messages` reports the in-scope message count. |

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
| GET | `/conversations/{id}/export/misp` | ROLE_USER | Export as MISP Event JSON. Event-level `Tag[]` carry the full scam-type classification (primary + secondary), each as RSIT taxonomy (`rsit:fraud="..."`) + MITRE ATT&CK MISP galaxy (`misp-galaxy:mitre-attack-pattern="..."`) + first-party (`scambuster:scam-type="..."`) machine tags. See [multi-label taxonomy](16_taxii_server.md#multi-label-scam-taxonomy--misp-galaxies). |

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

> **Note**: Campaign detection pipeline is experimental and not connected to the automated message flow.

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
| POST | `/campaign/{id}/export/stix` | ROLE_USER | Export STIX 2.1 bundle |

---

## Monitoring

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/monitoring/autonomy` | Yes | System status, kill switch, convergence |
| GET | `/monitoring/llm-cost` | Yes | Monthly LLM cost, per-purpose breakdown, daily trend |
| GET | `/monitoring/conversation-lifecycle` | Yes | Active conversations, about-to-timeout list, by scam type |
| GET | `/monitoring/rate-limits` | Yes | Rate limit stats, quarantined senders, daily limit hits |
| GET | `/monitoring/convergence-history` | Yes | Last 30 days bandit convergence snapshots by scam type |
| GET | `/monitoring/audit` | Yes | Structured audit trail (paginated, all event types) |
| GET | `/monitoring/pipeline-traces` | Yes | Recent pipeline execution traces (paginated, filterable by persona/scam_type) |
| GET | `/monitoring/pipeline-traces/{msgId}` | Yes | Full pipeline trace for a specific outbound message |
| GET | `/monitoring/pipeline-health` | Yes | Aggregated pipeline health: per-component success rates, avg cost, avg duration |
| GET | `/monitoring/injection` | Yes | Prompt injection detection stats: coverage, risk distribution, recent alerts |

```bash
# Example: check system status.
# `kill_switch_active` reflects BOTH ways of halting the pipeline: the runtime admin
# toggle (POST /admin/llm/killswitch) and the SCAMBUSTER_KILL_SWITCH deployment
# variable. It resolves through the same reader the reply pipeline enforces with, so
# `true` here means replies really are halted, and `status` reports `degraded`.
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/autonomy | jq '.kill_switch_active'

# Example: check LLM costs
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/llm-cost | jq '.current_month'

# Example: conversation lifecycle (timeout alerts)
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/conversation-lifecycle | jq '.about_to_timeout_list'

# Example: rate limit status
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8081/api/v1/monitoring/rate-limits | jq '.rate_limited_today'
```

---

## Meta (Frontend Config)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/meta/config` | Yes | Personas, scam types, IOC types, bandit config, LLM provider/model |

---

## Internal

Not intended for external use. Used by n8n and internal services.

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/internal/mail-account/active` | ROLE_ADMIN | List active mail accounts |

---

## CLI Commands

| Command | Schedule | Description |
|---------|----------|-------------|
| `app:close-stale-conversations` | Daily | Close conversations exceeding per-scam-type lifecycle policies (timeout, max turns, max duration) |
| `app:bandit:daily-report` | Daily 6h UTC | Log convergence snapshot per scam type to `bandit_convergence_log` |
| `app:cleanup:weekly` | Weekly Sun 4h UTC | Soft-delete closed conversations >90 days, purge LLM usage >180 days |
| `scambuster:ttp:backfill` | On demand | Extract scammer TTPs over historical inbound messages. Preview by default; `--apply` to persist. `--limit`, `--conv-days`, `--budget-usd`, `--force` (recompute). Idempotent and resumable. |
| `scambuster:ttp:audit-sample` | On demand | Export a random sample of TTP observations (WITH raw evidence) to a CSV for internal manual precision audit. `--limit`, `--output`/`-o`, `--seed`, `--ttp`. The only path by which evidence text leaves the database; computes no precision figure. |
| `scambuster:ttp:demo-seed` | On demand | Seed deterministic, plausible TTP observations for the keyless demo (mock LLM, no real extractor). Phrase-matches demo message bodies against a scam-type-aware tactic map; rows stamped `extraction_model = demo-seed`. Idempotent (`ON CONFLICT DO NOTHING`); `--limit`, `--purge`, `--dry-run`. |

### SIEM Commands

| Command | Description |
|---------|-------------|
| `app:siem:test` | Test SIEM provider connectivity and send test event |
| `app:siem:export --since=24h` | Batch export historical audit events to configured SIEM |
| `app:siem:export --since=7d --dry-run` | Dry run: count events without exporting |

```bash
# Run stale closure with per-type policies
docker compose exec backend-dev php bin/console app:close-stale-conversations

# Run with global override (7 days for all types)
docker compose exec backend-dev php bin/console app:close-stale-conversations --days=7

# Dry run bandit daily report
docker compose exec backend-dev php bin/console app:bandit:daily-report

# Weekly cleanup (dry run)
docker compose exec backend-dev php bin/console app:cleanup:weekly --dry-run
```

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

## TAXII 2.1 (Automated Feed)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| GET | `/api/v1/taxii2/` | Yes | TAXII discovery (server info, API roots) |
| GET | `/api/v1/taxii2/api/` | Yes | API root (versions, max content length) |
| GET | `/api/v1/taxii2/api/collections/` | Yes | List collections (IOCs, Campaigns, Threat Actors) |
| GET | `/api/v1/taxii2/api/collections/{id}/objects/` | Yes | Get STIX objects (delta sync via `added_after`) |

```bash
# Get latest 5 objects (indicators + threat-actors) as STIX
curl -H "Authorization: Bearer $TOKEN" \
  "http://localhost:8081/api/v1/taxii2/api/collections/a1b2c3d4-0001-4000-8000-000000000001/objects/?limit=5" | jq .
```

The IOC collection serves STIX 2.1 indicators alongside threat-actor objects derived from conversation analysis.

See [TAXII 2.1 Server Guide](16_taxii_server.md) for OpenCTI/MISP integration.

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
