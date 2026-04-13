# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

---

## [2.14.0] - 2026-04-10

### Added

- Attachment SHA256 hashes now generate observed IOC rows, visible in IOC Explorer and STIX exports
- Actor Deduplication stat card on the Impact page showing cluster noise reduction metrics

---

## [2.13.0] - 2026-04-10

### Added

- End-to-end email attachment capture via IMAP intake pipeline with SHA256 hashing
- Backend attachment parser fallback for producers that forward raw RFC822 without pre-extracted attachments
- Path-aware payload size limits: 50 MB for mail ingestion endpoints, 1 MB default elsewhere
- n8n workflow updated to download and hash attachments at intake time
- 18 tests added

---

## [2.12.0] - 2026-04-10

### Changed

- Refreshed MITRE ATT&CK mappings: replaced deprecated T1534 and retired T1566.004 with T1566.002 and T1656 (Impersonation) for 8 scam types
- Minimum compatible OpenCTI version is now 5.10 (for T1656 support)
- 5 tests added

---

## [2.11.0] - 2026-04-10

### Added

- Direction guard preventing IOC extraction from outgoing (honeypot-generated) messages
- Honeypot email identity filter blocking platform addresses quoted back by scammers
- One-time cleanup command for historical platform contamination in the IOC catalogue
- Configurable `HONEYPOT_EMAIL_ADDRESSES` environment variable for operator-defined filtering
- 15 tests added

---

## [2.10.0] - 2026-04-10

### Changed

- Removed O(n^2) indicator-to-indicator relationship mesh from conversation and bulk IOC STIX exports
- Clustered conversations now attribute indicators to the shared cluster threat-actor in STIX bundles
- Unclustered conversations use a readable "Unattributed Scam Actor (Type)" naming convention
- 27 tests added

---

## [2.9.0] - 2026-04-10

### Added

- Threat Profile section on cluster detail page with dominant stimulus, urgency, and behavioral aggregations
- Campaign Excerpts section showing deduplicated context excerpts with occurrence counts
- Per-anchor IOC behavioral pills (semantic role, stimulus, urgency)
- Navigation links from anchor IOCs to IOC Detail page
- 25 tests added (zero new LLM cost)

---

## [2.8.0] - 2026-04-09

### Added

- Real-time threat actor clustering via Union-Find on financial IOCs (IBAN, crypto wallets, phone numbers)
- Three new database tables for cluster storage and IOC-to-cluster mapping
- STIX 2.1 threat-actor export for clusters with deterministic UUIDs
- TAXII 2.1 third collection (`threat-actors`) for automated CTI feed consumption
- Five API endpoints for cluster listing, stats, detail, STIX export, and IOC-to-cluster lookup
- Frontend cluster list page with KPI cards and cluster detail page with anchor IOCs
- Scheduler-driven backfill every 30 minutes and real-time clustering at ingestion
- Mega-cluster guard flagging clusters exceeding 50 conversations as SUSPECT
- IOC normalization (IBAN whitespace, ETH lowercase, phone digits-only) and whitelisting for known contract addresses
- 125 tests added

---

## [2.7.0] - 2026-04-09

### Added

- IOC severity system: HIGH (IBAN, crypto, phone), MEDIUM (URL, domain, email, IP), LOW (metadata)
- Dominance Evolution chart for persona performance over time
- IOC count column in conversation list (infrastructure IOCs excluded)
- Faceted filters (status, scam type) with URL persistence on conversation list
- Column sorting across conversation list, IOC Explorer, and IOC Detail

### Changed

- Comprehensive UX overhaul across all frontend screens based on CTI/UX expert audit
- Scam type colored badges, risk score progress bars, and precise timestamps throughout
- CLOSED conversation badge changed from red to neutral gray
- Infrastructure IOCs (DMARC/SPF/DKIM) collapsed into a dedicated section in conversation detail
- Campaign UI hidden from frontend (pipeline disconnected)

### Fixed

- Conversation header counters mismatch (abandoned conversations not counted)
- IOC Detail aggregate score reference breaking production build
- ObservedIoc array access bug in ReplyContextService

---

## [2.6.0] - 2026-04-07

### Added

