# Changelog - ScamBuster

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.5.0] - 2026-04-06

### Added

#### STIX Threat Actor Export (Feature 044)
- **STIX 2.1 threat-actor objects** in campaign exports and TAXII feeds
- `ThreatActorStixBuilder`: builds threat-actor with behavioral fingerprint (style_dna, infra_dna), MITRE ATT&CK techniques, sophistication scoring, goals mapping
- `x_scambuster_actor` STIX extension with campaign metrics and actor DNA
- **attack-pattern** objects linked to MITRE ATT&CK techniques (TLP:WHITE)
- **3 new relationship types**: campaign→attributed-to→threat-actor, threat-actor→uses→attack-pattern, indicator→indicates→threat-actor
- Deterministic UUIDs for OpenCTI/MISP deduplication
- Backward compatible: `?include_threat_actor=false` on campaign export

### Changed
- Completed MITRE ATT&CK mapping for 6 scam types (INVOICE_FRAUD→T1534, ROMANCE/LOTTERY/CHARITY/ADVANCE_FEE_419→T1566.001, INVESTMENT→T1566.002)
- Fixed `ActorProfileGenerator` column reference (message_id→msg_id)

---

## [2.4.0] - 2026-04-06

### Added

#### Rich Contextual IOC Bundle (Feature 043)
- **Structural IOC context** computed at extraction time: revelation turn, scam type, persona, extraction method, engagement duration, co-revealed IOC types, campaign link
- **LLM semantic enrichment** via gpt-4o-mini (1 call per message): semantic role (PAYMENT_DESTINATION, PHISHING_CREDENTIAL_URL, CONTACT_CHANNEL, etc.), stimulus type (PASSIVE, URGENCY_PRESSURE, TRUST_BUILDING, etc.), scammer urgency score, hesitation/language switch detection, PII-free context excerpt
- **Confidence calibration**: analysis confidence capped based on available context window (max 0.60 for first-contact with no conversational history)
- **PII anonymization**: MessageAnonymizer strips emails, IBANs, phones, crypto wallets before LLM analysis; output validated post-LLM
- **IOC Context API**: `GET /api/v1/iocs/{indicatorId}/context` returns structural + semantic context per observation
- **Context tab** in IOC Detail (frontend): revelation context with turn indicator, semantic role with color coding, stimulus type, behavioral signals (urgency bar, hesitation, language switch), context excerpt, co-revealed IOCs
- **IOC Explorer enhancements**: sparkle indicator on IOCs with context, "Has context" filter checkbox, `has_context` boolean in list response
- **STIX export**: `x_scambuster_context` extension on indicators with scam type, persona, turn ratio, semantic role, stimulus type, urgency score
- **TAXII feed**: context extension included by default on all IOC objects
- **Batch command**: `app:ioc:compute-context --with-llm --budget-usd=1.00` for backfill with budget cap
- **Scheduler**: context computation with LLM enrichment runs every 6 hours
- **i18n**: full English and French translations for all context UI labels

### Changed
- `IocUpsertService` now triggers structural context + LLM enrichment at n8n IOC upsert time
- `IocHandler::extractIocsFromMessage()` runs 1 LLM enrichment call per message (not per IOC)
- `extraction_method` normalized: n8n pipeline correctly labeled as `llm` instead of generic `extraction`
- n8n workflow `WF-EXTRACT-AND-ENRICH-IOC` timeout increased from 30s to 120s

---

## [2.3.0] - 2026-04-04

### Added

#### TAXII 2.1 Server (Feature 040 -- MT-7)
- **4 TAXII 2.1 endpoints** for automated CTI feed consumption by OpenCTI, MISP, TheHive, SIEM:
  - `GET /api/v1/taxii2/` -- Server discovery
  - `GET /api/v1/taxii2/api/` -- API root
  - `GET /api/v1/taxii2/api/collections/` -- 2 collections (IOCs + Campaigns)
  - `GET /api/v1/taxii2/api/collections/{id}/objects/` -- STIX 2.1 objects with `added_after` delta sync
