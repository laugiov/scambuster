# scambuster-github Development Guidelines

Auto-generated from all feature plans. Last updated: 2026-04-10

## Active Technologies
- PHP 8.3 / Symfony 7.2 + ReplyOrchestrator, PolicyGuard, ReplyValidator, PromptBuilder, CostEstimator (all existing) (017-fix-validation-pipeline)
- PostgreSQL 15 (no schema changes) (017-fix-validation-pipeline)
- PHP 8.3 / Symfony 7.2 + ConversationClosureService, ConversationMetricsCollector, ConversationMetrics, CalculateRewardsCommand, PersonaOptimizer (all existing) (018-fix-feedback-loop)
- PostgreSQL 15 (no schema changes — fields exist, they're just never written) (018-fix-feedback-loop)
- PHP 8.3 / Symfony 7.2 + Monolog, ReplyOrchestrator, CostEstimator, PolicyGuardConfig, AuditLogger, PromptBuilder (all existing) (019-pipeline-observability)
- PostgreSQL 15 (no schema changes — AuditEventType::REPLY_GENERATED already exists) (019-pipeline-observability)
- PHP 8.3 / Symfony 7.2 (backend), React 19 / TypeScript (frontend) + ReplyOrchestrator (trace collection), existing message.headers JSON (storage), NelmioApiDoc (API docs) (020-pipeline-monitoring-dashboard)
- PostgreSQL 15 (no new tables — trace in message.headers JSON column) (020-pipeline-monitoring-dashboard)
- PHP 8.3 / Symfony 7.2, React 19 / TypeScript (021-fix-dead-wiring)
- PostgreSQL 15 — message_vector table (vector_id, embedding JSON, model_name, dim) and actor_profile table (actor_id, style_dna JSONB, infra_dna JSONB) already exist (022-activate-dormant-features)
- PHP 8.3 / Symfony 7.2 (backend), TypeScript 5.9 / React 19 (frontend) + Doctrine DBAL (queries), Recharts 3.8.0 (charts, already installed), React Query (data fetching) (024-analytics-charts-csv-export)
- PostgreSQL 15 (read-only analytics queries on existing tables) (024-analytics-charts-csv-export)
- PHP 8.3 / Symfony 7.2 (backend), TypeScript 5.9 / React 19 (frontend) + Doctrine ORM 3.3, Lexik JWT 3.1, PHPStan 1.12, scheb/2fa-bundle 7.9 (039-short-term-hardening)
- PostgreSQL 15, Redis 7, HashiCorp Vault (039-short-term-hardening)
- PHP 8.3 / Symfony 7.2 + Doctrine ORM 3.3, Lexik JWT 3.1, scheb/2fa-bundle 7.9 (installed), StixBundleBuilder (existing) (040-medium-term-hardening)
- PostgreSQL 15, Redis 7 (one migration for MT-11: totp_secret column on app_users) (040-medium-term-hardening)
- PHP 8.3 / Symfony 7.2 (for GTM-8 only) + n8n (Forward workflow), YouTube (video hosting), NLnet (grant platform) (041-adoption-go-to-market)
- No schema changes (041-adoption-go-to-market)
- PHP 8.3 / Symfony 7.2 (backend), TypeScript / React 19 (frontend) (041-impact-dashboard)
- PostgreSQL 15 (read-only queries on existing tables). Optional Redis cache. (041-impact-dashboard)
- PHP 8.3 / Symfony 7.2 + StixBundleBuilder (existing), TaxiiService (existing), ActorProfileGenerator (existing), CampaignStixExportHandler (existing) (044-stix-threat-actor-export)
- PostgreSQL 15 — 1 SQL migration (MITRE mapping update), no new tables (044-stix-threat-actor-export)
- PHP 8.3 / Symfony 7.2 (backend), TypeScript 5.9 / React 19 (frontend) + Doctrine ORM 3.3, Lexik JWT 3.1, TanStack Query 5.91, Zustand 5.0, Recharts 3.8, TailwindCSS 4.2 (050-consolidation-hardening)
- PostgreSQL 15, Redis 7 (cache.adapter.redis for rate limiting + framework cache) (050-consolidation-hardening)

- PHP 8.3 / Symfony 7.2 + ReplyOrchestrator, ReplyHandler, PromptBuilder, PolicyGuard, ReplyValidator (existing), no new external dependencies (016-quality-benchmark-suite)
- PHP 8.3 / Symfony 7.2 + IocClusteringService, ClusterQueryService, ClusteredThreatActorStixBuilder, NormalizedIocValue, ClusterStixIdGenerator (058a/b/c-clustering)
- PostgreSQL 15 — 3 new tables: threat_actor_cluster, threat_actor_cluster_conversation, threat_actor_cluster_ioc + idx_observed_ioc_indicator_id (058a-clustering)
- React 19 / TypeScript — Clusters.tsx, ClusterDetail.tsx, useClusters.ts hooks (058c-clustering-frontend)
- TAXII 2.1 — 3rd collection threat-actors (UUID 0003), STIX 2.1 cluster export with extension-definitions (058b-clustering-stix)

## Project Structure

```text
src/
tests/
```

## Commands

# Add commands for PHP 8.3 / Symfony 7.2

## Code Style

PHP 8.3 / Symfony 7.2: Follow standard conventions

## Recent Changes
- 062-mitre-attck-mapping-refresh: Removed deprecated/wrong MITRE ATT&CK mappings. T1534 (insider) → T1566.002 for INVOICE_FRAUD/CEO_FRAUD. T1566.004 (retired) → T1656 (Impersonation, added MITRE v14 Oct 2023) for TECH_SUPPORT. T1566.001 → T1656 for ROMANCE/LOTTERY/CHARITY/ADVANCE_FEE_419/INVESTMENT. New irreversible forward migration Version2026041100000000.php (down() throws IrreversibleMigration). Added T1656 to ThreatActorStixBuilder::MITRE_TECHNIQUES. 5 new MitreMappingTest assertions. Min OpenCTI version >= 5.10 for T1656 support.
- 061-ioc-extraction-skip-platform-mails: Defense in depth against platform contamination. Layer 1 = direction guard at 3 admin entry points + IocUpsertService funnel (throws InvalidArgumentException → 400 on outgoing messages). Layer 2 = HONEYPOT_EMAIL_ADDRESSES env-config filter at upsert time, case-insensitive, catches scammers quoting our email back. New `app:indicator:cleanup-platform-contamination` command (--dry-run, CSV audit, idempotent transactional cleanup). 15 new tests + permanent NoIocFromOutgoingMessageTest in `make test`. E2E validated with 3 real test mails through n8n pipeline. Cleaned 2 honeypot indicators + 141 cascade observations from dev DB.
- 060-stix-export-hardening: Removed O(n^2) related-to indicator mesh from conversation + IOC explorer exports (Sprint 1). Conversation exports now attribute to cluster threat-actor when conversation belongs to a cluster, singleton actor renamed "Unattributed Scam Actor (Type)" otherwise (Sprint 2). 27 new tests, validated visually on 7 STIX bundles. Honeypot/MITRE issues deferred to specs 061/062.
- 059-cluster-detail-enrichment: Threat Profile + Campaign Excerpts on cluster detail. ClusterQueryService computeBehavioralProfile/computeAnchorBehaviors aggregations from ioc_context (MODE WITHIN GROUP, COUNT DISTINCT). Reused IocDetail badge palette. Zero new LLM calls. 25 new tests.
- 058a/b/c-clustering: Real-time threat actor clustering via Union-Find on financial IOCs. IocClusteringService, ClusteredThreatActorStixBuilder, TAXII 3rd collection, API + React pages, scheduler 30min backfill. 125 new tests (TDD).
- 050-consolidation-hardening: Added PHP 8.3 / Symfony 7.2 (backend), TypeScript 5.9 / React 19 (frontend) + Doctrine ORM 3.3, Lexik JWT 3.1, TanStack Query 5.91, Zustand 5.0, Recharts 3.8, TailwindCSS 4.2
- 044-stix-threat-actor-export: Added PHP 8.3 / Symfony 7.2 + StixBundleBuilder (existing), TaxiiService (existing), ActorProfileGenerator (existing), CampaignStixExportHandler (existing)
- 043-rich-contextual-ioc: Added PostgreSQL 15 (ioc_context table), PHP 8.3 / Symfony 7.2 (IocContextService, ContextualEnricher, MessageAnonymizer), React 19 (Context tab, IOC Explorer sparkle filter), STIX x_scambuster_context extension, TAXII context in feed


<!-- MANUAL ADDITIONS START -->
<!-- MANUAL ADDITIONS END -->