- Threat Actor card on conversation detail showing sophistication, goals, and MITRE ATT&CK mapping
- Threat Actor summary card on IOC detail aggregating attribution across linked conversations
- TAXII IOC feed now includes threat-actor and attack-pattern objects alongside indicators

### Changed

- Removed `attributed-to` STIX relationship (incompatible with OpenCTI)
- TAXII limit parameter now applies to indicators only; enrichment objects are additional

### Removed

- Deleted obsolete GenerateDemoDataCommand (replaced by LoadDemoDataCommand)

---

## [2.5.0] - 2026-04-06

### Added

- STIX 2.1 threat-actor objects in conversation exports with sophistication scoring and behavioral profiles
- MITRE ATT&CK attack-pattern objects and indicator-to-threat-actor relationships
- Deterministic UUIDs for OpenCTI/MISP deduplication across exports

### Fixed

- ActorProfileGenerator column reference and direction ID lookup bugs

---

## [2.4.0] - 2026-04-06

### Added

- Structural IOC context computed at extraction time (revelation turn, scam type, co-revealed IOC types)
- LLM semantic enrichment per message: semantic role, stimulus type, urgency score, behavioral signals
- PII anonymization before LLM analysis with post-analysis validation
- IOC Context API endpoint and frontend Context tab in IOC Detail
- IOC Explorer "Has context" filter and visual indicator
- STIX extension and TAXII feed enrichment with contextual metadata
- Batch backfill command with configurable USD budget cap
- Full English and French translations for all context UI labels

---

## [2.3.0] - 2026-04-04

### Added

- TAXII 2.1 server with 4 endpoints for automated CTI feed consumption (OpenCTI, MISP, TheHive, SIEM)
- STIX pattern mapping for 8+ IOC types with delta sync support
- MFA via TOTP for admin accounts (opt-in, backward compatible)
- Fine-grained RBAC with 12 permissions across 37 controllers
- Dependabot, Trivy container scanning, and CycloneDX SBOM generation in CI
- Governance documentation (GOVERNANCE.md, MAINTAINERS.md, FUNDING.yml)

### Changed

- IocHandler decomposed from 1277 LOC monolith into 4 focused services
- IngestHandler decomposed from 891 LOC into 3 services plus orchestrator
- ReplyHandler decomposed from 941 LOC into 3 services plus orchestrator
- EntityManager removed from all 6 controllers that had direct access
- 5 domain repository interfaces added following hexagonal architecture

### Fixed

- Login rate limiting now backed by Redis (was inoperative static counter)
- GDPR retention aligned: soft-delete at 6 months, hard-delete at 12 months
- Pipeline trace handler direction lookup (was hardcoded, now dynamic)

---

## [2.2.1] - 2026-04-03

### Added

- "Export STIX" button on IOC Explorer page for bulk filtered export

### Fixed

- UTF-8 encoding issue in STIX report names

---

## [2.2.0] - 2026-04-03

### Added

- IOC Detail page with Overview, Observations, and Related IOCs tabs
- Co-occurrence graph with interactive SVG radial layout
- Observation Timeline chart showing IOC sightings over time
- Advanced IOC filters: severity, confidence threshold, date range, hide header IOCs
- STIX 2.1 bundle builder with OpenCTI-compatible extensions and TLP markings
- Conversation STIX export endpoint and download button
- Demo dataset v4 with 1025 IOCs across 9 types

### Fixed

- Telegram username regex (false negatives on word boundaries)
- CVE extraction added to LLM prompt
- IOC category always showing "Unknown" due to hardcoded placeholder bypass
- STIX TLP double-prefix issue and campaign export error

---

## [1.8.0] - 2026-03-30

### Added

- Quality benchmark suite with 3 evaluation commands and 9 quality metrics
- Pipeline monitoring dashboard with per-reply tracing and component waterfall view
- Prompt injection monitoring with scheduled detection and alert dashboard
- Semantic embedding generation using OpenAI text-embedding-3-small
- Actor profile generation (style and infrastructure DNA)

### Fixed

- PolicyGuard configuration now properly wired into ReplyOrchestrator (was dead code)
- Forbidden pattern list reduced from 16 to 6 (too many false positives)
- Reply validation: first-attempt approval improved from 29% to 100%
- Feedback loop: engagement duration and turn count now computed from real data (were always 0)
- Reward calculation command fixed (idempotence check was bypassed)
- LLM cost estimator wired correctly (was using 16x underestimate)
- IOC multi-observation confidence boost now applied after indicator upsert
- 8 additional audit event types wired into the audit trail
- Language compliance: French cultural markers stripped for non-French replies

