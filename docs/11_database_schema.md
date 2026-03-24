# Database Schema

> Extracted from the PostgreSQL preprod database. 21 tables, 3 views.

---

## Entity-Relationship Overview

```
                          +---------------+
                          |  app_users    |
                          +-------+-------+
                                  |
                          +-------v--------+
                          | refreshtoken   |
                          +----------------+

+-------------+     +---------------+     +---------------+
| lkp_channel |<----| conversation  |---->| lkp_scam_type |
+-------------+     +---+-+---+-----+     +-------+-------+
      ^                 | |   |                   |
      |   +-------------+ |   +--------+    +-----v----------+
      |   |                |            |    | scam_type_     |
      |   v                v            v    |   persona      |
+-----+--------+   +-----------+  +-------+ +-----+----------+
| conversation |   |  message  |  |persona|       |
|   _channel   |   +--+--+--+-+  +-------+       v
+--------------+      |  |  |                +---------+
                      |  |  +-----+          | persona_|
                      v  |        v          | perf_   |
              +----------+  +----------+     |  stats  |
              |attachment|  |observed_  |     +---------+
              +----------+  |   ioc    |
                            +----+-----+
                                 |
                            +----v-----+
                            | indicator|
                            +----------+

+-----------+   +--------------+   +---------------+
| campaign  |<--| campaign_    |   | actor_profile |
+-----------+   |    rule      |   +---------------+
      ^         +--------------+
      |
+-----+---------+
| message_      |
|   campaign    |
+---------------+

+----------------+
| mail_account   |
+----------------+

+----------------+
| message_vector |
+----------------+
```

---

## Core Tables

### conversation

Primary aggregate. One conversation per scam email thread.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| conv_id | uuid | PK | | Conversation identifier |
| primary_channel_id | integer | NO | | FK to lkp_channel |
| scam_type_id | integer | NO | | FK to lkp_scam_type |
| account_id | uuid | NO | | FK to mail_account |
| persona_id | integer | YES | | FK to persona (assigned by bandit) |
| status | varchar(255) | NO | | open, closed, abandoned, mistake |
| score_risk | integer | NO | | Risk score (0-100) |
| ts_first | timestamp | NO | | First message timestamp |
| ts_last | timestamp | NO | | Last message timestamp |
| stix_id | varchar(255) | NO | | STIX 2.1 identifier (unique) |
| engagement_duration_sec | integer | NO | 0 | Total engagement time in seconds |
| turns_count | integer | NO | 0 | Number of message exchanges |
| reward_value | numeric(5,4) | YES | | Bandit reward [0.0, 1.0] |
| delivery | varchar(32) | NO | | Delivery status |
| tlp | varchar(16) | NO | | Traffic Light Protocol marking |
| created_at | timestamp | NO | | |
| updated_at | timestamp | NO | | |
| deleted_at | timestamp | YES | | Soft delete |

**Foreign keys**: lkp_channel, lkp_scam_type, mail_account, persona

---

### message

One message per email (inbound or outbound).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| msg_id | uuid | PK | | Message identifier |
| conv_id | uuid | NO | | FK to conversation (CASCADE) |
| channel_id | integer | NO | | FK to lkp_channel |
| direction | integer | NO | | FK to lkp_direction (1=in, 2=out) |
| reply_to_msg_id | uuid | YES | | FK to message (self-ref threading) |
| lang_detect | varchar(2) | NO | | Detected language (en, fr, ...) |
| subject | varchar(255) | YES | | Email subject |
| body_text | text | NO | | Plain text body |
| body_html | text | YES | | HTML body |
| headers | json | NO | | RFC822 headers |
| composite_hash | varchar(64) | NO | | Deduplication hash (unique) |
| vector_id | uuid | YES | | FK to message_vector |
| external_message_id | varchar(255) | YES | | RFC822 Message-ID header |
| raw_source | text | YES | | Raw RFC822 source |
| url_analysis | json | YES | | URL analysis results |
| injection_analysis | json | YES | | Prompt injection detection results |
| ts_msg | timestamp | NO | | Original message timestamp |
| ts_ingest | timestamp | NO | | Ingestion timestamp |
| deleted_at | timestamp | YES | | Soft delete |

