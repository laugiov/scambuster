# ScamBuster Metrics Catalog

This document catalogs all 33 metrics displayed in the platform, their exact formulas, data sources, provenance classification, and known limitations.

**Provenance legend:**
- :green_circle: **Verified** -- Computed from structured data with deterministic logic; independently auditable.
- :yellow_circle: **LLM-Derived** -- Computed or influenced by a Large Language Model call; not independently validated against ground truth.
- :blue_circle: **Heuristic** -- Deterministic formula chosen by the development team; reasonable but not empirically calibrated.

---

## 1. Impact Dashboard (10 metrics)

The Impact dashboard is driven by two orthogonal page-level filters: a period
selector (7d / 30d / 90d / All) and a scam-type selector. Backend source of
truth: `ImpactHandler` (tiles + charts), `ScammerEngagementCalculator`
(engagement card), `ClusterQueryService::getStats` (actor deduplication tile).

### 1.1 Scammer Replies Elicited
- **Displayed in**: Impact Dashboard / KPI tile 1 (headline count + "across N conversations" subtitle)
- **Formula**: `COUNT(m.msg_id)` of inbound messages joined to their conversation, where `m.direction = <dir_id of 'in'>` AND `m.deleted_at IS NULL` AND `c.deleted_at IS NULL` AND `c.status IN ('closed','open','abandoned')` AND `c.turns_count >= 2`. The subtitle "across N conversations" is `COUNT(*)` of those same qualified conversations. On 7d/30d/90d the query adds `c.ts_last >= NOW() - INTERVAL 'N days'` and a delta vs the previous equal-length window is shown; on All no delta applies (prev = null).
- **Data source**: `message` (direction, deleted_at) JOIN `conversation` (status, turns_count, ts_last, deleted_at); inbound `dir_id` resolved from `lkp_direction WHERE code='in'` (PostgreSQL)
- **Provenance**: :green_circle: Verified -- direct deterministic count, zero time inference
- **Limitations**: Only "qualified" conversations (`turns_count >= 2`, i.e. the scammer replied at least once) are counted; single-turn first-contact conversations are excluded. Because outbound `external_message_id` is not persisted, a scammer reply sometimes threads into a NEW conversation rather than the original (fragmentation bias; see 1.7), which inflates the conversation count -- but the inbound-message tally itself stays faithful. Replaced the earlier time-based "Engagement Time" / "Criminal Time Wasted" headline, which was structurally inflated by overnight/weekend gaps.

### 1.2 Total IOCs Collected
- **Displayed in**: Impact Dashboard / KPI tile 2 -- shown when the page period = All
- **Formula**: `COUNT(*) FROM indicator WHERE type NOT IN (header types)` (a `created_at >= threshold` clause is added when a period is set; at All there is no threshold)
- **Data source**: `indicator` table (PostgreSQL), excluding header types (message_id, subject, spf_result, dkim_result, dmarc_result, x_mailer, return_path)
- **Provenance**: :green_circle: Verified
- **Limitations**: Counts distinct indicators, not observations -- one indicator seen in 10 conversations counts once. Shares its tile slot with Fresh IOCs (1.3): the cumulative face renders on All, the rolling face on 7d/30d/90d.