- JWT authentication + `#[IsGranted('ioc:read')]` on all TAXII endpoints
- Content-Type `application/taxii+json;version=2.1`, pagination headers (`X-TAXII-Date-Added-First/Last`)
- STIX pattern mapping for 8+ IOC types (domain, URL, IPv4/6, email, SHA256, MD5, SHA1)
- 9 new integration tests
- Full documentation: `docs/16_taxii_server.md` with OpenCTI/MISP/SIEM integration guides

#### MFA TOTP (Feature 040 -- MT-11)
- **Two-Factor Authentication** via TOTP (Time-based One-Time Password) for admin accounts
- `POST /api/v1/2fa/setup` -- Generate secret + QR URI for authenticator app
- `POST /api/v1/2fa/verify` -- Validate 6-digit code from authenticator
- `POST /api/v1/auth/2fa/login` -- Full login with email + password + TOTP code
- LoginController returns `requires_2fa: true` when TOTP is enabled (instead of tokens)
- **Not enabled by default** -- demo users unaffected, opt-in activation only
- Backward compatible: users without TOTP log in normally
- 6 new integration tests

#### Fine-Grained RBAC (Feature 039 -- CT-1)
- **`#[IsGranted]` annotations** on 37 controllers with 12 Permission enum values
- Per-method annotations on multi-action controllers (ConversationController, MessageController, ReplyController, AttachmentController)
- PermissionVoter updated to handle both User entity and InMemoryUser (test environment)
- UserFixtures grants all permissions to standard user for n8n compatibility

#### Infrastructure Hardening (Feature 039 -- CT-5/6/9/10/11)
- **Dependabot** enabled (monthly, 5 PRs max, direct deps only)
- **n8n image pinned** to 1.114.3 (was `latest`)
- **Trivy + CycloneDX SBOM** in CI pipeline (new `container-security` job)
- **GOVERNANCE.md** + **MAINTAINERS.md** + **.github/FUNDING.yml** created

### Changed

#### IocHandler Decomposition (Feature 039 -- CT-0)
- **IocHandler.php** (1277 LOC) decomposed into 4 services:
  - `IocQueryService` (445 LOC): list, detail, co-occurrence, conversation IOCs
  - `IocUpsertService` (320 LOC): upsert, dedup, header extraction
  - `IocExtractorOrchestrator` (274 LOC): regex/LLM/hybrid extraction + derivation
  - `IocEnrichmentService` (171 LOC): risk scoring, enrichment updates
- IocHandler (165 LOC) is now a thin facade delegating to the 4 services

#### IngestHandler Decomposition (Feature 040 -- MT-1)
- **IngestHandler.php** (891 LOC) decomposed into 3 services:
  - `EmailParsingService` (200 LOC): RFC822 parsing, HTML-to-text, language detection
  - `ThreadResolverService` (337 LOC): threading, conversation create/reopen
  - `IngestPostProcessor` (285 LOC): IOC extraction, classification, risk scoring, injection detection
- IngestHandler (341 LOC) is now an orchestrator

#### ReplyHandler Decomposition (Feature 040 -- MT-2)
- **ReplyHandler.php** (941 LOC) decomposed into 3 services:
  - `ReplyContextService` (276 LOC): conversation context, persona assignment
  - `ReplyCadenceService` (191 LOC): kill switch, cadence, rate limits, safelist
  - `ReplyCompositionService` (337 LOC): compose headers, mark sent, send email
- ReplyHandler (287 LOC) is now an orchestrator

#### DDD Architecture (Feature 039 -- CT-7/CT-8)
- **EntityManager removed** from all 6 controllers that had it (dedicated handlers created)
- **5 repository interfaces** added in Domain/ (Conversation, Message, ObservedIoc, Persona, Campaign) with Doctrine implementations

### Fixed

#### Security (Feature 039 -- CT-2)
- **Login rate limiting** now Redis-backed via `RateLimiterFactory` (was inoperant `static $attempts`)
- `Makefile` stan target uses `--memory-limit=512M` (matches CI)

#### Compliance (Feature 039 -- CT-3)
- **GDPR retention** aligned with constitution: soft-delete at 6 months (was 2 years), hard-delete at 12 months (was 5 years)

#### Monitoring (Feature 040)
- **PipelineTraceHandler** uses dynamic direction lookup (was hardcoded ID `2`, should be `4`)

---

## [2.2.1] - 2026-04-03

### Fixed