**Foreign keys**: conversation (CASCADE), lkp_channel, lkp_direction, message (self-ref)

---

### attachment

File attachments linked to messages.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| attachment_id | uuid | PK | | |
| msg_id | uuid | NO | | FK to message (CASCADE) |
| filename | varchar(255) | NO | | Original filename |
| mime_type | varchar(128) | NO | | MIME type |
| size_bytes | integer | NO | | File size |
| content_hash | bytea | NO | | SHA256 dedup hash (unique) |
| s3_key | varchar(255) | YES | | Object storage key |
| enc_key_id | varchar(64) | YES | | Encryption key identifier |
| av_status | varchar(16) | NO | pending | Antivirus scan status |
| ocr_text | text | YES | | OCR extracted text |
| metadata | json | YES | | Additional metadata |
| vector_id | uuid | YES | | FK to message_vector |
| ts_ingest | timestamp | NO | | |
| deleted_at | timestamp | YES | | Soft delete |

---

### observed_ioc

IOC observation linking a message to an indicator.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| obs_id | uuid | PK | | Observation identifier |
| msg_id | uuid | NO | | FK to message (CASCADE) |
| indicator_id | uuid | NO | | FK to indicator |
| context_observation | json | NO | | Type, value, value_norm, category, score, etc. |
| ts_observed | timestamp | NO | | |

---

### indicator

Deduplicated threat indicator (IOC master record).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| indicator_id | uuid | PK | | |
| type | text | NO | | IOC type (email, url, domain, ipv4, iban, phone, ...) |
| value | text | NO | | Raw value |
| value_norm | text | NO | | Normalized value |
| first_seen | timestamp | NO | | |
| last_seen | timestamp | NO | | |
| last_enriched | timestamp | YES | | Last enrichment timestamp |
| occurrences | integer | NO | 1 | Number of sightings |
| enrichment | json | YES | | URLScan, VirusTotal data |
| score | json | YES | | Aggregated scoring |
| tlp | text | NO | AMBER | Traffic Light Protocol |
| created_at | timestamp | NO | | |
| updated_at | timestamp | NO | | |

---

## Lookup Tables

### lkp_scam_type

| Column | Type | Description |
|--------|------|-------------|
| scam_type_id | integer (PK) | |
| code | varchar(32) | Unique code (e.g., PHISH_CREDENTIALS) |
| label | varchar(128) | Display label |
| description | text | |
| misp_taxonomy | varchar(128) | MISP taxonomy mapping |
| attck_technique | varchar(32) | MITRE ATT&CK technique |
| active | boolean | |
| created_at, updated_at | timestamp | |

### lkp_channel

| Column | Type | Description |
|--------|------|-------------|
| channel_id | integer (PK) | |
| code | varchar(32) | Unique code (e.g., EMAIL) |
| label_en, label_fr | varchar(64) | Bilingual labels |

### lkp_direction

| Column | Type | Description |
|--------|------|-------------|
| dir_id | integer (PK) | |
| code | varchar(16) | in, out |
| label_en, label_fr | varchar(32) | Bilingual labels |

---

## Adaptive Scambaiting Tables

### persona

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| persona_id | integer (PK) | | serial | |
| persona_code | varchar(32) | NO | | Unique code (e.g., elderly_person) |
| persona_label | varchar(128) | NO | | Display label |
| persona_tone | varchar(256) | NO | | Tone description |
| system_prompt | text | NO | | LLM system prompt |
| created_by | varchar(16) | NO | | manual or auto |
| is_active | boolean | NO | | |
| created_at | timestamp | NO | | |

### scam_type_persona

Junction table (M:M between persona and lkp_scam_type).