---

## [1.7.0] - 2026-03-25

### Added

- CI pipeline with Docker-based test execution in GitHub Actions
- Security headers (CSP, HSTS) on all API responses
- Dependency audit blocking in CI on new CVEs
- MISP/ATT&CK taxonomy mapping for all 13 scam types
- Community files: Code of Conduct, GitHub Discussions, first release
- GDPR Data Protection Impact Assessment
- PII masking in logs (emails, IPs)
- Automated PostgreSQL daily backup via scheduler
- OpenAPI 3.0 documentation covering 43+ endpoints with Swagger UI
- PHPStan level 6 full coverage (0 errors on entire src/)
- IOC confidence scoring with temporal decay and configurable half-life
- SIEM connector with CEF, ECS, and JSON formatters (pluggable adapter pattern)
- Campaign detail page with LLM profile generation and STIX export
- Convergence history and rate limits on monitoring pages

### Changed

- Per-sender rate limiting with flood detection
- Human delay simulation in outbound replies (log-normal distribution)

### Fixed

- Dashboard and conversation list count mismatches
- Settings page: exploration rate and best persona display

---

## [1.5.0] - March 2026

### Added

- OWASP security headers on all responses (6 headers)
- Structured audit trail with 16 event types and filterable API endpoint
- Request trace ID (`X-Trace-Id`) on all requests with Monolog integration
- JWT migrated from HS256 to RS256 with key rotation support
- Fine-grained RBAC with 12 permissions via PermissionVoter
- Payload size limit (1 MB) on all API requests
- CI security scanning: composer audit and Gitleaks secret detection

### Fixed

- Removed all `error_log()` calls from production code
- LLM providers no longer log prompt or response content, only metadata

---

## [1.4.0] - March 2026

### Added

- Multi-LLM provider support: Anthropic (Claude), Ollama (local inference), and Mock (demo mode)
- LLM cost tracking with per-model pricing, monthly totals, and daily trend API
- Demo mode (`LLM_PROVIDER=mock`) with 123 synthetic conversations
- Health check and Prometheus metrics endpoints
- MISP integration guide and connectivity test command
- Complete API and database schema documentation
- GitHub issue/PR templates and CI for frontend (TypeScript, ESLint, Vitest)
- Environment validation scripts

### Fixed

- 4 pre-existing test failures (auth headers, detached entities, unique constraints)
- Frontend Docker build and ESLint configuration issues

### Removed

- Manual n8n workflow management script (credentials must be configured via UI)

---

## [1.3.0] - March 2026

### Added

- English synthetic dataset generation (100+ conversations)
- n8n workflow anonymization for public preview

---

## [1.2.0] - January 2026

### Added

- A/B testing validation framework (2,221 synthetic conversations, p < 0.001, Cohen's d = 0.37)
- Test suite expanded to 1,039 automated tests

---

## [1.1.0] - December 2025

### Added

- Prompt injection detection via two-layer forensic analysis
- Scaled platform to 1,000+ active conversations

---

## [1.0.0] - 2025-11-21

### Added

- Adaptive scambaiting module with epsilon-greedy persona selection (80/20 exploit/explore)
- Conversation metrics: engagement duration, turn count, normalized reward scoring
- Persona performance tracking per scam type with cold-start handling
- Automated conversation closure with batch support and event-driven reward updates
- 5 REST API endpoints for conversation lifecycle and persona statistics
- n8n workflow for daily conversation closure (48h inactivity threshold)
- 40+ tests with ~95% coverage

---

## [0.9.0] - 2025-11-15

### Added

- Conversation history summary for LLM context enrichment

---

## [0.8.0] - 2025-11-10

### Added

- Post-IBAN capture strategy for eliciting additional IOCs from scammers

---

## [0.7.0] - 2025-11-05

### Added

- Multi-conversation support per sender email

### Fixed

- Duplicate attachment upload error

---

**Format**: [Keep a Changelog](https://keepachangelog.com/)
**Versioning**: [Semantic Versioning](https://semver.org/)