#### STIX Export
- **IOC Explorer STIX export**: new "Export STIX" button exports filtered IOCs as a STIX 2.1 bundle directly from the IOC Explorer page (not just per-conversation)
- Fixed UTF-8 encoding issue in STIX report names (em dash replaced with hyphen)
- CS-Fixer: single quotes for SQL strings without interpolation + PHPDoc alignment

---

## [2.2.0] - 2026-04-03

### Added

#### IOC Explorer UI Overhaul (Feature 037)
- **IOC Detail Page** with 3 tabs: Overview (scoring bars, MISP mapping, STIX pattern), Observations (linked conversations), Related IOCs (co-occurrence table)
- **Co-occurrence Graph**: custom SVG radial layout showing IOC relationships, colored by type, clickable nodes
- **Observation Timeline**: Recharts ScatterChart showing when each IOC was observed, colored by extraction method
- **Advanced Filters**: severity (High/Medium/Low), confidence threshold (>0.9/>0.7/>0.5), date range (7d/30d/90d), hide header IOCs toggle
- Direct navigation from IOC list to detail page (removed intermediate side panel)
- "View full IOC detail" link in Conversation Detail IOC panel
- `GET /api/v1/iocs/{indicator_id}/detail` endpoint
- `GET /api/v1/iocs/co-occurrence` endpoint (graph data)

#### STIX 2.1 Full Conformity (Feature 038)
- **StixBundleBuilder** service generating OpenCTI-compatible STIX 2.1 bundles
- TLP marking-definitions with OpenCTI well-known UUIDs
- Indicators with: name, valid_from, valid_until (from decay config), confidence, created_by_ref, OpenCTI extensions (x_opencti_score, x_opencti_main_observable_type)
- Relationship objects (related-to) for co-occurring IOCs
- `GET /api/v1/conversations/{conv_id}/export/stix` endpoint
- "STIX 2.1" download button on Conversation Detail page
- Refactored existing campaign STIX export to use shared builder
- Header IOCs excluded from STIX export

#### Demo Dataset v4
- 1025 IOCs across 9 types (added Telegram usernames, ETH wallets, SHA256)
- Indicator table populated with mock enrichment data (VT/URLScan scores)
- Outbound message uniqueness: 98.6% (was 82%)
- `injectVariation()` post-processor with persona-group-specific greetings, interjections, time references

### Fixed

#### IOC Extraction
- **Telegram username**: regex bug `\B@` (not-word-boundary) replaced with `(?<!\w)@` (negative lookbehind); validator now requires letter start per Telegram spec
- **CVE extraction**: added to LLM prompt (rule 8: Security Identifiers) with examples
- **Category always "Unknown"**: `upsertEnrichedIoc` bypassed categorizer due to hardcoded `'Unknown'` placeholder; now checks for placeholder before calling categorizer
- **Category display**: replaced IocCategorizer mini-taxonomy (3 values) with conversation scam type (13 values) for user display; MISP mapping kept separate
- **Extraction method**: fallback to `source` field when `extraction_method` missing from context

#### STIX Export
- Fixed `TLP:TLP_AMBER` double prefix (DB stores `TLP_AMBER` with underscore)
- Fixed campaign export 500 error after STIXExporter refactor (missing Uuid import)
- Updated all STIX-related tests for new bundle structure

---

## [1.8.0] - 2026-03-30

### Added

#### Quality Benchmark Suite (Feature 016)
- 3 evaluation commands: `app:evaluate:generate-corpus`, `app:evaluate:reply-quality`, `app:evaluate:bandit-analysis`
- 9 quality metrics across 6 dimensions (diversity, naturalness, language compliance, IOC elicitation, safety)
- Makefile targets: `evaluate-corpus`, `evaluate-quality`, `evaluate-bandit`, `evaluate-all`

#### Pipeline Monitoring Dashboard (Feature 020)
- PipelineTrace and ComponentTrace value objects for per-reply tracing
- 3 API endpoints: `/monitoring/pipeline-traces`, `/monitoring/pipeline-traces/{msgId}`, `/monitoring/pipeline-health`
- React page at `/monitoring/pipeline` with live feed, component waterfall, health table

