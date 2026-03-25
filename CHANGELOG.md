# Changelog - ScamBuster

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [1.7.0] - 2026-03-25

### Added

#### CI Pipeline Restoration (CT-1)
- Backend unit + integration tests now run in CI via Docker containers
- PHPUnit CI config with bootstrap_ci.php (include_once wrapper for Kernel)
- 1150+ tests passing in GitHub Actions

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