### 1.3 Fresh IOCs (rolling window)
- **Displayed in**: Impact Dashboard / KPI tile 2 -- shown when the page period = 7d/30d/90d
- **Formula**: `COUNT(*) FROM indicator WHERE type NOT IN (header types) AND first_seen >= NOW() - INTERVAL 'N days'`, N = selected period. Delta vs previous window: prev = same count with `first_seen` in `[NOW()-2N days, NOW()-N days)`; `delta_pct = (fresh - prev)/prev * 100`, null when prev = 0.
- **Data source**: `indicator.first_seen`, `indicator.type` (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: "Fresh" = first observed within the rolling window (by `first_seen`). Replaced the earlier "Novel IOCs %" tile, which conflated "not yet in VirusTotal" with "novel" and overclaimed. When period = All this face is hidden and the tile falls back to Total IOCs (1.2). (The VirusTotal-based novel heuristic survives only inside the secondary chart 1.10.)

### 1.4 Cost per IOC (USD)
- **Displayed in**: Impact Dashboard / KPI tile 3 (value + "Total: $X" subtitle)
- **Formula**: `round(total_cost_usd / total_iocs, 4)`; returns 0 when total_iocs = 0. `total_iocs` excludes header types. An optional delta from `trends.cost_per_ioc_delta_pct` is shown on 7d/30d/90d.
- **Data source**: `llm_usage.estimated_cost_usd` (SUM) + `indicator` count (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic -- denominator is deterministic, but the numerator (cost) is estimated from token pricing constants (see 1.5)
- **Limitations**: Denominator excludes header IOCs. Cost is ALL LLM usage (classification, enrichment, reply generation, contextual enrichment), not just IOC-extraction cost -- so this is a program-wide cost-efficiency ratio, not a marginal cost per IOC.

### 1.5 Total LLM Cost (USD)
- **Displayed in**: Impact Dashboard / subtitle of the Cost per IOC tile ("Total: $X")
- **Formula**: `SUM(estimated_cost_usd) FROM llm_usage` (+ `created_at >= threshold` when a period is set). Each row's `estimated_cost_usd = (prompt_tokens/1000)*input_price + (completion_tokens/1000)*output_price`, per-model prices in `CostEstimator::PRICING`; ollama/mock providers = $0; unknown models fall back to gpt-4o pricing.
- **Data source**: `llm_usage.estimated_cost_usd`, written by `CostEstimator` (PHP); pricing table last verified 2026-03-22
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Estimated from token counts x a static per-model price table. Actual vendor billing may differ (pricing changes, batching, rounding). Token counts may themselves be approximated (~4 chars/token) when the provider API does not return exact usage.

### 1.6 Actor Deduplication
- **Displayed in**: Impact Dashboard / KPI tile 4 (rendered only when cluster stats has > 0 conversations)
- **Formula**: value = `total_conversations -> (total_clusters + singleton_conversations)`. `total_conversations` = COUNT of non-deleted conversations (period-filtered). `clustered_conversations` = COUNT of conversations linked to a non-merged cluster. `singleton_conversations = total_conversations - clustered_conversations`. `total_clusters` = COUNT of non-merged clusters. Subtitle % = `taxii_noise_reduction_pct = round((1 - (total_clusters + singletons)/total_conversations) * 100, 1)`.
- **Data source**: `conversation`, `threat_actor_cluster` (status), `threat_actor_cluster_conversation` (PostgreSQL) via `ClusterQueryService::getStats`
- **Provenance**: :green_circle: Verified arithmetic over :blue_circle: heuristic clustering (upstream Union-Find on shared HIGH-severity anchor IOCs)
- **Limitations**: The "deduplicated actor" count deliberately adds singleton conversations (each unclustered conversation is still its own actor), so the reduction reflects only conversations that shared a HIGH-value anchor IOC. Cluster-level counts are unfiltered by period (a cluster is a long-lived entity); only conversation counts respect the period filter. Two distinct actors sharing a mule account merge into one cluster. Matches the identical formula on the Clusters page.

### 1.7 Scammer Engagement (real rate) + per-scam-type breakdown
- **Displayed in**: Impact Dashboard / Scammer Engagement card (global rate + "responded/observable senders"), plus an inline "Breakdown by Scam Type" bar list when no page-level scam-type filter is set
- **Formula**: Computed per real sender (email counterpart), not per conversation. `rate_pct = round(100 * responded / observable, 1)`.
  - counterpart = the `to` email for outbound messages, the `from` email for inbound (parsed from `message.headers`).
  - Excludes technical-noise conversations (first-inbound subject/sender ILIKE any configured bounce / DMARC / postmaster pattern) and honeypot counterpart addresses.
  - `observable` = a counterpart whose most recent outbound (`last_out`) is older than `censoring_hours` (default 96) -- enough time has passed that a non-reply is a genuine refusal, not still in-flight.
  - `responded` = a counterpart that sent at least one inbound message after its first outbound (`first_out`).
  - `GROUP BY ROLLUP(scam_type)`: the NULL group is the global rate, the remaining groups are the per-scam-type breakdown (sorted by observable desc, top 8 shown). Respects the page-level period + scam-type filters.
- **Data source**: `message.headers` (from/to), `conversation`, `lkp_scam_type`, `lkp_direction`; noise patterns from `ScammerEngagementNoiseConfig`; honeypot addresses from env; via `ScammerEngagementCalculator` (PostgreSQL, single CTE)
- **Provenance**: :blue_circle: Heuristic -- the counts are deterministic, but the bias corrections (96h censoring window, noise subject/sender pattern lists, honeypot exclusion) are team-chosen calibrations
- **Limitations**: Corrects three measured biases (technical noise, right-censoring, conversation fragmentation), but the censoring threshold and noise patterns are heuristic and were tuned on a one-month production sample. Per-scam-type rows with observable < 5 are dimmed as low-sample. `headers->>'to'` is not indexed (acceptable up to ~5k messages).

### 1.8 Hours Wasted per Week
- **Displayed in**: Impact Dashboard / "Hours Wasted per Week" bar chart
- **Formula**: `SUM(engagement_duration_sec)/3600` grouped by `DATE_TRUNC('week', ts_last)`, over qualified conversations (`status IN ('closed','open','abandoned')`, `deleted_at IS NULL`, `turns_count >= 2`). Window = the page period threshold, or the last 12 weeks when period = All.
- **Data source**: `conversation.engagement_duration_sec`, `conversation.ts_last` (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic (wall-clock)
- **Limitations**: `engagement_duration_sec` is wall-clock (`ts_last - ts_first` of the conversation), so idle/overnight/weekend gaps count as "wasted". This is exactly why the headline tile moved from summed time to a direct reply count (1.1); the weekly chart keeps the wall-clock methodology as a coarse activity-volume signal, not a literal attention measure.

### 1.9 IOCs by Type
- **Displayed in**: Impact Dashboard / "IOCs by Type" donut
- **Formula**: `SELECT type, COUNT(*) FROM indicator WHERE type NOT IN (header types) GROUP BY type ORDER BY count DESC LIMIT 10` (+ period / scam-type filters). The frontend keeps the top 8 slices and buckets the remainder into an "Other" slice; slices under 2% hide their label.
- **Data source**: `indicator.type` (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: Distinct indicators, not observations. Backend returns the top 10; the donut collapses ranks 9+ into "Other", so the smallest long-tail types are not individually visible.

### 1.10 IOCs per Day (novel vs known)
- **Displayed in**: Impact Dashboard / "IOCs per Day (novel vs known)" stacked area chart -- rendered only when the IOC-uniqueness daily trend returns rows
- **Formula**: per calendar day (`DATE(created_at)`), `total = COUNT(*)` and `novel = COUNT(*) FILTER (...)` over non-header indicators; the chart plots `novel` and `known = total - novel`. `novel = enrichment IS NULL OR enrichment = '{}' OR enrichment = 'null' OR virustotal.malicious < 3 OR virustotal key missing`. Window = page period threshold, or last 30 days when period = All.
- **Data source**: `indicator.created_at`, `indicator.enrichment` JSONB (PostgreSQL) via the `/impact/ioc-uniqueness` endpoint (`ImpactHandler::getIocUniqueness`)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: "Novel" means "not yet known to VirusTotal with >= 3 malicious detections"; the threshold of 3 is team-chosen, and never-enriched IOCs (enrichment IS NULL) are assumed novel even though they may simply not have been checked yet. This is the same heuristic the retired "Novel IOCs %" tile used -- it now survives only inside this secondary chart, which is hidden entirely when no daily-trend rows are returned.

---

## 2. IOC Scoring (6 metrics)

### 2.1 Extraction Confidence (base)
- **Displayed in**: IOC Explorer / Confidence column
- **Formula**: `1 - (1 - base)^occurrences` where base depends on extraction method:
  - `header` = 0.99
  - `regex` = 0.95
  - `llm` = 0.75
  - unknown method = 0.80 (default)
- **Data source**: `observed_ioc.confidence_score`, computed by `IocConfidenceCalculator` (PHP)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Base confidence values are team-chosen constants, not empirically calibrated against precision/recall benchmarks. The multi-observation boost formula assumes independence between observations, which may not hold when the same extraction pipeline runs multiple times on forwarded/quoted content.

### 2.2 Temporal Decay Factor
- **Displayed in**: IOC Explorer / Effective Score
- **Formula**: `2^(-age_days / half_life_days)` where half-lives by type:
  - URL: 14 days
  - IPv4/IPv6: 7 days
  - Domain: 30 days
  - Email/whois_email: 60 days
  - Phone/filename: 90 days
  - IBAN/BIC/wallet_btc/wallet_eth/wallet_xmr/bank_account/credit_card: 180 days
  - SHA256/SHA1/MD5: 365 days
  - Subject/message_id: 14 days
  - Registrar: 60 days
  - Default (unlisted types): 30 days
- **Data source**: Computed at query time by `IocConfidenceCalculator::computeDecayFactor()` from `observed_ioc.ts_observed` (PHP)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Half-life values are team-chosen constants loosely based on threat intelligence community conventions. Not empirically validated against real IOC lifecycle data.

### 2.3 Effective Score
- **Displayed in**: IOC Explorer / Effective Score column
- **Formula**: `confidence * decay_factor` = `confidence * 2^(-age_days / half_life_days)`, rounded to 4 decimal places
- **Data source**: Computed by `IocConfidenceCalculator::computeEffectiveScore()` (PHP)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Multiplicative combination of two heuristic values. A high-confidence old IOC and a low-confidence fresh IOC can produce the same effective score, which may not reflect operational reality.

### 2.4 Severity Level
- **Displayed in**: IOC Explorer / Severity badge
- **Formula**:
  - Financial types (iban, bank_account, credit_card, wallet_btc, wallet_eth, wallet_xmr, phone) -> always **HIGH**
  - Network types (bic, url, domain, email, whois_email, ipv4, ipv6, sha256, sha1, md5, filename, registrar) -> **MEDIUM**, upgraded to **HIGH** if max(vtScore, urlscanScore) > 0
  - All other types (subject, message_id, dmarc_result, etc.) -> **LOW**
- **Data source**: `IocConfidenceCalculator::computeSeverity()` using IOC type + enrichment scores (PHP)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Severity tiers are team-defined. BIC is classified as MEDIUM (identifies a bank, not a specific actor) despite being a financial identifier.

### 2.5 VirusTotal Risk Score
- **Displayed in**: IOC Explorer / Risk Score
- **Formula**: VT malicious > 0 -> +70 pts; VT suspicious > 0 -> +40 pts; URLscan malicious -> +60 pts; URLscan suspicious -> +25 pts. Capped at 100. Risk levels: HIGH >= 70, MEDIUM 40-69, LOW < 40.
- **Data source**: `indicator.enrichment` JSONB -> `virustotal.malicious`, `virustotal.suspicious`, `urlscan.verdict` (PostgreSQL)
- **Provenance**: :green_circle: Verified (external API data) + :blue_circle: Heuristic (point thresholds)
- **Limitations**: Point values (70, 40, 60, 25) are team-chosen. VirusTotal/URLscan data may be stale if enrichment ran days ago.

### 2.6 High-Confidence IOC Count
- **Displayed in**: Computed in the Impact summary payload (`ioc_value.high_confidence` from `ImpactHandler`); after the Impact dashboard rework it is no longer surfaced on any tile. Documented here because the query still runs and ships in the API response.
- **Formula**: `COUNT(*) FROM observed_ioc WHERE confidence_score >= 0.9` (+ `ts_observed >= threshold` and scam-type filter when set)
- **Data source**: `observed_ioc.confidence_score` (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: The 0.9 threshold is team-chosen. Confidence scores themselves are heuristic (see 2.1). Not currently rendered by any frontend page.

---

## 3. IOC Context / Behavioral Signals (5 metrics)

### 3.1 Stimulus Type
- **Displayed in**: IOC Explorer / Context tab
- **Formula**: LLM classifies the conversational trigger that caused IOC revelation into one of: URGENCY_PRESSURE, TRUST_BUILDING, DIRECT_REQUEST, DOCUMENT_REQUEST, PAYMENT_INITIATION, PASSIVE, UNKNOWN
- **Data source**: `ioc_context.stimulus_type` (PostgreSQL), populated by `ContextualEnricher` via LLM call
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Analyze a 3-message conversation window around the IOC and classify the stimulus
  - Confidence capping: 1 message -> max 0.60, 2 messages -> max 0.80, 3 messages -> no cap
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth.
- **Limitations**: Enum validation falls back to UNKNOWN for unrecognized values. No inter-annotator agreement measured.

### 3.2 Scammer Urgency Score
- **Displayed in**: IOC Explorer / Context tab
- **Formula**: LLM outputs a float, clamped to [0.0, 1.0] by `ContextualEnrichmentResult::fromLlmResponse()`
- **Data source**: `ioc_context.urgency_score` (PostgreSQL), populated by `ContextualEnricher`
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Rate the scammer's urgency/pressure level in the conversation window
  - Values outside [0.0, 1.0] are clamped (e.g., -0.5 -> 0.0, 1.5 -> 1.0)
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth.
- **Limitations**: Subjective assessment by LLM. No calibration against human raters.

### 3.3 IOC Semantic Role
- **Displayed in**: IOC Explorer / Context tab
- **Formula**: LLM assigns each IOC type a semantic role from: PAYMENT_DESTINATION, PAYMENT_REDIRECT_URL, PHISHING_CREDENTIAL_URL, MALWARE_DOWNLOAD_URL, CONTACT_CHANNEL, IDENTITY_DOCUMENT, VERIFICATION_CODE_URL, INFRASTRUCTURE_DOMAIN, MONEY_MULE_ACCOUNT, UNKNOWN
- **Data source**: `ioc_context.semantic_role` varchar(30) (PostgreSQL), populated by `ContextualEnricher`
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: For each IOC found in the message, determine its semantic role in the scam narrative
  - Invalid roles fall back to UNKNOWN; missing IOC types are filled with UNKNOWN
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth.
- **Limitations**: Role assignment depends on conversation context quality. Short conversations yield less reliable roles.

### 3.4 Enrichment Confidence
- **Displayed in**: IOC Explorer / Context tab
- **Formula**: LLM self-reported confidence, clamped to [0.0, 1.0], then capped by message window size:
  - 1 message available -> max 0.60
  - 2 messages available -> max 0.80
  - 3 messages available -> no additional cap (max 1.0)
- **Data source**: `ioc_context.enrichment_confidence` (PostgreSQL)
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Self-assess confidence in the enrichment analysis
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth. LLM self-reported confidence is known to be poorly calibrated.
- **Limitations**: LLM confidence self-reports are notoriously unreliable. The window-size cap is a team-chosen heuristic to partially mitigate this.

### 3.5 Language Switch / Hesitation Detection
- **Displayed in**: IOC Explorer / Context tab
- **Formula**: LLM outputs boolean flags, cast via `(bool)` with default `false`
- **Data source**: `ioc_context.language_switch`, `ioc_context.hesitation_detected` (PostgreSQL)
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Detect if scammer switched language mid-conversation or showed hesitation
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth.
- **Limitations**: Binary classification by LLM. No precision/recall measured. Missing values default to false (no detection).

---

## 4. Clustering (4 metrics)

### 4.1 Cluster Count
- **Displayed in**: Clusters page / summary
- **Formula**: `COUNT(*) FROM threat_actor_cluster`
- **Data source**: `threat_actor_cluster` table (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: Clusters are formed by Union-Find on shared HIGH-severity IOCs. Two distinct threat actors sharing a mule account will be merged into one cluster.

### 4.2 Cluster Size (conversations)
- **Displayed in**: Clusters page / cluster detail
- **Formula**: `COUNT(*) FROM threat_actor_cluster_conversation WHERE cluster_id = ?`
- **Data source**: `threat_actor_cluster_conversation` table (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: A conversation can only belong to one cluster. If a conversation contains IOCs linking to multiple clusters, Union-Find merges those clusters.

### 4.3 Anchor IOC Types
- **Displayed in**: Cluster Detail / IOC breakdown
- **Formula**: IOC types where `IocConfidenceCalculator::computeSeverity(type, vtScore=0, urlscanScore=0) === 'HIGH'`. Currently: iban, bank_account, credit_card, wallet_btc, wallet_eth, wallet_xmr, phone.
- **Data source**: `IocConfidenceCalculator::HIGH_VALUE_TYPES` constant, resolved dynamically by `IocClusteringService` (PHP)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Anchor types are derived from severity classification. Well-known ETH contract addresses (USDT, USDC, WETH, DAI, SHIB) are excluded to prevent false merges.

### 4.4 Behavioral Profile (Cluster Detail)
- **Displayed in**: Cluster Detail / Threat Profile
- **Formula**: Aggregations from `ioc_context` table: MODE of stimulus_type WITHIN GROUP, COUNT DISTINCT of IOC roles, behavioral signal frequencies
- **Data source**: `ioc_context` table via `ClusterQueryService::computeBehavioralProfile()` (PostgreSQL)
- **Provenance**: :yellow_circle: LLM-Derived (upstream: ioc_context fields are LLM-populated)
- **Limitations**: Profile quality depends on upstream LLM enrichment. Clusters with few enriched IOCs produce sparse profiles.

---

## 5. Scam Classification (3 metrics)

### 5.1 Scam Type Code
- **Displayed in**: Conversation list / scam type badge
- **Formula**: LLM classifies conversation messages into one of 14 predefined scam types (PHISHING, PHISH_CREDENTIALS, PHISH_MALWARE, INVOICE_FRAUD, CEO_FRAUD, ROMANCE, LOTTERY, CHARITY, ADVANCE_FEE_419, INVESTMENT, TECH_SUPPORT, JOB_OFFER, COLD_SERVICE_SPAM, UNKNOWN). The set is closed — the classifier never invents a code; anything unmatched falls to UNKNOWN.
- **Data source**: `conversation.scam_type_id` FK -> `lkp_scam_type.code` (PostgreSQL), populated by `ScamClassificationHandler` via `ScamClassifier` LLM call
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Classify the scam type from conversation messages (known types are presented with a short description each so the model can distinguish e.g. COLD_SERVICE_SPAM from INVOICE_FRAUD)
  - Confidence threshold: classification applied only if confidence >= 0.55
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth.
- **Limitations**: The closed set may not cover every scam variant; genuinely unmatched conversations remain UNKNOWN. New categories are added deliberately (migration + seed), not invented at runtime. `COLD_SERVICE_SPAM` covers unsolicited cold service outreach (SEO / web-dev / marketing) and the fake-vendor advance-fee-for-services pattern.

### 5.2 Classification Confidence
- **Displayed in**: Conversation detail / classification section
- **Formula**: LLM self-reported float confidence. Applied only if >= 0.75 threshold.
- **Data source**: `ClassificationResult.confidence` (PHP value object)
- **Provenance**: :yellow_circle: LLM-Derived
- **Limitations**: Same LLM confidence calibration caveats as 3.4. The 0.75 threshold is team-chosen.

### 5.3 Detected Language
- **Displayed in**: Conversation detail
- **Formula**: LLM detects the primary language of the conversation. Defaults to 'en' if not provided.
- **Data source**: `ClassificationResult.detectedLanguage` (PHP value object)
- **Provenance**: :yellow_circle: LLM-Derived
- **Limitations**: Single-language detection; does not handle multilingual conversations well.

---

## 6. Reply Quality (2 metrics)

### 6.1 Multi-Criteria Validation Score
- **Displayed in**: Pipeline monitoring / reply validation
- **Formula**: LLM judge scores reply on 3 dimensions (naturalness, persona_fit, ti_value) each 1-5. Rejection rules:
  - `security_pass = false` -> reject
  - `naturalness < 2` -> reject
  - `average(naturalness, persona_fit, ti_value) < 2.5` -> reject
- **Data source**: `ReplyValidator` LLM call result, stored in `message.headers` JSON trace (PostgreSQL)
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Act as a quality judge for generated scambaiting replies
  - Circularity note: This metric is computed by an LLM analyzing LLM-generated content. It is not independently validated against ground truth. The same model family generates and judges replies.
- **Limitations**: LLM-as-judge is known to have self-preference bias. The 2.5 average threshold is team-chosen.

### 6.2 Security Pass Gate
- **Displayed in**: Pipeline monitoring / reply validation
- **Formula**: Boolean output from ReplyValidator LLM call. Checks for operational security leaks (honeypot disclosure, system prompt leakage, real identity exposure).
- **Data source**: `ReplyValidator` result -> `security_pass` field
- **Provenance**: :yellow_circle: LLM-Derived
- **Limitations**: Binary gate. False negatives (missed leaks) are the primary risk. PolicyGuard provides a separate rule-based defense-in-depth layer.

---

## 7. Persona Optimization (3 metrics)

### 7.1 Conversation Reward
- **Displayed in**: Persona Performance / reward column
- **Formula**: `0.40 * norm(duration) + 0.25 * norm(iocs_total) + 0.25 * norm(iocs_sensibles) + 0.10 * completion_signal`
  - `norm(x) = clamp((x - min) / (max - min), 0, 1)` (min-max scaling)
  - Normalization constants: max_duration = 86400s (24h), max_iocs_total = 50, max_iocs_sensibles = 10
  - `completion_signal` = 1.0 if conversation ended normally, 0.0 otherwise
  - Result clamped to [0.0, 1.0]
- **Data source**: `ConversationMetrics` value object, computed by `ConversationMetricsCollector` from `conversation` + `observed_ioc` tables (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Weights (0.40/0.25/0.25/0.10) are team-chosen, not empirically optimized. Normalization constants are fixed ceilings; conversations exceeding them are capped at 1.0. Duration includes idle time.

### 7.2 Persona Selection Strategy
- **Displayed in**: Persona Performance / strategy indicator
- **Formula**: Contextual epsilon-greedy with UCB1 exploration bonus:
  - All personas in cold start (< 3 sessions) -> uniform random (`cold_start`)
  - With probability epsilon -> random selection (`exploration`)
  - With probability 1-epsilon -> best UCB1-adjusted score (`exploitation`)
  - Epsilon = 0.20 (default), reduced to 0.05 when converged
  - Convergence: best persona has >= 60% of total sessions AND >= 10 sessions
  - UCB1 bonus: `C * sqrt(ln(total_sessions) / persona_sessions)` with C = 0.5
- **Data source**: `PersonaOptimizer` algorithm using `persona_performance_stats` table (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Epsilon, cold start threshold, convergence threshold, and UCB1 constant C are all team-chosen hyperparameters. No A/B testing framework to validate optimality.

### 7.3 Persona Average Reward
- **Displayed in**: Persona Performance / reward_avg column
- **Formula**: `AVG(reward)` across all completed conversations for a (persona, scam_type) pair
- **Data source**: `persona_performance_stats.reward_avg` (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic (upstream: reward itself is heuristic)
- **Limitations**: Small sample sizes (< 10 sessions) produce unreliable averages. No confidence intervals displayed.

---

## Known Limitations

### Cross-conversation IOC deduplication
When the same IOC value appears in multiple conversations (e.g., same scammer
sending to multiple honeypot addresses), the indicator is stored once in the
`indicator` table. The `observed_ioc` record links each observation to its
specific message. However, if deduplication triggers before the observation
is recorded, some messages may not have an `observed_ioc` link despite
containing the IOC value. This is a known design trade-off for storage
efficiency.

### Enrichment confidence ceiling
The contextual enrichment uses a 3-message window (previous message, stimulus,
and revelation message). When only 1 message is available (first contact),
the analysis confidence is capped at 0.60. This ceiling is expected behavior,
not a defect.

### Urgency score distribution
The LLM enrichment tends to assign urgency scores that gravitate toward safe
middle values -- a known limitation of LLM-based scoring. The prompt
calibration has been refined to encourage fuller range usage
for future enrichments.