#### Injection Monitoring (Feature 021)
- `app:detect-prompt-injection` added to scheduler (every 6h)
- API endpoint: `/monitoring/injection` with risk stats and recent alerts
- React page at `/monitoring/injection` with coverage bar and alert list

#### Semantic Embeddings (Feature 022)
- `EmbeddingService` using OpenAI text-embedding-3-small (1536 dimensions)
- `app:generate-embeddings` command added to scheduler (every 6h)

#### Actor Profiles (Feature 022)
- `ActorProfileGenerator` computes style_dna and infra_dna from campaign messages
- `app:generate-actor-profiles` command added to scheduler (daily)

### Fixed

#### Reply Pipeline Hardening (Feature 017)
- PolicyGuardConfig::fromContext() now wired into ReplyOrchestrator (was dead code)
- Forbidden patterns narrowed from 16 to 6 (removed "test", "suspect", etc.)
- Validator prompt simplified — PolicyGuard owns syntax, Validator owns semantics
- Best-of-3 fallback strategy replaces canned response when validator rejects
- First-attempt approval: 29% → 100%, Fallback rate: 30% → 0%

#### Feedback Loop (Feature 018)
- engagement_duration_sec computed from actual message timestamps (was always 0)
- turns_count computed from message count (was always 0)
- CalculateRewardsCommand fixed (was broken — idempotence check bypassed)
- ConversationEndedListener: removed redundant reward double-write
- Scheduler: removed `profiles: [production]` gate, added `SCHEDULER_ENABLED` env var

#### Pipeline Observability (Feature 019)
- Dedicated production LLM log handler bypassing fingers_crossed
- CostEstimator wired into ReplyOrchestrator (was using hardcoded 16x underestimate)
- REPLY_GENERATED audit event added to ReplyHandler
- Debug logging added to LanguageDetector, ContextAnalyzer, ReciprocityManager

#### Dead Wiring Fixes (Feature 021)
- IOC multi-observation boost: `boostConfidence()` now called after indicator upsert
- Complete audit trail: 8 additional event types wired (MESSAGE_INGESTED, IOC_EXTRACTED, CONVERSATION_CLOSED, PERSONA_SELECTED, REPLY_SENT, INJECTION_DETECTED, EXPORT_STIX, EXPORT_MISP)
- Dead methods removed from PersonaPerformanceStatsRepository

#### Language Compliance
- `neutralizeLocale()` strips French cultural markers for non-French replies
- Language override instruction in OBJECTIVE section
- Persona labels migrated from French to English

---

## [1.7.0] - 2026-03-25

### Added

#### CI Pipeline Restoration (CT-1)
- Backend unit + integration tests now run in CI via Docker containers
- PHPUnit CI config with bootstrap_ci.php (include_once wrapper for Kernel)
- Comprehensive test suite passing in GitHub Actions

#### Security Headers (CT-2)
- Content-Security-Policy and Strict-Transport-Security headers on all API responses
- Relaxed CSP for Swagger UI page only (unsafe-inline/unsafe-eval)

#### Dependency Audit (CT-3)
- composer audit now blocking in CI (fails on new CVEs)
- 2 known CVEs ignored with documentation (Symfony 7.2, PHPUnit)

#### MISP/ATT&CK Taxonomy (CT-4)
- All 13 scam types mapped to MISP RSIT taxonomy and ATT&CK techniques

#### Community (CT-5, CT-6, CT-7)
- CODE_OF_CONDUCT.md (Contributor Covenant v2.1)
- GitHub Release v1.0.0 with release notes
- GitHub Discussions enabled (6 categories)

#### DPIA (CT-8)
- GDPR Article 35 Data Protection Impact Assessment v1.1

#### PII Masking (CT-9)
- PiiMaskingProcessor for Monolog (masks emails, IPs in logs)

#### PostgreSQL Backup (CT-10)
- Automated daily backup via scheduler service (pg_dump + verification)
- Restore documentation

#### OpenAPI 3.0 (MT-3)
- 100% API endpoint coverage with #[OA\*] annotations (43+ endpoints)
- Swagger UI at /api/doc with interactive documentation
- 7 endpoint tags: Auth, Communication, Campaign, Scambaiting, Monitoring, User, Meta

#### PHPStan Full Coverage (MT-6)
- Removed excludePaths for Infrastructure/ and UI/ layers
- 100% of src/ analyzed at level 6 bleeding edge, 0 errors