| Column | Type | Description |
|--------|------|-------------|
| scam_type_id | integer | FK to lkp_scam_type (CASCADE) |
| persona_id | integer | FK to persona (CASCADE) |

**Primary key**: (scam_type_id, persona_id)

### persona_performance_stats

Epsilon-greedy bandit statistics per persona per scam type.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| persona_id | integer | PK | | FK to persona (CASCADE) |
| scam_type_id | integer | PK | | FK to lkp_scam_type (CASCADE) |
| sessions_count | integer | NO | 0 | Number of completed sessions |
| reward_sum | numeric(10,4) | NO | 0.0000 | Cumulative reward |
| reward_avg | numeric(5,4) | NO | 0.0000 | Average reward [0.0, 1.0] |
| last_updated | timestamp | NO | | |

**Primary key**: (persona_id, scam_type_id)
**CHECK constraints**: sessions_count >= 0, reward_avg BETWEEN 0.0 AND 1.0

---

## Campaign Radar Tables

### campaign

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| campaign_id | uuid (PK) | | | |
| first_seen | timestamptz | NO | | |
| status | varchar(20) | NO | | shadow, promoted, archived |
| actor_guess | text | YES | | LLM-generated actor profile |
| tlp | varchar(20) | NO | | TLP marking |
| severity | smallint | NO | | 1-5 scale |
| dsl_hash | varchar(64) | NO | | Hash of detection rule DSL |
| created_by | text | NO | | |
| notes | text | YES | | |
| profile_yaml | text | YES | | LLM-generated YAML profile |
| centroid_simhash | varchar(32) | YES | | Cluster centroid hash |
| created_at, updated_at | timestamptz | NO | | |

### campaign_rule

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| rule_id | uuid (PK) | | | |
| campaign_id | uuid | NO | | FK to campaign (CASCADE) |
| version | integer | NO | | Rule version |
| dsl | text | NO | | MailGuard DSL source |
| compiled_sql | jsonb | YES | | Compiled SQL query |
| ppv | numeric(5,4) | NO | 0.0 | Positive Predictive Value |
| hits_total | integer | NO | 0 | |
| hits_true_pos | integer | NO | 0 | |
| hits_false_pos | integer | NO | 0 | |
| lead_time_sec | integer | YES | | Detection lead time |
| promoted_at | timestamptz | YES | | Promotion timestamp |
| enabled | boolean | NO | true | |
| created_at, updated_at | timestamptz | NO | | |

### message_campaign

Junction table linking messages to campaigns.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| msg_id | uuid | PK | | FK to message (CASCADE) |
| campaign_id | uuid | PK | | FK to campaign (CASCADE) |
| confidence | numeric(5,4) | NO | | Match confidence [0, 1] |
| detected_at | timestamptz | NO | | |
| detected_by | varchar(50) | NO | | Detection method |
| features | jsonb | YES | | Matched features |
| is_true_positive | boolean | YES | | Manual verification |

### actor_profile

| Column | Type | Description |
|--------|------|-------------|
| actor_id | uuid (PK) | |
| style_dna | jsonb | Linguistic fingerprint |
| infra_dna | jsonb | Infrastructure fingerprint |
| campaigns | text | Associated campaign IDs |
| created_at, updated_at | timestamptz | |

---

## Auth & Infrastructure Tables

### app_users

| Column | Type | Description |
|--------|------|-------------|
| id | uuid (PK) | |
| tenant_id | uuid | Multi-tenant identifier |
| email | varchar(255) | Unique login email |
| password_hash | varchar(255) | Bcrypt hash |
| roles | json | e.g., ["ROLE_USER", "ROLE_ADMIN"] |

### refreshtoken

| Column | Type | Description |
|--------|------|-------------|
| token | varchar(128) (PK) | Token string |
| user_id | uuid | FK to app_users (CASCADE) |
| expiresat | timestamp | Expiration timestamp |
| valid | boolean | Single-use rotation |

### mail_account

