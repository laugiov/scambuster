# ScamBuster Metrics Catalog

This document catalogs all 31 metrics displayed in the platform, their exact formulas, data sources, provenance classification, and known limitations.

**Provenance legend:**
- :green_circle: **Verified** -- Computed from structured data with deterministic logic; independently auditable.
- :yellow_circle: **LLM-Derived** -- Computed or influenced by a Large Language Model call; not independently validated against ground truth.
- :blue_circle: **Heuristic** -- Deterministic formula chosen by the development team; reasonable but not empirically calibrated.

---

## 1. Impact Dashboard (8 metrics)

### 1.1 Total Scammer Time Wasted (hours)
- **Displayed in**: Impact Dashboard / Wasted Time
- **Formula**: `SUM(engagement_duration_sec) / 3600` across conversations with status IN (closed, open, abandoned) and deleted_at IS NULL
- **Data source**: `conversation.engagement_duration_sec` (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: Duration is computed as `ts_last - ts_first` of messages. Idle time between messages is included; actual scammer attention time may be lower.

### 1.2 Average Time Wasted per Conversation (hours)
- **Displayed in**: Impact Dashboard / Wasted Time
- **Formula**: `AVG(NULLIF(engagement_duration_sec, 0)) / 3600`
- **Data source**: `conversation.engagement_duration_sec` (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: Excludes zero-duration conversations. Single-message conversations are excluded from the average.

### 1.3 Total IOCs Collected
- **Displayed in**: Impact Dashboard / IOC Value
- **Formula**: `COUNT(*) FROM indicator WHERE type NOT IN (header types)`
- **Data source**: `indicator` table (PostgreSQL), excluding header types (message_id, subject, spf_result, dkim_result, dmarc_result, x_mailer, return_path)
- **Provenance**: :green_circle: Verified
- **Limitations**: Count of unique indicators, not observations. One indicator seen in 10 conversations counts as 1.

### 1.4 Novel IOCs (%)
- **Displayed in**: Impact Dashboard / IOC Value
- **Formula**: `novel_count / total_count * 100` where novel = `enrichment IS NULL OR enrichment = '{}' OR enrichment = 'null' OR virustotal.malicious < 3 OR virustotal key missing`
- **Data source**: `indicator.enrichment` JSONB column (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: "Novel" is defined as not yet known to VirusTotal with >= 3 malicious detections. The threshold of 3 is a team-chosen heuristic. IOCs that have never been enriched (enrichment IS NULL) are assumed novel -- they may simply not have been checked yet.

### 1.5 Financial IOCs Collected
- **Displayed in**: Impact Dashboard / IOC Value
- **Formula**: `COUNT(*) FROM indicator WHERE type IN (iban, bic, wallet_btc, wallet_eth, wallet_xmr, credit_card, phone)`
- **Data source**: `indicator` table (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: Phone numbers are classified as financial IOCs because they serve as clustering anchors. Not all phone IOCs are inherently financial.

### 1.6 Total LLM Cost (USD)
- **Displayed in**: Impact Dashboard / Cost Efficiency
- **Formula**: `SUM(estimated_cost_usd) FROM llm_usage`
- **Data source**: `llm_usage.estimated_cost_usd` (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Cost is estimated from token counts multiplied by per-model pricing constants embedded in CostEstimator. Actual billing may differ due to rounding, batching, or pricing changes.

### 1.7 Cost per IOC (USD)
- **Displayed in**: Impact Dashboard / Cost Efficiency
- **Formula**: `total_cost_usd / total_iocs` (excluding header types)
- **Data source**: `llm_usage` + `indicator` tables (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: Denominator excludes header IOCs. If IOC count is zero, returns 0. Cost includes all LLM usage (classification, enrichment, reply generation), not just IOC extraction.

### 1.8 Campaigns (total / promoted)
- **Displayed in**: Impact Dashboard / Campaigns
- **Formula**: `COUNT(*) FROM campaign` and `COUNT(*) FILTER (WHERE status = 'promoted')`
- **Data source**: `campaign` table (PostgreSQL)
- **Provenance**: :green_circle: Verified
- **Limitations**: Campaign promotion is a manual analyst action. Count reflects analyst decisions, not automated quality assessment.

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
- **Displayed in**: Impact Dashboard / IOC Value
- **Formula**: `COUNT(*) FROM observed_ioc WHERE confidence_score >= 0.9`
- **Data source**: `observed_ioc.confidence_score` (PostgreSQL)
- **Provenance**: :blue_circle: Heuristic
- **Limitations**: The 0.9 threshold is team-chosen. Confidence scores themselves are heuristic (see 2.1).

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
- **Data source**: `ioc_context.ioc_roles` JSONB (PostgreSQL), populated by `ContextualEnricher`
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
- **Formula**: LLM classifies conversation messages into one of 13 predefined scam types (PHISHING, INVOICE_FRAUD, CEO_FRAUD, ROMANCE, LOTTERY, CHARITY, ADVANCE_FEE_419, INVESTMENT, TECH_SUPPORT, EXTORTION, CRYPTO_SCAM, JOB_SCAM, UNKNOWN) or proposes a new type code in UPPER_SNAKE_CASE
- **Data source**: `conversation.scam_type_id` FK -> `lkp_scam_type.code` (PostgreSQL), populated by `ScamClassificationHandler` via `ScamClassifier` LLM call
- **Provenance**: :yellow_circle: LLM-Derived
- **LLM details**:
  - Model: gpt-4o-mini
  - Temperature: 0.3
  - Task: Classify the scam type from conversation messages
  - Confidence threshold: classification applied only if confidence >= 0.75
  - Circularity note: This metric is computed by an LLM analyzing conversation content. It is not independently validated against ground truth.
- **Limitations**: 13 predefined types may not cover all scam variants. LLM may propose new types that require manual review.

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
the analysis confidence is capped at 0.60. This means 36% of enrichment
records have confidence between 0.55-0.60 -- this is expected behavior,
not a defect.

### Urgency score distribution
The LLM enrichment tends to assign urgency scores in clusters around 0.75
(28% of records) and 0.55 (16%). This is a known limitation of LLM-based
scoring -- the model gravitates toward safe middle values. The prompt
calibration has been refined (spec 074) to encourage fuller range usage
for future enrichments.