#### IOC Confidence Scoring & Decay (MT-10)
- Confidence score per IOC (0.0-1.0) based on extraction method
- Temporal decay with configurable half-life per IOC type
- Effective score = confidence x decay factor
- Frontend IOC Explorer updated with confidence column

#### SIEM Connector (MT-7)
- Pluggable SiemExporterInterface (hexagonal port/adapter)
- 3 adapters: NullSiemExporter, FileSiemExporter, SyslogSiemExporter
- 3 formatters: CEF (Common Event Format), ECS (Elastic Common Schema), JSON
- 16 audit event types with severity mapping
- CLI: app:siem:test + app:siem:export
- Complete integration guide: docs/15_siem_integration.md

#### Data Consistency Fixes
- Dashboard/Conversations active count aligned with Monitoring (31 vs 20 bug)
- Settings exploration rate, best persona, unique IOC types fixed
- Conversation list message count column populated
- All scam types shown in Monitoring (including 0-count)
- Convergence history section on Personas page
- Rate limits section on Monitoring page

#### Campaign Radar Frontend
- Campaign Detail page with metadata, messages, profile, actions
- Clickable campaign IDs in list
- Generate Profile (LLM), Promote Rule, Export STIX buttons
- Run Hunt button for admin users

### Changed
- Conversation lifecycle policies for all 13 scam types
- Per-sender rate limiting + flood detection
- Human delay simulation in n8n (log-normal distribution)

### Fixed
- CI Kernel double-include issue resolved (Docker-based test execution)
- Mock test MetaConfig missing llm_provider/llm_model fields
- Campaign route conflict (/campaign/{id} vs /campaign/candidates)

---

## [1.5.0] - March 2026

### Added — Security by Design