| Column | Type | Description |
|--------|------|-------------|
| account_id | uuid (PK) | |
| owner_id | uuid | FK to app_users |
| protocol | varchar(32) | IMAP, SMTP |
| endpoint | varchar(255) | Server hostname |
| port | integer | Server port |
| secure | boolean | TLS enabled |
| login_hash | varchar(255) | Hashed login (Vault lookup key) |
| oauth_scopes | json | OAuth scopes if applicable |
| is_active | boolean | Default true |
| created_at, updated_at | timestamp | |

### conversation_channel

| Column | Type | Description |
|--------|------|-------------|
| conv_id | uuid | FK to conversation (CASCADE) |
| channel_id | integer | FK to lkp_channel |
| ts_first_channel | timestamp | When channel was first used |

**Primary key**: (conv_id, channel_id)

### message_vector

| Column | Type | Description |
|--------|------|-------------|
| vector_id | uuid (PK) | |
| embedding | json | Vector embedding |
| model_name | varchar(64) | Model used |
| dim | integer | Vector dimension |
| ts_created | timestamp | |

---

## Bandit Convergence Tracking

### bandit_convergence_log

Daily snapshot of persona convergence per scam type. Populated by `app:bandit:daily-report`.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer (PK) | | serial | |
| scam_type_code | varchar(32) | NO | | Scam type code |
| dominant_persona_code | varchar(32) | NO | | Persona with highest session share |
| dominant_pct | numeric(5,2) | NO | | Dominance percentage [0.00, 1.00] |
| sessions_count | integer | NO | | Total sessions for this scam type |
| converged | boolean | NO | false | True if dominant_pct >= 60% and sessions >= 10 |
| logged_at | timestamp | NO | | Snapshot timestamp |

**Indexes**: `idx_convergence_scam_type`, `idx_convergence_logged_at`

---

## Audit Trail

### audit_log

Structured audit trail for all security-relevant events.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer (PK) | | serial | |
| event_type | varchar(50) | NO | | Event type enum (AUTH_SUCCESS, RATE_LIMIT_EXCEEDED, etc.) |
| actor_type | varchar(32) | NO | | user, system, sender |
| actor_id | varchar(255) | NO | | Actor identifier |
| action | varchar(100) | NO | | Action performed |
| outcome | varchar(32) | NO | | success, failure, blocked |
| resource_type | varchar(100) | YES | | Resource type affected |
| resource_id | varchar(255) | YES | | Resource identifier |
| details | json | YES | | Additional context |
| ip_address | varchar(45) | YES | | Client IP |
| trace_id | varchar(64) | YES | | W3C trace correlation ID |
| created_at | timestamp | NO | | |

---

## LLM Cost Tracking

### llm_usage

Records each LLM API call for cost monitoring and budget enforcement.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| id | integer (PK) | | auto-increment | |
| conversation_id | varchar(36) | YES | | Conversation UUID (if applicable) |
| provider | varchar(32) | NO | | openai, anthropic, ollama, mock |
| model | varchar(64) | NO | | Model name (e.g., gpt-4o-mini) |
| purpose | varchar(50) | NO | | reply_generation, validation, classification, etc. |
| prompt_tokens | integer | NO | | Input token count (from API response) |
| completion_tokens | integer | NO | | Output token count (from API response) |
| estimated_cost_usd | numeric(10,6) | NO | | Estimated cost in USD |
| created_at | timestamp | NO | | |

**Indexes**: `idx_llm_usage_created_at`, `idx_llm_usage_provider`

---

## Views

### v_campaign_shadow_hits

Active shadow rules with 24h hit counts. Used by the Campaign Radar dashboard.

### v_campaign_promotion_candidates

Campaigns eligible for promotion (PPV >= 0.85, hits >= 5, lead time >= 3h).

### v_campaign_ppv_7d_window

7-day sliding window PPV calculation for drift monitoring.

---

## Preprod Statistics

| Table | Row Count |
|-------|-----------|
| conversation | 147 |
| message | 1,224 |
| observed_ioc | 442 |
| persona | 6 |
| lkp_scam_type | 13 |