Based on the [security-by-design](https://github.com/laugiov/security-by-design) reference framework:

- **OWASP Security Headers**: 6 headers on all responses (X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, COOP, X-Permitted-Cross-Domain-Policies)
- **Structured Audit Trail**: `audit_log` table with 16 event types, `AuditLogger` service, `GET /api/v1/monitoring/audit` endpoint (paginated, filterable)
- **Request Trace ID**: `X-Trace-Id` header on every request/response, Monolog processor injects trace_id in all logs, audit events auto-capture trace_id
- **JWT RS256**: Migrated from HS256 (symmetric) to RS256 (asymmetric), TTL reduced from 1h to 15min
- **Key Management**: `generate-jwt-keys.sh` + `rotate-jwt-keys.sh` with zero-downtime rotation, `docs/14_key_management.md`
- **RBAC Permissions**: 12 fine-grained permissions via `PermissionVoter`, `Permission` enum, permissions JSON on User entity
- **Payload Size Limit**: Reject requests > 1MB (413 Payload Too Large)
- **CI Security**: `composer audit` (PHP SCA) + Gitleaks (secret detection) in GitHub Actions

### Fixed — PII Removal
- Removed all `error_log()` calls from production code (7 occurrences)
- Truncated debug logs: no more full LLM prompts or generated text in logs
- LLM providers log metadata only (lengths, token counts), never content

---

## [1.4.0] - March 2026

### Added

#### Multi-LLM Provider Support
- **AnthropicClient**: Claude Haiku, Sonnet, Opus via Messages API (system message as separate parameter)
- **OllamaClient**: Local inference with llama3, mistral, phi3 (zero cost, full privacy)
- **MockLLMClient**: Static responses for demo mode (no API key required)
- **LLMProviderCompilerPass**: Automatic provider selection via `LLM_PROVIDER` env var
- 14 unit tests for Ollama + Anthropic clients (HTTP mock, payload, headers, errors)

#### LLM Cost Tracking
- **LlmCallCompletedEvent**: Dispatched by each provider with real token counts from API responses
- **LlmUsageRecord**: Doctrine entity + `llm_usage` table for cost persistence
- **CostEstimator**: Per-model pricing (OpenAI, Anthropic; Ollama/Mock = free)
- **LlmUsageListener**: Event-driven, non-blocking cost recording
- **GET /api/v1/monitoring/llm-cost**: Monthly totals, per-purpose breakdown, daily trend
- 17 unit tests for CostEstimator + LlmUsageRecord

#### Demo Mode
- `LLM_PROVIDER=mock` bypasses all external API calls
- `scambuster:demo:load` command loads 123 synthetic conversations (1,034 messages, 382 IOCs)
- `make demo-load` Makefile target

#### Monitoring & Observability
- **GET /api/health**: Database + Redis connectivity checks with latency measurement
- **GET /api/metrics**: Prometheus text format (conversations, messages, IOCs, kill switch, health)
- `make validate` script checks all services, auth, and environment

#### MISP Integration
- `docs/13_misp_integration.md`: Export methods, attribute mapping, troubleshooting
- `scambuster:misp:test` console command for connectivity testing
- `make misp-test` Makefile target

#### Documentation
- `docs/11_database_schema.md`: Complete schema extracted from live database
- `docs/12_api_quick_reference.md`: All endpoints with curl examples
- `docs/13_misp_integration.md`: MISP integration guide

#### Open Source Readiness
- GitHub issue templates (bug report, feature request, question) with YAML forms
- Pull request template with DDD architecture checklist
- GitHub Actions CI: frontend job (TypeScript, ESLint, Vitest, Vite build)
- `scripts/check-env.sh`: Environment variable validation
- `scripts/validate-install.sh`: Full installation health check
- `docker-compose.override.yml.example` for local customizations

#### Infrastructure
- Redis healthcheck in Docker Compose
- `depends_on: condition: service_healthy` for reliable startup order
- Vite proxy corrected to target backend-dev
- `role_hierarchy: ROLE_ADMIN -> ROLE_USER` in security config

### Fixed
- 4 pre-existing test failures (auth headers, detached entity cleanup, unique constraint)
- ESLint error: `statusToBadgeVariant` extracted to separate file for React Fast Refresh
- Frontend Dockerfile: removed silent `npm ci` failure

### Removed
- `scripts/manage-workflows.sh` (n8n credentials must be configured manually in UI)

---

## [1.3.0] - March 2026

### Added
- English synthetic dataset generation (100+ conversations)
- n8n workflow anonymization for public preview
- Documentation update for preview repository

---

## [1.2.0] - January 2026

### Added
- A/B testing validation framework (2,221 synthetic conversations)
- Statistical analysis: p < 0.001, Cohen's d = 0.37
- Test suite expanded to 1,039 automated tests

---

## [1.1.0] - December 2025

### Added
- Prompt injection detection via InjectionDetector agent (two-layer forensic)
- Scaled platform to 1,000+ active conversations

---

## [1.0.0] - 21 November 2025

### Added - Adaptive Scambaiting Module

#### Database
- **Migration 1**: ALTER TABLE `conversation` - 3 new columns:
  - `engagement_duration_sec` (INTEGER): Conversation duration in seconds
  - `turns_count` (INTEGER): Number of conversation turns
  - `reward_value` (NUMERIC(5,4)): Normalized reward [0.0, 1.0]
  - Indexes: `idx_conversation_reward`, `idx_conversation_duration`

- **Migration 2**: CREATE TABLE `persona_performance_stats`:
  - Composite key: `(persona_id, scam_type_id)` for contextual bandit
  - Columns: `sessions_count`, `reward_sum`, `reward_avg`, `last_updated`
  - CHECK constraints: `sessions_count >= 0`, `reward_avg BETWEEN 0.0 AND 1.0`
  - Indexes: `scam_type_id`, `reward_avg DESC`, `last_updated DESC`
  - Foreign keys CASCADE to `persona` and `lkp_scam_type`

#### Domain Layer
- **Value Object** `ConversationMetrics`: Conversation metrics (duration, IOCs, completion)
- **Value Object** `PersonaPerformance`: Persona performance stats (reward_avg, sessions_count)
- **Domain Event** `ConversationEndedEvent`: Dispatched when a conversation ends

#### Application Layer
- **Service** `PersonaOptimizer`: Epsilon-greedy algorithm (80% exploitation, 20% exploration)
  - `selectPersona(string $scamTypeCode): ?string` -- Optimal selection
  - `getSelectionStats(string $scamTypeCode): array` -- Selection stats
  - Cold start handling: < 3 sessions = pure exploration
  - Tie-breaking by sessions_count

- **Service** `ConversationMetricsCollector`: Metrics collection
  - Reuses existing `IocHandler` and `MessageHandler`
  - Returns a `ConversationMetrics` Value Object

- **Service** `ConversationClosureService`: Conversation closure
  - `closeConversation(string $convId): void` -- Single closure
  - `closeConversationsBatch(array $convIds): int` -- Batch closure (CRON)
  - Dispatches `ConversationEndedEvent` after reward computation

#### Infrastructure Layer
- **Entity** `PersonaPerformanceStatsEntity`: Doctrine entity for persistence
- **Repository** `PersonaPerformanceStatsRepository`: Doctrine repository
  - Methods: `findOrCreate`, `findBestPerformingPersona`, `findTopPerformingPersonas`, `findAllByScamType`, `countColdStartPersonas`
- **Event Listener** `ConversationEndedListener`: Updates `persona_performance_stats` with new reward (moving average)

#### UI Layer (REST API)
- **POST** `/api/v1/scambaiting/conversation/{convId}/close` -- Close a conversation
- **GET** `/api/v1/scambaiting/stats/{scamTypeCode}` -- Epsilon-greedy stats for a scam type
- **GET** `/api/v1/scambaiting/stats` -- Aggregated stats across all scam types
- **GET** `/api/v1/scambaiting/persona/{personaCode}/performance` -- Persona performance
- **POST** `/api/v1/scambaiting/select-persona` -- Test endpoint for selection

All endpoints require JWT authentication.

#### n8n Workflow
- **Workflow** `WF-SCAMBAITING-END-CONVERSATION`:
  - Daily CRON at 03:00 UTC
  - Queries PostgreSQL: conversations with `status='open'` and `created_at < NOW() - 48h`
  - Loops over conversations (limit 500)
  - Calls API `POST /close` for each conversation
  - Aggregates results (success, failed)

#### Tests
- **10 unit tests (Domain)**: ConversationMetrics, PersonaPerformance, ConversationEndedEvent
- **5 unit tests (Application)**: PersonaOptimizer selection, cold start, tie-breaking
- **7 integration tests**: Repository, Event Listener, Controllers
- **9 regression tests**: ReplyHandler (no breakage of existing behavior)
- **3 E2E tests**: Full end-to-end workflow
- **6 fixture scenarios**: Exploitation, exploration, cold start, edge cases

**Total**: 40+ tests, 100+ assertions, ~95% coverage

### Changed

#### Application Layer
- **`ReplyHandler.php`**: Replaced `assignRandomPersona()` with `PersonaOptimizer->selectPersona()`
  - Before: Uniform random selection
  - After: Epsilon-greedy selection (80% exploitation, 20% exploration)

#### Symfony Services
- **services.yaml**: Autowiring configuration for `PersonaOptimizer`

### Fixed
- **ConversationEndedEventTest**: Type mismatch `conversationId` (int to string)
- **Controller permissions**: `chmod 644` on all UI Layer controllers
- **Symfony cache**: Cache invalidation after controller creation

---

## [0.9.0] - 15 November 2025

### Added
- Conversation history summary for LLM context enrichment
- Endpoint: `POST /api/v1/communication/conversation/{convId}/history-summary`
- Service: `ConversationHistoryService` with LLM-generated summary

### Changed
- `ReplyHandler`: Integrated history summary into LLM context

---

## [0.8.0] - 10 November 2025

### Added
- Post-IBAN strategy for capturing additional IOCs
- Optimized reply generation after IBAN capture

### Changed
- `ReplyOrchestrator`: Added high-value IOC capture logic

---

## [0.7.0] - 5 November 2025

### Added
- Multi-conversation support with the same sender email
- Multiple active conversations per sender handling

### Fixed
- Duplicate attachment upload error

---

## [Unreleased] - Before November 2025

Earlier versions not documented in this changelog.
History available via `git log`.

---

## Change Types

- **Added**: New features
- **Changed**: Changes to existing features
- **Deprecated**: Features to be removed soon
- **Removed**: Removed features
- **Fixed**: Bug fixes
- **Security**: Security fixes

---

**Format**: [Keep a Changelog](https://keepachangelog.com/)
**Versioning**: [Semantic Versioning](https://semver.org/)
