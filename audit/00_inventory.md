# Phase 0 — Factual inventory

> **Nature of this document.** Purely descriptive. No judgement, no recommendation.
> Statuses: `[VERIFIED]` = read in a file (path + line). `[INFERRED]` = inference,
> with the reasoning spelled out. `[UNKNOWN]` = cannot be determined from the files.
>
> Audited commit: `f3b2188` — branch `claude/scambuster-security-audit-ftmolg`.
> Method: exploration delegated to 6 read-only sub-agents, with the instruction
> "path + line mandatory, exact replayable commands".

---

## 0. Repository metadata

| Fact | Value | Status |
|---|---|---|
| Number of commits in the public history | 10 | [VERIFIED] `git rev-list --count HEAD` |
| First commit | `7e71739` — 2026-08-05 — "ScamBuster initial public release" | [VERIFIED] `git log --reverse` |
| Last commit | `f3b2188` — 2026-08-10 — "Also exclude false positives from cluster anchor persistence (#46)" | [VERIFIED] `git log -1` |
| Time span of the history | 2026-08-05 → 2026-08-10 (6 days) | [VERIFIED] `git log --format='%ad' --date=format:'%Y-%m' \| sort \| uniq -c` → `10 2026-08` |
| Contributors | 2 identities, a single natural person: `laugiov@users.noreply.github.com` (9), `laurent.giovannoni@filigran.io` (1) | [VERIFIED] `git shortlog -sne HEAD` |
| Git tags | **0** | [VERIFIED] `git tag -l \| wc -l` → `0` |
| Commit signatures | 9 commits carry an SSH signature that cannot be verified in this environment (`%G?` = `E`, `gpg.ssh.allowedSignersFile` absent); 1 unsigned commit (`7e71739`, `%G?` = `N`) | [VERIFIED] `git log --format='%h\|%G?\|%GS\|%s'` |
| License | MIT, "Copyright (c) 2025-2026 Laurent Giovannoni" | [VERIFIED] `LICENSE:1-3` |
| Size | 1905 files excluding `.git`; 1283 `.php`, 196 `.tsx`, 99 `.eml`, 92 `.ts`, 56 `.md`, 43 `.yaml` | [VERIFIED] `find . -path ./.git -prune -o -type f -print \| wc -l` then `\| sed 's/.*\.//' \| sort \| uniq -c \| sort -rn` |
| Makefile targets | 96 | [VERIFIED] `grep -cE '^[a-zA-Z0-9_.-]+:' Makefile` |

**History / production gap.** The audit brief states that the system went into production
in November 2025; the public git history starts on 2026-08-05 with a single
"initial public release" commit. [INFERRED] The published history is a squash: the
9 months of prior development are not in this repository, so neither the design
decisions, nor the past security fixes, nor the evolution of the guardrails can be
traced from git. Reasoning: 10 commits covering 6 days for 1905 files and
66 migrations, the oldest of which is dated `Version20250510093411`
(May 2025) — the migrations attest to a history that the commits do not carry.

---

## 1. Actual directory tree and role of each module

### 1.1 Layers of `backend-symfony/src` [VERIFIED] `ls -d backend-symfony/src/*/`

| Layer | Path | Contents |
|---|---|---|
| Application | `backend-symfony/src/Application` | 19 sub-modules |
| Domain | `backend-symfony/src/Domain` | 11 sub-modules |
| Infrastructure | `backend-symfony/src/Infrastructure` | 11 sub-modules |
| Security | `backend-symfony/src/Security` | 5 flat classes |
| UI | `backend-symfony/src/UI` | `Console`, `Http` |
| DataFixtures | `backend-symfony/src/DataFixtures` | `Campaign`, `Communication`, `Scambaiting`, `User` |

#### Application sub-modules [VERIFIED] `ls -d backend-symfony/src/Application/*/`

| Module | Representative file | Role |
|---|---|---|
| Audit | `src/Application/Audit/AuditHmacChainer.php` | Audit event logging, HMAC chaining, conversation quality audit |
| Auth | `src/Application/Auth/AuthService.php`, `.../Oidc` | Login, TOTP verification (`TotpVerifier.php`), OIDC, login-hash generation |
| Campaign | `src/Application/Campaign/CampaignHunter.php`, `DSLTranspiler.php` | Campaign hunting and profiling, promotion, DSL rule transpiling, campaign STIX export |
| Clustering | `src/Application/Clustering/IocClusteringService.php` | Grouping actors by IOC, time-based analysis |
| Communication | `src/Application/Communication/IngestHandler.php` — **66 files** | Email ingestion, threading, IOC extraction/normalisation/validation, classification, risk scoring, reply generation and composition, SMTP sending, personas, TTP, prompt injection detection |
| Evaluation | `src/Application/Evaluation/BanditAnalyzer.php`, `IocExtractionMetrics.php` | Corpus generation, LLM-judge metrics, confidence intervals, reply quality analysis |
| Export | `src/Application/Export/IocFeedExporter.php` | IOC feed export |
| Guard | `src/Application/Guard/PromptCanaryService.php` | Prompt canary smoke runs, comparison against the baseline |
| LLM | `src/Application/LLM/ContextAnalyzer.php`, `EmbeddingService.php`, `CostEstimator.php`, `Director/` | LLM orchestration, contextual enrichment, embeddings, cost estimation |
| Meta | `src/Application/Meta/ConfigHandler.php` | Runtime config exposure, preprod copy/purge |
| Monitoring | `src/Application/Monitoring/HealthCheckHandler.php` | Analytics, health checks, autonomy and lifecycle monitoring, budget threshold notification |
| Prompt | `src/Application/Prompt/PromptOverrideHandler.php` | Operator prompt overrides, canary jobs, body validation |
| Scambaiting | `src/Application/Scambaiting/PersonaOptimizer.php`, `BanditConvergenceReporter.php` | Persona selection/optimisation, cognitive mirrors, conversation closure |
| Stats | `src/Application/Stats/FinancialRevealTimingService.php` | Timing statistics for financial reveal and urgency |
| Stix | `src/Application/Stix/ConversationStixExportHandler.php`, `ExtensionSchemaValidator.php` | STIX 2.1 bundle building, custom extension validation |
| Taxii | `src/Application/Taxii/TaxiiService.php` | TAXII collections/objects service |
| ThreatActor | `src/Application/ThreatActor/ThreatActorPsychProfileGenerator.php`, `AbuseReportGenerator.php` | Actor psychological profiling, abuse reports, analyst feedback on IOCs |
| Ttp | `src/Application/Ttp/TtpHandler.php` | Upsert and querying of TTP observations |
| User | `src/Application/User/UserPasswordValidator.php` | Password policy |

#### Domain sub-modules [VERIFIED] `ls -d backend-symfony/src/Domain/*/`

| Module | Representative file | Contents |
|---|---|---|
| Audit | `src/Domain/Audit/AuditLog.php`, `SiemEvent.php`, `SiemSeverityMap.php` | Audit log entity, event types, SIEM severity mapping |
| CampaignRadar | `src/Domain/CampaignRadar/Campaign.php`, `CampaignRule.php`, `ActorProfile.php` | Campaign entities + repository interface + status enum |
| Clustering | `src/Domain/Clustering/Service/ClusterStixIdGenerator.php`, `ValueObject/NormalizedIocValue.php` | Cluster STIX ID generation, normalised IOC value VO |
| Communication | `src/Domain/Communication/Conversation.php`, `Attachment.php`, `Channel.php`, `Direction.php` | Core conversation/message/channel entities + repository interfaces |
| Exception | `src/Domain/Exception/DomainException.php` | Base domain exception |
| LLM | `src/Domain/LLM/PipelineTrace.php`, `ComponentTrace.php`, `LlmUsageRecord.php` | LLM traces and usage records, events/exceptions |
| Prompt | `src/Domain/Prompt/PromptOverride.php`, `PromptCanaryJob.php` | Prompt override and canary job entities, `CanaryJobStatus` enum |
| Scambaiting | `src/Domain/Scambaiting/PersonaPerformance.php`, `BanditConvergenceLog.php`, `ConversationMetrics.php` | Persona performance, bandit convergence and metrics entities |
| ThreatActor | `src/Domain/ThreatActor/ThreatActorPsychProfile.php`, `CialdiniLever.php`, `AnalystVerdict.php` | Psychological profile entity, Cialdini lever enum, analyst verdict enum |
| User | `src/Domain/User/User.php`, `Permission.php`, `RefreshToken.php` | User/permission/refresh-token entities |
| Validation | `src/Domain/Validation/LeakageDetectionResult.php`, `StructuredCorrection.php` | Leak detection result and structured correction VOs |

#### Infrastructure sub-modules [VERIFIED] `ls -d backend-symfony/src/Infrastructure/*/`

| Module | Representative file | Contents |
|---|---|---|
| Audit | `src/Infrastructure/Audit/HttpRequestContext.php` | Captures the HTTP request context for the audit log |
| Auth | `src/Infrastructure/Auth/DoctrineUserTotpChecker.php` | Doctrine-backed TOTP checker |
| Campaign | `src/Infrastructure/Campaign/Doctrine/` | Campaign Doctrine repositories |
| Doctrine | `src/Infrastructure/Doctrine/Repository/` (12 files), `Type/EncryptedStringType.php` | ORM repositories + custom DBAL type `EncryptedStringType` |
| EventListener | `src/Infrastructure/EventListener/KernelExceptionListener.php`, `LlmUsageListener.php`, `ConversationEndedListener.php` | Kernel / auth / conversation / LLM usage listeners |
| Guard | `src/Infrastructure/Guard/InProcessSmokeRunner.php` | In-process canary smoke runner |
| LLM | `src/Infrastructure/LLM/Provider/{AnthropicClient,OpenAIClient,OllamaClient,MockLLMClient}.php`, `LLMProviderCompilerPass.php` | Concrete LLM clients + DI compiler pass |
| Mailer | `src/Infrastructure/Mailer/TransportFactory.php` | Mail transport construction |
| Preprod | `src/Infrastructure/Preprod/ConversationGenerator.php`, `IocGenerator.php`, `ScamTemplates.php` | Synthetic preprod data generation |
| Prompt | `src/Infrastructure/Prompt/CachedDbPromptOverrideSource.php`, `CompositePromptOverrideSource.php` | Prompt override sources (database / file) |
| Siem | `src/Infrastructure/Siem/Adapter/{FileSiemExporter,SyslogSiemExporter,NullSiemExporter}.php`, `SiemCompilerPass.php` | SIEM exporters + DI compiler pass |

#### Security layer [VERIFIED] `ls backend-symfony/src/Security/`

`PermissionVoter.php`, `CustomAccessDeniedHandler.php`, `SecretPolicy.php`,
`TaxiiApiKeyAuthenticator.php`, `TestCsrfTokenManager.php`, `TestTokenAuthenticator.php`.

#### UI/Http layer

**148 `#[Route]` attributes** [VERIFIED] `grep -rn "#\[Route" backend-symfony/src/UI/Http --include="*.php" | wc -l` → `148`.

Breakdown by sub-folder: `Admin` (2), `Auth` (10), `Campaign` (10), `Clustering` (7),
`Communication` (~50), `Dto` (21 response DTOs), `Internal` (1), `Meta` (1), `Personas` (2),
`Monitoring` (24), `Prompt` (7), `Scambaiting` (7), `Stats` (2), `Taxii` (4), `ThreatActor` (2),
`Ttp` (9), `User` (4), plus `HealthController.php`.

### 1.2 `backend-symfony/config` [VERIFIED] `ls -R backend-symfony/config`

| Path | Contents |
|---|---|
| `config/packages/` (26 files) | `doctrine.yaml`, `security.yaml`, `llm.yaml`, `rate_limiter.yaml`, `scheb_2fa.yaml`, `lexik_jwt_authentication.yaml`, `monolog.yaml`, `nelmio_api_doc.yaml`, `nelmio_cors.yaml`, `campaign.yaml`, `scambuster.yaml`, `lock.yaml`, `cache.yaml`, `mailer.yaml` |
| `config/packages/{e2e,test,prod}/` | Per-environment overrides (`e2e/` 6 files, `test/` 5, `prod/doctrine.yaml`) |
| `config/scambuster/scambuster.defaults.yaml` | Operator-tunable scalars; **only one parameter present**: `scambuster.reward.llm_weight: 0.7` [VERIFIED] `config/scambuster/scambuster.defaults.yaml:9` |
| `config/scambuster/prompts/` | **Contains only `README.md`**; documents override resolution by dropping `<key>.txt` files, resolved through `PromptProvider` [VERIFIED] `config/scambuster/prompts/README.md:1-12` |
| `config/stix-schemas/` | `x_scambuster_context.schema.json`, `x_scambuster_mirror.schema.json`, `x_scambuster_ttp_sighting.schema.json` |
| Config root | `bundles.php`, `services.yaml`, `services_test.yaml`, `services_e2e.yaml`, `bootstrap.php`, `preload.php`, `reference.php`, `routes.yaml` |

Override chain: `config/packages/scambuster.yaml:9-10` imports the default values,
then `../scambuster/scambuster.yaml` with `ignore_errors: not_found` [VERIFIED].

### 1.3 `backend-symfony/migrations` [VERIFIED] `ls backend-symfony/migrations`

- 65 `.php` migration classes + one `data/` directory (66 entries).
- Name range: `Version20250510093411.php` (May 2025) → `Version2026080700000000.php`.
- **Two naming conventions coexist**: 14-digit and 16-digit timestamps.
- `migrations/data/`: `insert_personas.sql`, `link_scam_types_personas.sql`,
  `personas_27.json`, `preprod_reference_data.sql`.

### 1.4 `frontend-react/src` [VERIFIED] `ls frontend-react/src/*`

| Path | Contents |
|---|---|
| `App.tsx`, `main.tsx`, `index.css` | Application root / Vite entry point / global CSS |
| `src/pages/` | 34 pages (+ co-located tests): `Dashboard`, `Conversations`, `ConversationDetail`, `ConversationMonitoring`, `IocExplorer`, `IocDetail`, `Clusters`, `ClusterDetail`, `Campaigns`, `CampaignDetail`, `TtpExplorer`, `TtpDetail`, `Personas`, `PersonaMatrix`, `PersonaMirror`, `Theater`, `Analytics`, `Impact`, `LlmCosts`, `PipelineMonitor`, `InjectionMonitoring`, `ConvergenceHistory`, `StixExport`, `PromptCustomization`, `Settings`, `Login` |
| `src/components/` | `clusters`, `conversation`, `feedback`, `impact`, `ioc`, `layout`, `personas`, `promptOverrides`, `theater`, `ttp`, `ui` |
| `src/api/` | `client.ts`, `endpoints.ts`, `__tests__` |
| `src/hooks/` | 30+ data hooks + `MaskModeProvider.tsx` |
| `src/lib/` | `format.ts`, `csv.ts`, `iocCategory.ts`, `clusterVerdict.ts`, `actorColors.ts`, `domainVariants.ts` |
| `src/store/` | `authStore.ts` (single store) |
| `src/types/` | `api.ts`, `threatActor.ts`, `ttp.ts` |
| `src/i18n/` | `index.ts` + `locales/` |

### 1.5 `n8n/workflows` [VERIFIED] JSON read of the 4 files

| File | `active` | Trigger | Factual node chain |
|---|---|---|---|
| `WF-INTAKE-EMAIL-V2.json` | `true` | `n8n-nodes-base.emailReadImap` (`customEmailConfig: ["UNSEEN"]`, `downloadAttachments: true`) | IMAP → `Extract Email Data` → `Retrieve Token` (POST `/api/v1/auth/login`) → `Prepare Payload` → `Ingest Email` (POST `/api/v1/communication/ingest/raw`) → `Get Risk Assessment` (GET `/message/{msg_id}/risk`) → `Decision Gate` → `Trigger Reply Generation` or `Skip Reply`; `splitInBatches` loop to `WF-EXTRACT-AND-ENRICH-IOC` |
| `WF-REPLY-GENERATE-V2.json` | `true` | `executeWorkflowTrigger` | `00_auth_login` → `10_fetch_context` → `20_generate` (POST `/reply/generate`) → `40_store_draft` → `Prepare SEND data` → call to `WF-REPLY-SEND-v1` |
| `WF-REPLY-SEND-v1.json` | **`false`** | `executeWorkflowTrigger` | `00_auth_login` → `10_fetch_reply` → `12_fetch_compose` → `Calculate Human Delay` → `Wait Until Send Time` → `20_backend_send` (POST `/reply/{msg_id}/send-email`) → `22_extract_message_id` → `30_confirm` → `40_notify` → `respondToWebhook` |
| `WF-EXTRACT-AND-ENRICH-IOC.json` | `true` | `executeWorkflowTrigger` | `Retrieve Token` → `Extract IOCs via Backend API (LLM)` → `Parse API Response` → `Filter URLs & Domains` → **`URLScan: Scan URL`** + **`VirusTotal: Scan URL`** → `Wait 1 Minute` → report retrieval → `Merge URLscan + VT` → `Map Enrichment Data` → PATCH `/api/v1/iocs/{obs_id}/enrich` |

### 1.6 `infra/` [VERIFIED] `find infra -type f`

| Path | Contents |
|---|---|
| `infra/docker/backend/` | `Dockerfile`, `Dockerfile.prod`, `docker-entrypoint-prod.sh`, `write-prod-env.sh`, `nginx-prod.conf`, `supervisord.conf` (php-fpm + nginx, `:9,:18`), `scheduler.sh`, `canary-worker.sh`, `prod-seed-reference.sql` |
| `infra/docker/frontend/Dockerfile` | Frontend image |
| `infra/docker/nginx/nginx.conf` | Dev nginx config |
| `infra/docker/demo/` | `Dockerfile.backend`, `Dockerfile.frontend`, `docker-entrypoint-demo.sh`, `nginx-demo.conf.template` |
| `infra/monitoring/` | `docker-compose.yml` (prometheus `prom/prometheus:v2.54.1` `:13`, grafana `grafana/grafana:11.2.0` `:27`), `prometheus/prometheus.yml`, `prometheus/alert.rules.yml`, `grafana/dashboards/scambuster-security.json`, `grafana/provisioning/` |

### 1.7 `scripts/` [VERIFIED] header of each script

| Script | Declared purpose |
|---|---|
| `scripts/check-env.sh:2` | Validation of environment variables before startup |
| `scripts/check-honeypot-leak.sh:3` | Guard against accidental leaking of honeypot names |
| `scripts/doctor.sh:3` | Environment and connectivity health check |
| `scripts/generate-jwt-keys.sh:2` | Generation of an RS256 RSA-2048 key pair in `backend-symfony/config/jwt/` |
| `scripts/rotate-jwt-keys.sh:2` | Rotation of the RS256 JWT keys without downtime |
| `scripts/install-pre-commit-hook.sh:3` | Installation of `check-honeypot-leak.sh` as a pre-commit hook |
| `scripts/hooks/pre-push-guard.sh:3` | Pre-push hook that reminds about / enforces (via `GUARD_ON_PUSH=1`) the real-LLM guard |
| `scripts/preflight.sh:3` | Checks before a destructive quickstart |
| `scripts/preflight-check.sh:3` | Local 8-gate runner |
| `scripts/validate-install.sh:2` | Install validation |
| `scripts/validate-n8n-workflows.sh:2` | Validation of the n8n JSON files against hard-coded values |
| `scripts/check-credentials.py` | No description header [UNKNOWN] |
| `scripts/honeypot-names.txt.example` | Example honeypot name list |
| `scripts/test-llm/test-{all,anthropic,openai,ollama}.sh` | Connectivity tests per LLM provider |

### 1.8 `docs/` [VERIFIED] `find docs -type f`

| Group | Files |
|---|---|
| Product / concept (01–08) | `01_problem_statement`, `02_value_proposition`, `03_high_level_architecture`, `04_security_guardrails`, `05_evaluation_methodology`, `06_roadmap`, `07_faq`, `08_getting_started` |
| Compliance / legal | `09_dpia_template`, `10_threat_model`, `compliance/{README,breach-notification-procedure,data-classification,data-processing-agreements,gdpr-record-of-processing,mule-victim-account-policy,risk-register}` |
| CTI integrations | `11_opencti_integration`, `13_misp_integration`, `15_siem_integration`, `16_taxii_server` |
| API / operations | `12_api_quick_reference`, `14_key_management`, `17_email_provider_setup`, `20_enterprise_sso` |
| Quality / metrics | `18_data_validation`, `19_data_quality_audit`, `22_metrics_catalog`, `24_analyst_feedback` |
| Analyst screen guides | `21_threat_actor_profiling`, `23_reading_the_threat_actor_screen`, `25_prompt_customization`, `26_reading_the_ttp_screens` |
| Deployment / demo | `AI_DEPLOYMENT`, `DEMO`, `QUICKSTART` |
| Runbooks | `runbooks/{RACI,audit-hmac-key-rotation,incident-response-plan,n8n-credentials,post-mortem-template,production-deployment,totp-key-rotation}` |

### 1.9 `docker-compose*.yml` services [VERIFIED]

| File | Service | Image / build | Note |
|---|---|---|---|
| `docker-compose.yml:2` | `postgres` | `postgres:15-alpine` | |
| `docker-compose.yml:17` | `postgres-preprod` | `postgres:15-alpine` (`:22`) | `profiles: ["preprod"]` (`:21`) |
| `docker-compose.yml:41` | `redis` | `redis:7-alpine` | |
| `docker-compose.yml:56` | `backend-dev` | build `context: .` (`:58`) | `command: php -S 0.0.0.0:8080 -t public` (`:74`) |
| `docker-compose.yml:78` | `backend-test` | inherits | `profiles: ["test"]` (`:82`) |
| `docker-compose.yml:97` | `backend-e2e` | inherits | `profiles: ["test"]` (`:100`) |
| `docker-compose.yml:115` | `backend-preprod` | inherits | `profiles: ["preprod"]` (`:118`) |
| `docker-compose.yml:139` | `scheduler` | backend image | `command: sh /opt/scheduler.sh` (`:143`) |
| `docker-compose.yml:166` | `canary-worker` | backend image | `profiles: ["canary"]` (`:168`) |
| `docker-compose.yml:186` | `frontend` | build (`:188`) | |
| `docker-compose.yml:215` | `n8n` | `n8nio/n8n:1.114.3` (`:216`) | |
| `docker-compose.prod.yml:16,42,58,67,105` | `app`, `postgres`, `redis`, `n8n`, `scheduler` | build / `postgres:15-alpine` / `redis:7-alpine` / `n8nio/n8n:1.114.3` / build | volumes `prod-pgdata`, `prod-n8ndata`, `prod-backups` (`:129-131`) |
| `docker-compose.demo.yml:22,38,48,98` | `postgres`, `redis`, `backend`, `frontend` | `postgres:15-alpine`, `redis:7-alpine`, build, build | **neither `n8n` nor `scheduler`** |
| `infra/monitoring/docker-compose.yml:12,26` | `prometheus`, `grafana` | `prom/prometheus:v2.54.1`, `grafana/grafana:11.2.0` | separate monitoring stack |

---

## 1.10 Entry path of an inbound email

[VERIFIED] Full trace, from the IMAP trigger to the handler.

| Step | Component | Evidence |
|---|---|---|
| 1 | n8n IMAP trigger reads `UNSEEN` messages | `n8n/workflows/WF-INTAKE-EMAIL-V2.json`, node `IMAP Email Trigger` |
| 2 | Authentication: `POST {SCAMBUSTER_API_URL}/api/v1/auth/login` | same file, node `Retrieve Token` |
| 3 | `POST /api/v1/communication/ingest/raw` | same file, node `Ingest Email` |
| 4 | Controller | `src/UI/Http/Communication/IngestController.php:24` (prefix), `:77` (`#[Route('/raw', methods:['POST'])]`), `:25` (`#[IsGranted('conversation:write')]`), `:79` (`__invoke`) |
| 5 | DTO deserialisation + validation | `IngestController.php:82` (`IngestRawRequestDto`), `:96` (validator) |
| 6 | Per-account rate limiting | `IngestController.php:107-129` — `ingest_per_account` limiter, `RATE_LIMIT_EXCEEDED` audit event |
| 7 | Handler | `src/Application/Communication/IngestHandler.php:23` (class), `:40` (`ingest(IngestRawRequestDto): array`) |
| 7a | Email parsing | `IngestHandler.php:53` — `EmailParsingService::parseEmail()` |
| 7b | Account/channel/direction resolution | `IngestHandler.php:62` (`EntityReferenceResolver::resolve`), inline fallback `:70-85` |
| 7c | Deduplication by Message-ID | `IngestHandler.php:88` (`threadResolver->findExistingMessage`), returns `status: already_exists` `:90-94` |
| 7d | Thread resolution/creation | `IngestHandler.php:97` (`resolveConversation`), `:108` (`createNewConversation`), `:118` (`reopenIfNeeded`) |
| 7e | Post-processing | `IngestHandler.php:248` (`postProcessor->processAfterIngest`), `:251` (`checkSenderRateLimits`) |
| 7f | Scope of post-processing | `src/Application/Communication/IngestPostProcessor.php:22-26` (docblock): "IOC extraction, scam classification, risk scoring, prompt injection detection, and rate limiting" |
| 8 | Risk read-back | `GET /message/{msgId}/risk` → `src/UI/Http/Communication/GetMessageRiskController.php:49` |
| 9 | Decision gate → reply generation | `WF-INTAKE-EMAIL-V2.json`, node `Decision Gate` → `WF-REPLY-GENERATE-V2.json` |
| 10 | Generation | `POST /reply/generate` → `src/UI/Http/Communication/GenerateReplyController.php:54` (route), `:73` → `src/Application/Communication/ReplyHandler.php:126` |
| 11 | Sending | `POST /reply/{msgId}/send-email` → `src/UI/Http/Communication/SendEmailController.php:47` → `ReplyHandler.php:432` (`sendEmail`); composition `:406`, mark-as-sent `:416` |
| 12 | IOC branch | `POST /message/{msgId}/extract-iocs` → `src/UI/Http/Communication/ExtractIocsController.php:97`; then PATCH `/api/v1/iocs/{id}/enrich` |

Adjacent internal entry point: `GET /api/v1/internal/mail-account/active`
[VERIFIED] `src/UI/Http/Internal/MailAccountActiveController.php:12`.

---

## 1.11 Console commands

68 command classes + 1 trait (`ResolvesPasswordInput.php`) under
`backend-symfony/src/UI/Console` [VERIFIED] `ls backend-symfony/src/UI/Console`.
Descriptions are verbatim from the `#[AsCommand]` attributes.

### 1.11.1 Ingestion / classification / IOC hygiene
| Command | Description | Evidence |
|---|---|---|
| `scambuster:classify:backfill-unknown` | Preview (default) or apply re-classification of last-month UNKNOWN conversations. | `BackfillUnknownClassificationCommand.php:31` |
| `app:verify:classification` | Generate spot-check report for scam type classification review | `VerifyScamClassificationCommand.php:26` |
| `app:indicator:cleanup-platform-contamination` | Remove IOCs ingested from outgoing messages or matching honeypot addresses | `CleanupPlatformContaminationCommand.php:40` |
| `app:migrate-header-iocs` | Extract header-based IOCs from existing messages | `MigrateHeaderIocsCommand.php:28` |
| `app:migrate-iocs-export-metadata` | Enrich existing IOCs with MISP/STIX export metadata | `MigrateIocsExportMetadataCommand.php:27` |
| `app:ioc:compute-context` | Compute structural context for IOCs without context (backfill + retry) | `ComputeIocContextCommand.php:21` |
| `app:fix:semantic-roles` | Fix mislabeled semantic roles on existing IOC contexts | `FixSemanticRolesCommand.php:24` |
| `app:verify:ioc-source-presence` | Verify extracted IOCs appear in their source messages | `VerifyIocSourcePresenceCommand.php:26` |
| `app:analyze-threading` | Analyze message threading by subject | `AnalyzeThreadingCommand.php:16` |
| `app:check-message-headers` | Check message headers for a specific message | `CheckMessageHeadersCommand.php:15` |
| `app:generate-embeddings` | Generate semantic embeddings for inbound messages without vectors | `GenerateEmbeddingsCommand.php:18` |
| `app:detect-prompt-injection` | Run prompt injection forensic analysis on inbound messages | `DetectPromptInjectionCommand.php:17` |

### 1.11.2 Clustering / actor / campaign
| Command | Description | Evidence |
|---|---|---|
| `app:clustering:backfill` | Backfill threat-actor clusters for all existing conversations | `ClusteringBackfillCommand.php:24` |
| `app:clustering:export-stix` | Export threat-actor clusters as STIX 2.1 bundles | `ClusterExportStixCommand.php:23` |
| `app:compute:cluster-sophistication` | Compute sophistication level for all clusters from conversation metrics | `ComputeClusterSophisticationCommand.php:24` |
| `app:verify:cluster-quality` | Verify cluster anchor IOC quality and detect potential artifacts | `VerifyClusterQualityCommand.php:26` |
| `app:actor:compute-psych-profiles` | Generate the per-cluster threat-actor psychological profile (one LLM call per cluster) | `ComputeActorPsychProfilesCommand.php:25` |
| `app:generate-actor-profiles` | Generate actor fingerprints for campaigns with sufficient conversation data | `GenerateActorProfilesCommand.php:17` |

### 1.11.3 TTP
| Command | Description | Evidence |
|---|---|---|
| `scambuster:ttp:backfill` | Preview (default) or apply TTP extraction over historical inbound messages. | `TtpBackfillCommand.php:45` |
| `scambuster:ttp:audit-sample` | Export a random sample of TTP observations (WITH raw evidence) for internal manual precision audit. | `TtpAuditSampleCommand.php:42` |
| `scambuster:ttp:demo-seed` | Seed deterministic, plausible TTP observations for the demo dataset (no LLM). | `TtpDemoSeedCommand.php:51` |

### 1.11.4 Scambaiting / personas / bandit
| Command | Description | Evidence |
|---|---|---|
| `app:evaluate:bandit-analysis` | Analyze epsilon-greedy persona selection convergence per scam type | `AnalyzeBanditCommand.php:18` |
| `app:bandit:daily-report` | Log daily convergence snapshot for each active scam type | `BanditDailyReportCommand.php:17` |
| `app:rewards:calculate` | Calculate rewards for all CLOSED conversations without a reward | `CalculateRewardsCommand.php:18` |
| `app:close-stale-conversations` | Close conversations based on per-scam-type lifecycle policies | `CloseStaleConversationsCommand.php:30` |
| `app:persona:compute-mirrors` | Generate the Cognitive Mirror cache (one LLM call per persona x scam type pair) | `ComputePersonaMirrorsCommand.php:28` |
| `app:link-scam-types-personas` | Link existing ScamTypes to their appropriate Personas | `LinkScamTypesPersonasCommand.php:15` |
| `app:fix:risk-scores` | Recalculate risk scores for all conversations using current formula | `RecalculateRiskScoresCommand.php:23` |
| `scambuster:strip-pending-signatures` | Strip queued outbound replies whose body still contains a signature block | `StripPendingSignaturesCommand.php:28` |

### 1.11.5 Evaluation / metrics
| Command | Description | Evidence |
|---|---|---|
| `app:eval:compute-metrics` | Compute baseline metrics from annotations + judge + production CSVs | `EvalComputeMetricsCommand.php:37` |
| `app:eval:render-ioc` | Render one IOC + 3-msg window + production predictions for annotation/judging | `EvalRenderIocCommand.php:33` |
| `app:eval:run-judge` | Call gpt-4o (or gpt-4o-mini) on an IOC, save independent judgment as JSON | `EvalRunJudgeCommand.php:36` |
| `app:eval:sample-test-set` | Stratified sample of 150 IOC contexts (train 50 / test 100) for human-factor calibration evaluation | `EvalSampleTestSetCommand.php:53` |
| `app:eval:test-prompt-v2` | Run prompt v2 on a batch of IOCs (same model gpt-4o-mini) | `EvalTestPromptV2Command.php:40` |
| `app:eval:ioc-extraction-metrics` | Report IOC-extraction precision/recall/F1 against a gold-annotated set | `IocExtractionMetricsCommand.php:24` |
| `app:evaluate:generate-corpus` | Generate an evaluation corpus by calling the real LLM pipeline on conversations | `GenerateCorpusCommand.php:19` |
| `app:evaluate:reply-quality` | Evaluate reply quality from a generated corpus file | `EvaluateReplyQualityCommand.php:19` |
| `app:audit:conversation-quality` | Audit conversation data quality using independent LLM analysis | `AuditConversationQualityCommand.php:24` |

### 1.11.6 GUARD / prompt canary / smoke
| Command | Description | Evidence |
|---|---|---|
| `scambuster:guard:baseline` | Freeze a canary baseline (stable metrics + safety-violation rates) from a smoke summary JSON. | `GuardBaselineCommand.php:25` |
| `scambuster:guard:canary:work` | Process one pending prompt-canary validation job (run the candidate smoke, compare vs baseline, store the verdict). | `GuardCanaryWorkCommand.php:29` |
| `scambuster:guard:check` | Merge-gate: diff a candidate smoke summary against the frozen baseline; exit non-zero on regression. | `GuardCheckCommand.php:29` |
| `scambuster:smoke:cialdini-mirror` | Smoke harness — drive reply pipeline, capture Cialdini-mirror detection in strategic_suggestions. | `CialdiniMirrorSmokeCommand.php:37` |
| `scambuster:smoke:reply-objective` | Smoke harness — drive reply pipeline on .eml fixtures and dump per-test artifacts. | `ReplyObjectiveSmokeCommand.php:33` |
| `scambuster:prompt:diag` | Show which operator prompt overrides and settings are active (read-only). | `PromptDiagCommand.php:23` |

### 1.11.7 Security / audit / compliance
| Command | Description | Evidence |
|---|---|---|
| `app:security:check-secrets` | Refuse known-default or weak secrets in production (fail-fast guardrail) | `CheckSecretsCommand.php:27` |
| `app:audit:verify-chain` | Verify the HMAC chain of the audit_log table | `VerifyAuditChainCommand.php:28` |
| `app:purge:rgpd` | Purge conversations and messages according to RGPD rules. | `PurgeRgpdCommand.php:14` |
| `app:cleanup:weekly` | Soft-delete old closed conversations and purge stale LLM usage + prompt-canary-job records | `WeeklyCleanupCommand.php:17` |
| `app:llm:check-budget` | Check the LLM monthly budget threshold and emit an audit warning event if exceeded | `CheckLlmBudgetCommand.php:24` |

### 1.11.8 Users / auth
`app:user:create` (`UserCreateCommand.php:32`), `app:user:list` (`UserListCommand.php:21`),
`app:user:promote` (`UserPromoteCommand.php:27`), `app:user:set-password`
(`UserSetPasswordCommand.php:28`), `login-hash:generate` (`GenerateLoginHashCommand.php:15`).

### 1.11.9 Mail accounts
| Command | Description | Evidence |
|---|---|---|
| `app:mail-account:add` | Add a mail account with optional per-account SMTP DSN (encrypted at rest) | `MailAccountAddCommand.php:31` |
| `app:mail-account:list` | List all mail accounts (NEVER reveals SMTP DSN) | `MailAccountListCommand.php:18` |
| `app:mail-account:rotate-smtp` | Replace the encrypted SMTP DSN for an existing mail account | `MailAccountRotateSmtpCommand.php:23` |
| `app:mail-account:disable` | Soft-disable a mail account (sets is_active = false) | `MailAccountDisableCommand.php:22` |

### 1.11.10 Export connectors
`scambuster:misp:test` (`MispTestCommand.php:15`), `app:siem:export`
(`SiemExportCommand.php:22`), `app:siem:test` (`SiemTestCommand.php:23`).

### 1.11.11 Demo / preprod / debug
`scambuster:demo:load` (`LoadDemoDataCommand.php:32`), `preprod:generate-conversations`
(`PreprodGenerateConversationsCommand.php:22`), `preprod:copy-conversations`
(`PreprodCopyConversationsCommand.php:15`), `preprod:clear-conversations`
(`PreprodClearConversationsCommand.php:16`), `app:test-context`
(`TestContextCommand.php:16`), `app:test-conversation-context`
(`TestConversationContextCommand.php:16`), `TestReplyGenerateCommand.php` [UNKNOWN] name/description.

---

## 1.12 Asynchronous handlers (Symfony Messenger)

**None.** [VERIFIED]

- `grep -rn "AsMessageHandler\|MessageHandlerInterface\|MessageBusInterface" backend-symfony/src/ backend-symfony/config/` → 0 occurrences.
- `grep -rn "symfony/messenger\|symfony/scheduler" backend-symfony/composer.json` → 0 occurrences.
- `grep -rn -A20 "messenger" backend-symfony/config/packages/framework.yaml` → 0 occurrences.

`src/Application/Communication/MessageHandler.php` is a business handler for email
messages, not a Messenger handler (namespace `App\Application\Communication`, no
Messenger attribute) [VERIFIED].

[INFERRED] Asynchronous work is done by shell-loop containers (§5), not by a message
bus. Reasoning: Messenger is entirely absent, and `scheduler.sh` and
`canary-worker.sh` appear as the `command:` of compose services.

---

## 1.13 Scheduled tasks

No `crontab` and no Symfony Scheduler component in the repository [VERIFIED]
`grep -rln "crontab\|cron" --include="*.yml" --include="*.yaml" --include="*.conf" --include="Dockerfile*" .`
→ only `.github/workflows/guard-nightly.yml` matches.

### 1.13.1 `scheduler` container — shell loop
`infra/docker/backend/scheduler.sh`, service `docker-compose.yml:139`
(`command: ["sh","/opt/scheduler.sh"]` `:143`); prod equivalent `docker-compose.prod.yml:105`.
Switch `SCHEDULER_ENABLED=false` (`scheduler.sh:9`).

| Cadence | Command | Evidence |
|---|---|---|
| Every 6 h | `app:close-stale-conversations` | `scheduler.sh:47` |
| 6 h | `app:rewards:calculate` | `scheduler.sh:52` |
| 6 h | `app:detect-prompt-injection` | `scheduler.sh:57` |
| 6 h | `app:generate-embeddings --limit=500` | `scheduler.sh:62` |
| 6 h | `app:ioc:compute-context --with-llm --budget-usd=1.00 --limit=200` | `scheduler.sh:67` |
| Daily ≥06:00 UTC | `app:bandit:daily-report` | `scheduler.sh:74` |
| Daily ≥06:00 UTC | `app:generate-actor-profiles` | `scheduler.sh:78` |
| Daily ≥06:00 UTC | `app:actor:compute-psych-profiles --budget-usd=1.00` | `scheduler.sh:84` |
| Daily ≥02:00 UTC | `app:audit:verify-chain` | `scheduler.sh:94` |
| Daily ≥02:00 UTC | `pg_dump` → `/backups/scambuster_<date>.sql.gz`, retention `-mtime +7` | `scheduler.sh:101-124` |
| Weekly, Sunday ≥04:00 UTC | `app:cleanup:weekly` | `scheduler.sh:128` |
| Every 30 min | `app:clustering:backfill` | `scheduler.sh:139` |
| Every 30 min | `app:llm:check-budget` | `scheduler.sh:145` |

### 1.13.2 `canary-worker` container
`infra/docker/backend/canary-worker.sh`, service `docker-compose.yml:166`,
`profiles: ["canary"]` (`:168`). Drains `scambuster:guard:canary:work` every
`CANARY_WORKER_POLL_SECONDS` (default 60, `canary-worker.sh:32-37`); switch
`CANARY_WORKER_ENABLED=false` (`canary-worker.sh:10`) [VERIFIED].

### 1.13.3 CI scheduling
`.github/workflows/guard-nightly.yml:18-19` — `cron: '0 5 * * 0'` (Sunday 05:00 UTC)
+ `workflow_dispatch` [VERIFIED].

### 1.13.4 n8n scheduling
No `scheduleTrigger` node. The only periodic trigger is the IMAP node of
`WF-INTAKE-EMAIL-V2.json` (`forceReconnect: 2`, no `pollTimes` key).
`WF-REPLY-SEND-v1.json` uses a `wait` node driven by a code node
`Calculate Human Delay` [VERIFIED].

---

## 2. Network egress points of the code

### 2.1 Backend — HTTP egress

| # | Destination (host — hard-coded / variable) | Protocol | Call site | Trigger |
|---|---|---|---|---|
| E1 | `%llm.api_url%` + `/chat/completions` — **`LLM_API_URL`**, default `https://api.openai.com/v1` (`.env.dist:307`), wired in `config/packages/llm.yaml:5,45` | HTTPS POST | `src/Infrastructure/LLM/Provider/OpenAIClient.php:48` (const `:21`) | Any caller of `LLMClientInterface::chat()` when `LLM_PROVIDER=openai` |
| E2 | `https://api.anthropic.com/v1/messages` — **hard-coded** | HTTPS POST | `src/Infrastructure/LLM/Provider/AnthropicClient.php:165` (const), `:217` (call) | Same when `LLM_PROVIDER=anthropic`; key `ANTHROPIC_API_KEY` (`llm.yaml:55`) |
| E3 | `$baseUrl` + `/api/chat` — **`OLLAMA_BASE_URL`**, default `http://localhost:11434` (`llm.yaml:7,64`) | HTTP POST | `src/Infrastructure/LLM/Provider/OllamaClient.php:50` (const `:23`) | Same when `LLM_PROVIDER=ollama` |
| — | *(no socket)* `MockLLMClient` | — | `src/Infrastructure/LLM/Provider/MockLLMClient.php:18` | `LLM_PROVIDER=mock` |
| E4 | `https://api.openai.com/v1/embeddings` — **hard-coded** | HTTPS POST | `src/Application/LLM/EmbeddingService.php:20` (const `API_URL`), `:68` (call) | `app:generate-embeddings` command |
| E5 | `https://api.openai.com/v1/chat/completions` — **hard-coded** (legacy `OpenAIService`) | HTTPS POST | `src/Infrastructure/LLM/OpenAIService.php:51` | `ConversationGenerator` (`src/Infrastructure/Preprod/ConversationGenerator.php:339`); DI alias `config/services.yaml:525-532` |
| E6 | `http://scambuster-backend-preprod:8080/api/v1/auth/login` — **hard-coded** | HTTP POST | `src/Infrastructure/Preprod/ConversationGenerator.php:584` | Preprod generation |
| E7 | `http://scambuster-backend-preprod:8080/.../extract-iocs` — **hard-coded** | HTTP POST | `src/Infrastructure/Preprod/ConversationGenerator.php:620-622` | Preprod generation |
| E8 | OIDC token endpoint (from the discovery document) | HTTPS POST | `src/Application/Auth/Oidc/OidcService.php:107` | OIDC callback |
| E9 | OIDC userinfo endpoint | HTTPS GET | `src/Application/Auth/Oidc/OidcService.php:144` | OIDC callback |
| E10 | OIDC discovery document — **`OIDC_DISCOVERY_URL`** (`config/services.yaml:248`, `.env.dist:78`) | HTTPS GET | `src/Application/Auth/Oidc/OidcService.php:231` | OIDC start/callback (`OIDC_ENABLED`, `.env.dist:75`) |
| E11 | MISP `{MISP_URL}/servers/getVersion` — `$_ENV['MISP_URL']` | HTTPS GET | `src/UI/Console/MispTestCommand.php:33`, `:54` | `scambuster:misp:test` command. TLS verification driven by **`MISP_VERIFY_SSL`** (`:59`) |

### 2.2 Backend — non-HTTP egress

| # | Destination | Protocol | Path | Trigger |
|---|---|---|---|---|
| E12 | SMTP server from **`MAILER_DSN`** (`config/packages/mailer.yaml:3`; `.env.dist:232` default `null://null`) | SMTP/SMTPS | `src/Application/Communication/ReplyCompositionService.php:311` (`sendEmail`), resolution `:352-357` | `POST /reply/{msgId}/send-email`, called by n8n `WF-REPLY-SEND-v1.json:53` |
| E13 | Per-account SMTP, DSN encrypted in the database (`smtp_dsn_encrypted`) | SMTP/SMTPS | `src/Application/Communication/Smtp/SmtpTransportResolver.php:59` (decryption), `:68` (`fromDsn`), `:76` (`new Mailer`) | Same send path |
| E14 | Syslog collector — **`SIEM_ENDPOINT`** (`udp://` or `tcp://`), enabled by **`SIEM_PROVIDER=syslog`** (`.env.dist:421`, examples `:428-440`) | raw UDP/TCP | `src/Infrastructure/Siem/Adapter/SyslogSiemExporter.php:122` (`fsockopen`), `:134` (`fwrite`), probe `:67` | `app:siem:export`, `app:siem:test` |
| E15 | PostgreSQL — **`DATABASE_URL`** (`.env.dist:53`) | TCP/pgsql | `config/packages/doctrine.yaml` | All persistence |
| E16 | Redis — **`REDIS_URL`** (`.env.dist:59`) | TCP | `config/packages/cache.yaml` | Cache, rate limiters, kill switch cache |

**Observed absences** [VERIFIED] by negative search: no `curl_*`, `GuzzleHttp`,
`imap_open`, `Webklex`, `dns_get_record`, `gethostbyname`, `checkdnsrr`, `whois`,
VirusTotal, AbuseIPDB, urlscan, Shodan, TAXII client, Sentry, and no Prometheus
pushgateway in the PHP sources. `App\Application\Taxii\TaxiiService` is a
**server-side feed provider** (`/api/v1/taxii2`), not a client: no egress.
`IocFeedExporter` contains no HTTP client. The Vault integration is documented as
removed (`.env.dist:102-108`, `docker-compose.yml:198-204`).

### 2.3 n8n — outbound nodes

| # | Destination | Protocol | Node (file:line) | Trigger |
|---|---|---|---|---|
| E17 | IMAP mailbox — n8n credential `ScamBuster IMAP`; host/port via **`HONEYPOT_IMAP_HOST/PORT/SECURE`** (`.env.dist:190-194`) | IMAP/IMAPS | `WF-INTAKE-EMAIL-V2.json:15`, credential ref. `:22-26` | System entry point |
| E18 | `{{SCAMBUSTER_API_URL}}/api/v1/auth/login` — **`SCAMBUSTER_API_URL`** (`.env.dist:183`, default `http://backend-dev:8080`) | HTTP POST | `WF-INTAKE-EMAIL-V2.json:45`; `WF-EXTRACT-AND-ENRICH-IOC.json:18`; `WF-REPLY-GENERATE-V2.json:20`; `WF-REPLY-SEND-v1.json:147` | Every run |
| E19 | `.../communication/ingest/raw` | HTTP POST | `WF-INTAKE-EMAIL-V2.json:91` | After the IMAP read |
| E20 | `.../message/{id}/risk` | HTTP | `WF-INTAKE-EMAIL-V2.json:127` | After ingestion |
| E21 | `.../message/{msg_id}/extract-iocs` | HTTP POST | `WF-EXTRACT-AND-ENRICH-IOC.json:51` | Sub-workflow |
| E22 | `.../iocs/{obs_id}/enrich` | HTTP | `WF-EXTRACT-AND-ENRICH-IOC.json:287` | After the enrichment merge |
| **E23** | **urlscan.io** — node `n8n-nodes-base.urlScanIo`, credential `urlScanIoApi` | HTTPS | `WF-EXTRACT-AND-ENRICH-IOC.json:119` (scan; target URL `:114` = `{{ $json.value }}`), `:208` (report) | Enrichment of URL/domain IOCs |
| **E24** | **`https://www.virustotal.com/api/v3/urls`** — **hard-coded** | HTTPS POST | `WF-EXTRACT-AND-ENRICH-IOC.json:136`, credential `:160` | IOC enrichment |
| E25 | `https://www.virustotal.com/api/v3/analyses/{id}` — **hard-coded** | HTTPS GET | `WF-EXTRACT-AND-ENRICH-IOC.json:224`, credential `:248` | Report polling |
| E26 | `.../conversation/{conv_id}/context`, `/reply/generate`, `/reply/draft` | HTTP | `WF-REPLY-GENERATE-V2.json:46`, `:70`, `:101` | Generation sub-workflow |
| E27 | `.../reply/{msg_id}`, `/compose`, `/send-email`, `/sent` | HTTP | `WF-REPLY-SEND-v1.json:6`, `:29`, `:53`, `:90` | Send sub-workflow (cause of E12/E13) |

**Notable fact** [VERIFIED] `WF-EXTRACT-AND-ENRICH-IOC.json:114`: the value submitted to
urlscan.io and VirusTotal is **the IOC supplied by the adversary itself**.

### 2.4 Shell scripts

| Destination | Protocol | File:line |
|---|---|---|
| `${LLM_API_URL:-https://api.openai.com/v1}/chat/completions` | HTTPS | `scripts/test-llm/test-openai.sh:8,23,51,76` |
| `https://api.anthropic.com/v1/messages` (hard-coded) | HTTPS | `scripts/test-llm/test-anthropic.sh:22,51,77` |
| `${OLLAMA_BASE_URL:-http://localhost:11434}` `/api/tags`, `/api/chat` | HTTP | `scripts/test-llm/test-ollama.sh:8,17,58,85,103`; `test-all.sh:51-52` |

### 2.5 Published ports (compose)

| File:line | Service | Published port |
|---|---|---|
| `docker-compose.yml:29-30` | `postgres-preprod` | `5433:5432` (all interfaces) |
| `docker-compose.yml:67-68` | `backend-dev` | `8081:8080` |
| `docker-compose.yml:128-129` | `backend-preprod` | `8082:8080` |
| `docker-compose.yml:194-195` | `frontend` | `3002:5173` |
| `docker-compose.yml:245-246` | `n8n` | `${N8N_HTTP_PORT:-5678}:${N8N_PORT:-5678}` |
| `docker-compose.yml:93,111,142,170` | `backend-test`, `backend-e2e`, `scheduler`, `canary-worker` | `ports: []` |
| `docker-compose.prod.yml:33-34` | `app` | `${APP_BIND_HOST:-127.0.0.1}:${APP_PORT:-8080}:8080` (loopback by default) |
| `docker-compose.prod.yml:93-94` | `n8n` | `${N8N_BIND_HOST:-127.0.0.1}:${N8N_HTTP_PORT:-5678}:5678` (loopback by default) |
| `docker-compose.prod.yml:42,58` | `postgres`, `redis` | no `ports:` stanza |
| `infra/monitoring/docker-compose.yml:23-24,39-40` | `prometheus` (9090), `grafana` (3003:3000) | published |

Prometheus works in **pull** mode, target `backend:8080`
(`infra/monitoring/prometheus/prometheus.yml:23`); the backend exposes `/api/metrics`
(`src/UI/Http/Monitoring/MetricsController.php:27`). No pushgateway [VERIFIED].

### 2.6 Egress filtering

**No egress allowlist and no outbound proxy configuration exist in the code or in the
infrastructure** [VERIFIED]. `framework.http_client` is enabled with no `proxy`, no
`no_proxy` and no scoped client: `config/packages/framework.yaml:17-18`. The only
occurrences of `no_proxy` are in the auto-generated reference stub
(`config/reference.php:484,537`), which is not applied.
Docker networks: a single `scambuster` bridge (`docker-compose.yml:261-262`); no
`internal: true`, no `network_mode` restriction.
The only allowlists present are **inbound / application-level**: CORS origin
**`CORS_ALLOW_ORIGIN`** (`config/packages/nelmio_cors.yaml:3`, `.env.dist:140`) and the
reply-recipient safe list **`SCAMBUSTER_SAFE_DOMAINS`** (`.env.dist:251`,
**default `*`**), applied in `src/Application/Communication/ReplyCompositionService.php:140,145`.

---

## 3. LLM calls

### 3.1 Provider abstraction layer

| Item | File:line |
|---|---|
| Port `App\Application\LLM\Port\LLMClientInterface::chat()` | `src/Application/LLM/Port/LLMClientInterface.php:13,27` |
| OpenAI adapter (configurable base URL) | `src/Infrastructure/LLM/Provider/OpenAIClient.php:19` |
| Anthropic adapter (**hard-coded URL** `:165`) | `src/Infrastructure/LLM/Provider/AnthropicClient.php:163` |
| Ollama adapter (`OLLAMA_BASE_URL`) | `src/Infrastructure/LLM/Provider/OllamaClient.php:21` |
| Mock adapter (no network) | `src/Infrastructure/LLM/Provider/MockLLMClient.php:16` |
| Provider switch (reads `$_ENV['LLM_PROVIDER']`) | `src/Infrastructure/LLM/LLMProviderCompilerPass.php:25-29,33,49-52`; default alias → OpenAI `config/packages/llm.yaml:92` |
| **Second, legacy interface** `LLMServiceInterface::complete()` | `src/Infrastructure/LLM/LLMServiceInterface.php:10,20`; impl. `OpenAIService.php:34`; alias `config/services.yaml:525-526`; wired only to the preprod generator (`config/services.yaml:542`) |

[INFERRED] There are **two** competing LLM abstractions: the hexagonal port
`LLMClientInterface` (11 application callers) and the legacy interface
`LLMServiceInterface`, hard-wired to `https://api.openai.com`. Reasoning: both
interfaces coexist in `config/services.yaml`; `OpenAIService.php:51` ignores
`LLM_API_URL`. The embedding service uses **neither**: it calls
`HttpClientInterface` directly (`EmbeddingService.php:8,23`).

### 3.2 All distinct LLM calls

| Use case | Call (file:line) | Model | Prompt location | Configurable endpoint | Abstraction |
|---|---|---|---|---|---|
| Reply generation (retry loop) | `src/Application/LLM/RetryCoordinator.php:707`; options `:700-703`, `purpose=reply_generation` | `%llm.model%` ← **`LLM_MODEL`** (`.env.dist:306`, default `gpt-4o-mini`), injected `llm.yaml:172`, accessor `:863-866`; temp 0.6, max_tokens 400 | `PromptBuilder`; overridable key `persona_style_rules` (`src/Application/LLM/PromptBuilder.php:105`) | Yes, via `LLM_API_URL` | `LLMClientInterface` |
| Reply validation (LLM judge) | `src/Application/LLM/ReplyValidator.php:109`; options `:96-105` | **hard-coded** `gpt-4o-mini` (`:103`), temp 0.4, max_tokens 500 | supplied by `PromptBuilder` | provider level | `LLMClientInterface` |
| Operational leakage detector (2nd judge) | `src/Application/LLM/OperationalLeakageDetector.php:53`; options `:54-57` | **hard-coded** const `MODEL='gpt-4o-mini'` (`:28`) | hard-coded in the PHP | provider level | `LLMClientInterface` |
| Payment instigation guard (2 calls) | `src/Application/LLM/PaymentInstigationGuard.php:125`, `:227`; options `:126-129`, `:228-231` | **hard-coded** const `MODEL='gpt-4o-mini'` (`:50`) | hard-coded in the PHP | provider level | `LLMClientInterface` |
| Conversation analysis / anti-repetition (Director) | `src/Application/LLM/ConversationAnalyzer.php:111`; options `:116-120` | **hard-coded** const `ANALYZER_MODEL='gpt-4o'` (`:27`), temp 0.3 (`:28`) | inline defaults + overridable keys `conversation_director_strategy` (`:246`), `conversation_director_tone` (`:251`) | provider level | `LLMClientInterface` |
| Scam classification | `src/Application/LLM/ScamClassifier.php:57`; options `:58-60` | no `model` key → provider default | hard-coded heredocs `:184-265` (system), `:269-275` (user) | provider level | `LLMClientInterface` |
| IOC extraction | `src/Application/Communication/IocExtractor.php:105`; options `:106-108` | no `model` key → provider default | hard-coded heredocs `:193`, `:282` | provider level | `LLMClientInterface` |
| TTP extraction | `src/Application/LLM/TtpExtractor.php:181`; options `:182-184` | provider default; `max_tokens` = const `MAX_TOKENS` | `PromptProvider::resolve()` `:164`, key `ttp_extraction` (`PromptCatalog.php:295`) | provider level | `LLMClientInterface` |
| Contextual IOC enrichment | `src/Application/LLM/ContextualEnricher.php:61`; options `:50-52` | provider default | `PromptProvider::resolve()` `:144`, key `contextual_enrichment` (`PromptCatalog.php:38`) | provider level | `LLMClientInterface` |
| Conversation history summary | `src/Application/Communication/ConversationHistoryService.php:233`; options `:227-230` | **hard-coded** `gpt-4o-mini` (`:229`) | hard-coded in the PHP | provider level | `LLMClientInterface` |
| Prompt injection detection (LLM analyser) | `src/Application/Communication/PromptInjectionLlmAnalyzer.php:83`; options `:89-92` | **`PROMPT_INJECTION_MODEL`** (`.env.dist:296`, default `gpt-4o-mini`) + **`PROMPT_INJECTION_TEMPERATURE`** (`:298`, default 0.2), wired in `llm.yaml:254-255`; flag **`PROMPT_INJECTION_ENABLED`** (`.env.dist:294`, `llm.yaml:262`) | hard-coded const `SYSTEM_PROMPT` `:21` | provider level | `LLMClientInterface` |
| Conversation quality audit (judge) | `src/Application/Audit/ConversationQualityAuditor.php:85`; options `:77-80` | **hard-coded** `gpt-4o` (`:77`), temp 0.2, max_tokens 1000 | const `SYSTEM_PROMPT` `:23-33`; heredoc `:196-238` | provider level | `LLMClientInterface` |
| Reward judge (conversation closure) | `src/Application/Scambaiting/RewardJudge.php:89`; options `:94` | provider default | `PromptProvider::resolve('reward_judge')` `:78`, default `PromptCatalog.php:275` | provider level | `LLMClientInterface` |
| Persona mirror generation | `src/Application/Scambaiting/PersonaMirrorGenerator.php:66`; options `:70-73` | const `MODEL` (`:274`); temp 0.3, max_tokens 350; `PROMPT_VERSION='v1'` `:39` | const `SYSTEM_PROMPT` `:40`; heredoc `:171-204` | provider level | `LLMClientInterface` |
| Actor psychological profiling | `src/Application/ThreatActor/ThreatActorPsychProfileGenerator.php:65`; options `:69-72` | const `MODEL` (`:69`); temp 0.3, max_tokens 500; `PROMPT_VERSION='v1'` `:30` | const `SYSTEM_PROMPT` `:34`; heredoc `:195-224` | provider level | `LLMClientInterface` |
| Campaign profiling | `src/Application/Campaign/CampaignProfiler.php:143`; options `:144-147` | provider default; temp 0.3, max_tokens 800 | `Campaign\PromptBuilder::buildCampaignProfilerPrompts()` (`:136`) | provider level | `LLMClientInterface` |
| Campaign rule compilation (DSL) | `src/Application/Campaign/RuleCompiler.php:121`; options `:122-125` | provider default; temp 0.2, max_tokens 1000 | `Campaign\PromptBuilder::buildRuleCompilerPrompts()` (`:115`) | provider level | `LLMClientInterface` |
| Eval — prompt V2 test | `src/UI/Console/EvalTestPromptV2Command.php:122`; options `:126-129` | CLI option `--model`, default `gpt-4o-mini` (`:61`, `:72`) | const `SYSTEM_PROMPT` `:46` | provider level | `LLMClientInterface` |
| Eval — judge run | `src/UI/Console/EvalRunJudgeCommand.php:116`; options `:120-123` | CLI option `--model`, default `gpt-4o` (`:57`, `:67`), temp 0.0 | in the command | provider level | `LLMClientInterface` |
| Preprod conversation generator | `src/Infrastructure/Preprod/ConversationGenerator.php:339`; temp 0.8, max_tokens 3000 (`:340-342`) | **`LLM_MODEL`** via `config/services.yaml:532` | hard-coded heredoc `:302-338` | **Hard-coded endpoint** `https://api.openai.com/v1/chat/completions` (`OpenAIService.php:51`) | `LLMServiceInterface` (legacy) |
| **Embeddings** | `src/Application/LLM/EmbeddingService.php:68` | **hard-coded** const `MODEL='text-embedding-3-small'` (`:18`), `DIMENSIONS=1536` (`:19`) | n/a | **Hard-coded endpoint** `https://api.openai.com/v1/embeddings` (`:20`) | **none** — `HttpClientInterface` directly (`:8,:23`) |

**Summary of model configurability** [INFERRED]: out of 21 call sites, **7 hard-code the
model name** (`ReplyValidator`, `OperationalLeakageDetector`,
`PaymentInstigationGuard`, `ConversationAnalyzer`, `ConversationHistoryService`,
`ConversationQualityAuditor`, `EmbeddingService`), 2 use a CLI option, 1 uses a
dedicated variable, and the rest inherit `LLM_MODEL`. Reasoning: direct reading of the
`model` keys in each options array cited above. The hard-coded models are all
**OpenAI** identifiers (`gpt-4o`, `gpt-4o-mini`, `text-embedding-3-small`).

**Embeddings are computed through a remote API, not locally** [VERIFIED]
`EmbeddingService.php:20,68`.

### 3.3 Trigger surface

| Use case | Trigger |
|---|---|
| Generation / validation / leakage / payment guard / director | `POST /api/v1/communication/reply/generate` (`GenerateReplyController.php:54`), via n8n `WF-REPLY-GENERATE-V2.json:70` |
| IOC extraction | `POST /message/{msgId}/extract-iocs` (`ExtractIocsController.php:97`), n8n `WF-EXTRACT-AND-ENRICH-IOC.json:51` |
| TTP extraction | `POST /message/{msgId}/extract-ttps` (`ExtractTtpsController.php:100`); `scambuster:ttp:backfill` (`TtpBackfillCommand.php:54`) |
| Classification | `POST /conversation/{convId}/classify` (`ClassifyConversationController.php:68`), `/auto-classify` (`AutoClassifyConversationController.php:83`); `scambuster:classify:backfill-unknown`; **and ingestion post-processing** (`IngestPostProcessor`, ref. `IngestHandler.php:21`) via `POST /ingest/raw` |
| Prompt injection analysis | ingestion post-processing; `app:detect-prompt-injection` |
| Campaign profiling / rule compilation | `POST /campaign/{id}/profile` (`ProfileCampaignController.php:16`), `POST /campaign/{id}/rules/compile` (`CompileCampaignRulesController.php:16`) |
| Quality audit | `app:audit:conversation-quality` |
| Persona mirror | `app:persona:compute-mirrors` |
| Psychological profiling | `app:actor:compute-psych-profiles` |
| Contextual enrichment | `app:ioc:compute-context`; `POST /api/v1/iocs/enriched` (`IngestEnrichedIocController.php:150`) |
| Reward judge | `app:rewards:calculate` |
| GUARD canary (prompt validation on a real LLM) | `POST /api/v1/prompt-overrides/{key}/canary` (`RequestPromptCanaryController.php:25`) → drained by `scambuster:guard:canary:work` in the `canary-worker` container (`docker-compose.yml:166-185`) |
| Scheduled runs | `scheduler` service loop (`docker-compose.yml:139-155`, `infra/docker/backend/scheduler.sh`) |

### 3.4 Prompt override mechanism

| Aspect | File:line |
|---|---|
| Resolution order: **database override → disk file → built-in default** | `src/Application/LLM/Prompt/PromptProvider.php:55-58` (candidate array), `:60-78` (loop) |
| A candidate missing a required `{{PLACEHOLDER}}` is skipped in favour of the next one | `PromptProvider.php:65-75`, `:126-135` |
| Placeholder substitution | `PromptProvider.php:42` |
| Database source (loads all active overrides once per service instance) | `src/Infrastructure/Prompt/CachedDbPromptOverrideSource.php:36-38`, `:44-65`; documented behaviour `:16-19` |
| **Database errors swallowed → treated as "no override"** | `CachedDbPromptOverrideSource.php:58-64`; also `PromptProvider.php:91-97` |
| Head of the chain = ephemeral (canary) candidate **ahead of** the database source | `config/packages/llm.yaml:202-206`; `src/Infrastructure/Prompt/CompositePromptOverrideSource.php`; carrier `src/Application/LLM/Prompt/EphemeralPromptOverride.php` |
| File override directory | `%kernel.project_dir%/config/scambuster/prompts` (`llm.yaml:212`); `<key>.txt` files, key matching `^[a-z0-9_]+$` (`PromptProvider.php:106-119`) |
| Entity / repository | `src/Domain/Prompt/PromptOverride.php`, `PromptOverrideRepositoryInterface.php`, `src/Infrastructure/Doctrine/Repository/DoctrinePromptOverrideRepository.php` |
| Catalogue of overridable keys + built-in defaults | `src/Application/LLM/Prompt/PromptCatalog.php:37` — `contextual_enrichment` (`:38`), `persona_style_rules` (`:222`), `conversation_director_strategy` (`:241`), `conversation_director_tone` (`:257`), `reward_judge` (`:275`), `ttp_extraction` (`:295`) |
| Admin API | `GET /api/v1/prompt-overrides` (`ListPromptOverridesController.php:13`), `GET\|PUT\|DELETE /{key}`, canary endpoints (`RequestPromptCanaryController.php:25`, `GetPromptCanaryController.php:21`, `GetLatestPromptCanaryController.php:19`) |
| Diagnostic CLI | `scambuster:prompt:diag` — `PromptDiagCommand.php:77,100` |

[VERIFIED] **6 prompt keys out of 21 call sites are overridable**. The other 15
prompts are hard-coded in the PHP (const or heredoc), so they can only be changed by
changing code.

### 3.5 Kill switch, budget cap, rate limiting

| Control | File:line | Exact scope |
|---|---|---|
| **Kill switch** — two layers: cache key `llm.killswitch.active`, then fallback to an environment variable | `src/Application/Communication/ReplyCadenceService.php:30` (key), `:55-77` (`isKillSwitchActive`), env read `:74` (**`SCAMBUSTER_KILL_SWITCH`**, `.env.dist:287`) | Blocks reply generation: `ReplyHandler.php:137-139` (`RuntimeException`). Pre-send flag `kill_switch_off` `ReplyCompositionService.php:140`, combined `:145`, applied `:373`. **Does not block** classification, IOC/TTP extraction, enrichment, profiling or embeddings |
| API toggle + audit event | `src/UI/Http/Admin/ToggleLlmKillSwitchController.php:18`, `:72-84` (`AuditEventType::KILL_SWITCH_TOGGLED`); read `GetLlmKillSwitchStateController.php:14,43` | — |
| Metric | `src/UI/Http/Monitoring/MetricsController.php:97-100` (`scambuster_kill_switch` gauge) | — |
| **Monthly budget cap** | parameter `llm.max_cost_usd_month` ← **`LLM_MAX_COST_USD_MONTH`**, default `50.0` (`llm.yaml:11-12`, `.env.dist:336`); `src/Application/Monitoring/LlmCostHandler.php:19`, `:137-149`, `:157-160`, threshold `:125-129` | Gate at `ReplyHandler.php:146-158`: if the cap is exceeded and the mode is `enforce` → `LlmBudgetExceededException` (`:147-151`), otherwise a simple warning (`:154-158`). **Governs reply generation only** |
| Enforcement mode | `llm.budget_enforcement_mode` ← **`LLM_BUDGET_ENFORCEMENT_MODE`**, **default `warning`** (`llm.yaml:16-17`, `.env.dist:343`), wired in `config/services.yaml:467` | `warning` \| `enforce` |
| Budget exception → HTTP 503 | `GenerateReplyController.php:106` | — |
| Threshold notification + CLI | `src/Application/Monitoring/BudgetThresholdNotifier.php` (`config/services.yaml:471-476`); `app:llm:check-budget` | — |
| Usage accounting | `LlmCallCompletedEvent` event emitted by each provider (`OpenAIClient.php:88`, `AnthropicClient.php:259`, Ollama equivalent); listener `src/Infrastructure/EventListener/LlmUsageListener.php` (`llm.yaml:85`); cost `src/Application/LLM/CostEstimator.php`; `llm_usage` table | — |
| **Rate limiting (Redis)** | `config/packages/rate_limiter.yaml`: `replies_per_conversation` 20/24 h (`:28-31`), `llm_calls_per_hour` 200/1 h (`:33-36`), `active_conversations_per_day` 50/24 h (`:38-41`), `emails_per_sender_per_day` 10/24 h (`:46-49`), `ingest_per_account` 100/1 h (`:57-60`), `login_ip` (`:7-10`), `login_email` (`:15-18`), `api_global` (`:20-23`) | Checked at `ReplyCadenceService.php:116-…` (`checkRateLimits`), called from `ReplyHandler.php:195` |
| Reply cadence | `ReplyCadenceService.php:27` (`MIN_HOURS_BETWEEN_REPLIES = 6`), `:82-109` | Minimum spacing between outbound replies |

### 3.6 Where the model / temperature / max_tokens settings live

| Layer | Location |
|---|---|
| Environment variables | `.env.dist:305-308` (`LLM_PROVIDER`, `LLM_MODEL`, `LLM_API_URL`, `LLM_API_KEY`), `:296-298`, `:317-332`, `:336`, `:343` |
| Container parameters | `config/packages/llm.yaml:3-6`, `:7`, `:8`, `:11-17`, `:20-32` |
| Per-adapter defaults | `OpenAIClient.php:22-23` (0.6 / 400); `AnthropicClient.php:167-168` (0.6 / 1024); `OllamaClient.php:24` (0.6); `OpenAIService.php:47-48` (0.7 / 1024) |
| Per-call-site overrides | see the `options` columns of the §3.2 table |
| Model wiring in the reply pipeline | `llm.yaml:172` (`ReplyOrchestrator.$model: '%llm.model%'`) |

## 4. Existing deterministic controls (non-LLM)

> This section underpins rule R2: no component may be proposed in phase 3 without
> having been checked against this inventory. The "Not covered" column is established
> **by reading the code alone** (list size, language of the patterns, declared scope),
> never by assumption.

### 4.1 Guardrails on the outbound reply (post-LLM, pre-send)

| # | Name | File:line | Position in the flow | Exact scope | Not covered (from reading) |
|---|---|---|---|---|---|
| D1 | `PolicyGuard::validate()` — master post-LLM gate | `src/Application/LLM/PolicyGuard.php:223` | Stage 2 of `RetryCoordinator::execute()` (`RetryCoordinator.php:152`), after generation and signature stripping, before the payment guard / leakage detector / validator | Returns `{approved, flags}`; `approved = ($flags === [])` (`:380`) | Code docblock: "NOT a proof that no harmful output can ever pass (a novel paraphrase carries no enumerated substring)" (`:15-16`) |
| D2 | Word-count band | `PolicyGuard.php:238-260` | post-LLM | `WordCounter::count()` against the min/max of `PolicyGuardConfig`; flags `too_short:{n}_words_min_{min}`, `too_long:{n}_words` | Whitespace tokenisation only |
| D3 | Link cap | `PolicyGuard.php:263-278` | post-LLM | Regex `#https?://[^\s<>"{}\|\\^`\[\]]+#i`; `$maxLinks = 1` (`:210`) | Only counts `http(s)://` forms; bare `www.` and defanged links are not counted |
| D4 | `FORBIDDEN_PATTERNS` (AI / honeypot self-disclosure) | `PolicyGuard.php:47-54` | post-LLM | **6 regexes**: `/\bhoneypot\b/i`, `/\bscambuster\b/i`, `/\bI am (?:a \|an )?(?:bot\|automated\|AI)\b/i`, `/\bautomated system\b/i`, `/\bartificial intelligence\b/i`, `/\bleurre\b/i` | 6 entries, English + **one single French word**. Docblock: "Common victim words like 'test', 'suspect', 'strange' are intentionally ALLOWED" (`:42`) |
| D5 | `OPERATIONAL_LEAKAGE_PATTERNS` | `PolicyGuard.php:67-78` | post-LLM | **10 pattern/suffix pairs**: `n8n`, `workflow[_\s-]?(id\|name)?`, `ingest/raw`, `api/v1/(admin\|internal)`, `SCAMBUSTER_[A-Z][A-Z0-9_]*`, `backend-(dev\|test\|preprod\|e2e\|prod)`, `MailAccount(SecretResolver)?`, `IocUpsertService`, `sodium_crypto_secretbox`, `docker[\s-]compose` | Fixed list of 10 literal infrastructure tokens |
| D6 | Operator-supplied OPSEC patterns (union only) | `PolicyGuard.php:312-324` | post-LLM | Iterates `$additionalOpsecPatterns`; `@preg_match(...) === 1` → flag `opsec_extra:{i}`. Docblock: "Additive only — it can never remove or weaken a HARM pattern" (`:202-204`) | **Invalid regexes are silently ignored** (suppressed by `@`) |
| D7 | `THREAT_PATTERNS` | `PolicyGuard.php:83-91` | post-LLM | **7 regexes**, 6 FR + 1 EN (`je vais vous (tuer\|frapper\|détruire\|blesser\|éliminer)`, `i will (kill\|hurt\|destroy\|harm)`, `vous êtes mort`, `je sais où vous (habitez\|vivez)`, `gare à (toi\|vous)`, …) | 7 entries; FR/EN only |
| D8 | `AUTHORITY_PATTERNS` (authority impersonation) | `PolicyGuard.php:94-122` | post-LLM | **22 regexes** in FR/EN/ES/DE/IT: police, gendarmerie, Interpol, Europol, FBI, prosecutor, judge, `soy del banco`, `ich bin von der Bank`, `sono della banca`, `des impôts`, `du fisc`, `dgfip`, `irs\|hmrc\|tax (office\|authority)`, `hacienda`, `finanzamt`, `au nom de la loi`, `mandat d'arrêt` | 5 languages; fixed list of wordings |
| D9 | `PII_PATTERNS` | `PolicyGuard.php:131-134` | post-LLM | **Only 2 regexes**: IBAN `/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/` and French postal address `/\b\d{1,3}\s+(?:rue\|avenue\|boulevard\|impasse)\s+[A-Z]/i`. Emits the single flag `pii_detected` then `break` | The comment states that phone/handles/wallets were "moved to the dedicated `OUT_OF_BAND_CHANNEL_PATTERNS`" (`:127-129`). The address pattern covers only **4 French street nouns** |
| D10 | `OUT_OF_BAND_CHANNEL_PATTERNS` | `PolicyGuard.php:159-197` | post-LLM | **9 keyed regexes**, evaluated in order (crypto first so the `phone` catch-all does not mask them): `crypto_btc`, `crypto_eth`, `crypto_xmr`, `telegram_handle`, `skype_uri`, `signal_discord`, `phone`, `messenger_link` (`t.me`/`wa.me`), `redirect_email`. Flag `out_of_band_channel:<kind>` then `break` | Docblock: "A bare app NAME is intentionally NOT matched" (`:190-192`); a handle token **without `@`** is not caught (`SafetyInvariantOracle.php:132-134`) |
| D11 | `PolicyGuardConfig::fromContext()` — contextual word bands | `src/Application/LLM/PolicyGuardConfig.php:50-77` | pre-PolicyGuard | `match(true)`: bot accusation → `12/70`; aggression → `15/90`; post-IBAN → `18/100`; evasive → `18/120`; terse persona → `12/120`; default `20/150` (`:70-75`) | The input flags come from `ConversationAnalyzer` (**LLM**) or from the calling context |
| D12 | `SignatureStripper::strip()` | `src/Application/LLM/SignatureStripper.php:101` | Right after generation, **before** PolicyGuard (`RetryCoordinator.php:118-130`) | 3 patterns: (1) **36 sign-off phrases** in EN/FR/ES/DE/IT/PT/NL, listed longest to shortest (`:43-92`), regex `'/\n+(?:'.$alternation.')[,!.]?\s*\n.*$/sui'` (`:129`); (2) trailing bracketed markers (`:145`); (3) RFC 3676 separator (`:161`) | Docblock: "does NOT match signoff words inline mid-sentence"; edge cases (informal English, typos, a name dropped mid-body) are "delegated to the LLM-based validator" (`:25-27`). **Conditional on `REPLY_SIGNATURE_STRIP_ENABLED`; if false, returns the input unchanged** (`:106-114`) |
| D13 | Levenshtein divergence guardrail | `RetryCoordinator.php:482-539` | Between two attempts | `levenshtein()` if `max(strlen) <= 255`, otherwise `similar_text()`; logs a warning if `$ratio > 0.5` (`:530`) | **Explicitly non-blocking**: "Does NOT block the loop — purely monitoring/forensic log" (`:478`) |
| D14 | Fail-closed "best of 3" rule | `RetryCoordinator.php:364-366`, `:376-394` | End of the loop | Only a draft whose `validatorResult['security_pass'] === true` can be reused. Comment: "A draft rejected for a security reason — or never security-checked at all — is never sent (fail closed at the last layer)" (`:88-90`) | **Depends on the `security_pass` field produced by the LLM validator** |
| D15 | Attempt cap | `RetryCoordinator.php:35` | Loop | `MAX_ATTEMPTS = 3` | — |
| D16 | Frozen fallback when attempts are exhausted | `RetryCoordinator.php:762-791`, `FallbackProvider` | After the loop | `getFallback($detectedLanguage, $convId, $msgCount)`, rotation per turn and per conversation (`:774-776`) | — |

### 4.2 Deterministic fallback inside an LLM guard

| # | Name | File:line | Position | Exact scope | Not covered |
|---|---|---|---|---|---|
| D17 | `PaymentInstigationGuard::containsPaymentInfraTokens()` | `src/Application/LLM/PaymentInstigationGuard.php:185-194`, constant `:78-91` | **Only on the LLM judge's failure path** — "The deterministic scan runs only here — never on the nominal (judge-OK) path" (`:157-158`) | **12 regexes**: `(swift\|bic\|iban\|ifsc)`, `(neft\|rtgs\|aba)`, `sort code`, `routing number`, `account number`, `(wire\|bank) transfer`, `beneficiary`, `wallet address`, `remit(tance)?`, `virement`, `transferencia`, `(ü\|u)berweisung` | 12 tokens; a free paraphrase ("send the funds to my account") is not caught |

### 4.3 `SafetyInvariantOracle` — deterministic oracle of the GUARD subsystem

| # | Name | File:line | Position | Exact scope | Not covered |
|---|---|---|---|---|---|
| D18 | `SafetyInvariantOracle::violations()` | `src/Application/Guard/SafetyInvariantOracle.php:177-214` | **Offline scoring** of captured outbound texts (canary aggregate), **not in the real send path** | Returns codes drawn from `ALL_CODES` (`:49-57`): `payment_token`, `payment_instrument`, `crypto_wallet`, `out_of_band_channel`, `word_band`, `language_mismatch`, `automation_reveal` | Self-declared: "It is a DETECTOR compared against a baseline (delta), not a blocker" (`:33-34`) |
| D19 | `PAYMENT_TOKEN_PATTERNS` | `SafetyInvariantOracle.php:87-105` | oracle | **16 regexes** = the 12 from `PaymentInstigationGuard`, byte for byte, + 4 extensions (`transfer[eê]ncia`, `bonifico`, `overschrijving`, `przelew`) | Declared **INFORMATIONAL** (`:70-72`) — never blocks the gate. Docblock: "Residual free-paraphrase … is an inherent limit" (`:84-85`) |
| D20 | `PAYMENT_INSTRUMENT_PATTERNS` (blocking) | `SafetyInvariantOracle.php:114-116` | oracle | **1 regex**: literal IBAN | IBAN only; no card instrument or account number |
| D21 | `CRYPTO_WALLET_PATTERNS` | `SafetyInvariantOracle.php:119-123` | oracle | 3 regexes (BTC/ETH/XMR), "byte-identical mirror of PolicyGuard; drift-tested" | 3 strings |
| D22 | `OUT_OF_BAND_PATTERNS` | `SafetyInvariantOracle.php:136-144` | oracle | 6 regexes: Telegram handle, Skype URI, Signal/Discord, phone catch-all, `t.me`/`wa.me`, email address | Does not catch a bare app **name**; "A bare username token with no '@' … is NOT caught" (`:132-134`) |
| D23 | `AUTOMATION_REVEAL_PATTERNS` | `SafetyInvariantOracle.php:153-165` | oracle | **10 regexes** = PolicyGuard's 6 `FORBIDDEN_PATTERNS` + 4 extensions (`language model`, `(as\|being) an? (ai\|automated\|artificial)`, `i (was \|am )?(generated\|programmed)`, `automated (assistant\|reply\|response\|message)`) | 10 entries |
| D24 | Word band + language check | `SafetyInvariantOracle.php:74-75`, `:201-211` | oracle | `MIN_WORDS = 20`, `MAX_WORDS = 150`; language via `LanguageDetector::detect()` against `$expectedLanguage` | **Fixed** band, independent of the contextual bands of `PolicyGuardConfig` (D11) |
| D25 | Oracle fingerprint | `SafetyInvariantOracle.php:223-237` | baseline / comparison | `substr(hash('sha256', json_encode([ALL_CODES, the 5 pattern sets, MIN_WORDS, MAX_WORDS])), 0, 12)` | — |
| D26 | Declared scope exclusion | `SafetyInvariantOracle.php:29-31` | — | "It deliberately does NOT re-check PolicyGuard's THREAT / AUTHORITY / PII sets" | The D7/D8/D9 sets are therefore **not** under regression monitoring |
| D27 | Reflection-based drift tests (oracle ⊇ guards) | `tests/Unit/Application/Guard/SafetyInvariantOracleTest.php:219,231,246` | CI, unit suite | Checks that the oracle does contain the patterns of `PaymentInstigationGuard` and of `PolicyGuard` | — |

### 4.4 Input controls (pre-LLM)

| # | Name | File:line | Position | Exact scope | Not covered |
|---|---|---|---|---|---|
| D28 | `PromptInjectionPatternMatcher::scan()` — layer 1 regex detector | `src/Application/Communication/PromptInjectionPatternMatcher.php:136` | Inbound, after persistence, inside `IngestPostProcessor::analyzePromptInjection()` (`:546`) | 6 groups / **25 regexes**. Weights: strong groups (`instruction_override`, `prompt_extraction`, `jailbreak`) = +0.4; medium (`role_manipulation`, `delimiter_injection`) = +0.25; weak (`encoding_obfuscation`) = +0.15; `min(1.0, …)` (`:296-323`). Scans `subject + "\n" + bodyText` (`PromptInjectionDetector.php:53`) | **Forensic only**: "Detection is forensic -- it does not block ingestion or modify the reply pipeline" (`PromptInjectionDetector.php:18`). Scans **neither attachments nor any header other than the subject** |
| D29 | `INSTRUCTION_OVERRIDE_PATTERNS` | `:19-25` | inbound | 5 regexes | **English only** |
| D30 | `ROLE_MANIPULATION_PATTERNS` | `:28-35` | inbound | 6 regexes | English only; `dan_jailbreak` **without `/i`** (case-sensitive) |
| D31 | `PROMPT_EXTRACTION_PATTERNS` | `:38-43` | inbound | 4 regexes | English only |
| D32 | `DELIMITER_PATTERNS` | `:46-53` | inbound | 6 regexes (``` ```(system\|prompt) ```, `[INST]`, `<\|im_start\|>`, `<\|system\|>`, `^#{3,}\s*(system\|…)`, `<system>`) | 6 delimiter forms |
| D33 | `ENCODING_PATTERNS` | `:56-69` | inbound | 4 regexes: base64 fragments (`aWdub3Jl`, `SWdub3Jl`, `ZGlzcmVnYXJk`, `Zm9yZ2V0`); `[\x{200B}\x{200C}\x{200D}\x{FEFF}]{3,}`; invisible character inside a word; `\\u00XX` escape ×4+ | Comment: the soft hyphen U+00AD "is deliberately NOT flagged here" (`:63-66`); the base64 set covers only **4 encoded words** |
| D34 | `JAILBREAK_PATTERNS` | `:119-124` | inbound | 4 regexes | English only |
| D35 | Homoglyph / invisible-character normalisation | `:157-167`, `:235-269` | inbound | Runs only if `hasNonAscii()` (`:224-227`). Removes `INVISIBLE_CHARS` (`:85`), folds `CONFUSABLES` (`:101-116` — **25 Cyrillic + 22 Greek** → ASCII), NFKC via `\Normalizer`, then ICU `Any-Latin; Latin-ASCII` | **The ICU transliterator returns `null` if the intl extension is missing — "detection degrades to the literal pass"** (`:273-274`); the confusables table is limited to Cyrillic and Greek |
| D36 | Anti-denial-of-service cap on the scan | `:79`, `:139-148` | inbound | `MAX_SCAN_BYTES = 1_048_576` (1 MB); beyond that, `mb_strcut` truncates and logs — "we truncate and flag rather than reject" | **Content beyond 1 MB is not scanned** |
| D37 | `matchPreFilter()` — automated-mail pre-filter | `src/Application/Communication/IngestPostProcessor.php:88-146` | Inbound, before classification and reply | Order: (1) operator test senders (environment CSV, strict equality); (2) `KNOWN_LEGITIMATE_DOMAINS` — **15 entries**: `instagram.com, facebook.com, facebookmail.com, google.com, linkedin.com, twitter.com, x.com, github.com, apple.com, microsoft.com, amazon.com, paypal.com, netflix.com, spotify.com, dropbox.com` (`:36-41`); (3) `LOCAL_PART_PATTERNS` — **7 entries**: `noreply, no-reply, postmaster, abuse, mailer-daemon, dmarcreport, dmarc-noreply` (`:50-53`); (4) subject `'/^\s*Report\s+Domain:/i'` | Docblock: "commercial B2B included — explicitly left to the classifier out-of-scope" (`:79-80`) |
| D38 | HTML → text conversion | `src/Application/Communication/EmailParsingService.php:320-349` | Inbound parsing | Removes `<script>`/`<style>` with their content (`:323-324`), `html_entity_decode(ENT_QUOTES\|ENT_HTML5,'UTF-8')`, block tags → line breaks, `<li>` → `"\n• "`, then `strip_tags()` | **No sanitisation library**; no handling of attributes or of `javascript:` URLs (text extraction only) |
| D39 | Attachment size cap + exclusion of inline parts | `EmailParsingService.php:27`, `:238-266`, `:308-314` | Inbound parsing | `DEFAULT_MAX_ATTACHMENT_SIZE_BYTES = 25 * 1024 * 1024` (25 MB); read in 64 KB chunks, aborting when the cap is exceeded; `isExtractableAttachment()` excludes `inline` parts | **No MIME type allowlist or denylist** — `$mimeType = $part->getContentType() ?? 'application/octet-stream'` (`:275`) |
| D40 | Strict MIME / base64 decoding failures | `EmailParsingService.php:63-75` | Inbound parsing | Strict `base64_decode(..., true)`; `RuntimeException('Invalid base64 in raw_source')` / `'Mail parse error: …'` | — |
| D41 | `SenderFloodDetector` | `src/Application/Communication/SenderFloodDetector.php:18-20`, `:43-78` | Inbound, `IngestPostProcessor::checkSenderRateLimits()` (`:611`) | `BURST_THRESHOLD = 5`, `BURST_WINDOW_SECONDS = 300`, `QUARANTINE_SECONDS = 3600`. Key `sha256(strtolower($senderEmail))` | **`getQuarantinedCount()` is "a no-op placeholder" returning 0** (`:84-89`) |
| D42 | `MessageAnonymizer::anonymize()` | `src/Application/Communication/MessageAnonymizer.php:23-37`, `:44-56` | **Pre-LLM PII masking** (prompt building only) | 5 ordered patterns: email→`[EMAIL]`, IBAN→`[IBAN]`, BTC→`[WALLET]`, ETH→`[WALLET]`, phone→`[PHONE]` | Docblock: "URLs are intentionally NOT included (they are IOCs needed by the LLM)" (`:19`). **Used only by `ContextualEnricher.php:26`**, never by the purge |
| D43 | `MessageRedactionService::redactHeaders()` | `src/Application/Communication/MessageRedactionService.php:12-26` | Monitoring / export | Replaces exactly `From`, `To`, `X-Originating-IP`, `Received` with `[REDACTED]` | Keys are **case-sensitive**; 4 headers only |

### 4.5 Rate, cadence, kill switch, budget

| # | Name | File:line | Exact scope | Not covered |
|---|---|---|---|---|
| D44 | `rate_limiter` configuration | `config/packages/rate_limiter.yaml:1-64` | `login_ip` 5/1 min; `login_email` 5/15 min; `api_global` token bucket 100, refill 100/1 min; `replies_per_conversation` 20/24 h; `llm_calls_per_hour` 200/1 h; `active_conversations_per_day` 50/24 h; `emails_per_sender_per_day` 10/24 h; `ingest_per_account` 100/1 h | — |
| D45 | `ReplyCadenceService::checkRateLimits()` | `src/Application/Communication/ReplyCadenceService.php:116-165` | 3 levels consumed in order; each one emits `RATE_LIMIT_EXCEEDED` | **Bypassed when `$force === true`** (`ReplyHandler.php:194`) |
| D46 | Minimum delay between replies | `ReplyCadenceService.php:27`, `:82-109` | `MIN_HOURS_BETWEEN_REPLIES = 6` | **Bypassed by `$force`** (`ReplyHandler.php:189`) |
| D47 | Kill switch (2 layers) | `ReplyCadenceService.php:30`, `:55-77`; throws in `ReplyHandler.php:137-139` | Layer 1: cache key `llm.killswitch.active`; layer 2: env `SCAMBUSTER_KILL_SWITCH` via `FILTER_VALIDATE_BOOLEAN` | A cache pool failure is **caught and logged**, then the env layer is used instead (`:65-71`). Governs reply generation only |
| D48 | Safe domain list | `ReplyCadenceService.php:170-200` | Wildcard `'*'` in `SCAMBUSTER_SAFE_DOMAINS` → always true (`:179-181`); built-in list `['example.test','mailinator.com','guerrillamail.com']` (`:183`) + environment CSV | **The result is exposed as `safelist_eligible` metadata (`ReplyHandler.php:394`); no code path was found where `false` blocks a send** |
| D49 | Monthly LLM budget cap | `ReplyHandler.php:146-158`; `LlmCostHandler.php:102,123-129` | `'enforce'` → `LlmBudgetExceededException` (HTTP 503); `'warning'` → log and continue; soft threshold at 0.8 of the limit | **Default `'warning'` in the constructor signature** (`ReplyHandler.php:41`) |
| D50 | Alternation invariant ("Lock A") | `ReplyHandler.php:168-186`, `:442-462` | Refuses a new outbound message if the last non-deleted message is already `out`. Comment: "it is enforced unconditionally — force=true does NOT bypass it" (`:171-172`) | — |
| D51 | Refusal on a closed conversation | `ReplyHandler.php:164-166` | `if ($conversation->getStatus()->value !== 'open') throw` | — |

### 4.6 Conversation lifecycle limits

| # | Name | File:line | Exact scope |
|---|---|---|---|
| D52 | `ConversationLifecycleConfig::POLICIES` | `src/Application/Communication/ConversationLifecycleConfig.php:22-55` | **14 keyed policies** (`timeout_hours / max_turns / max_duration_days / allow_reopen / reopen_window_hours`): `ROMANCE 336/50/60/true/72`; `INVESTMENT 168/40/45/true/48`; `INVOICE_FRAUD 72/30/21/true/72`; `CEO_FRAUD 120/25/14/true/72`; `ADVANCE_FEE_419 168/40/30/true/48`; `PHISHING`/`PHISH_CREDENTIALS`/`PHISH_MALWARE 48/15/7/true/72`; `TECH_SUPPORT 24/20/5/true/72`; `LOTTERY 72/25/14/false/0`; `JOB_OFFER 72/25/14/true/72`; `CHARITY 72/25/14/false/0`; `UNKNOWN 72/25/14/true/72`. Default `72/25/14/true/72` (`:58`) |
| D53 | `CloseStaleConversationsCommand` | `src/UI/Console/CloseStaleConversationsCommand.php:23-25`, `:156-173` | Three closing reasons: inactivity, `turnsCount >= max_turns`, age. `--days`, `--dry-run` |

### 4.7 IOC extraction, normalisation and validation

| # | Name | File:line | Exact scope | Not covered |
|---|---|---|---|---|
| D54 | `IocExtractionPolicy::allows()` | `src/Domain/Communication/Policy/IocExtractionPolicy.php:23-26` | `direction->getCode() === 'in'` only | — |
| D55 | `TtpExtractionPolicy::allows()` | `src/Domain/Communication/Policy/TtpExtractionPolicy.php:17-20` | Same rule, inbound only | — |
| D56 | Upsert layer 1 — refuses outbound | `src/Application/Communication/IocUpsertService.php:190-199` | `InvalidArgumentException`. Comment: "Single funnel: this guard catches all callers (HTTP /enriched, MigrateHeaderIocs, IngestPostProcessor, future entry points)" (`:191-192`) | — |
| D57 | Upsert layer 2 — refuses honeypot identifiers | `IocUpsertService.php:205-214`, comparator `:82-154` | Case-insensitive, non-defanged comparison against `honeypotAddressesIndex` / `honeypotDomainsIndex` (injected from the environment). Covers `email` (exact + domain part), `domain` (exact), `url` (host after stripping `www.`) | **3 types only**; **requires the environment lists to be populated** |
| D58 | Anti-denial-of-service cap of the regex extractor | `src/Application/Communication/IocExtractorOrchestrator.php:29`, `:213-222` | `MAX_REGEX_BYTES = 1_048_576` | Text beyond 1 MB is not scanned |
| D59 | IOC pattern battery | `IocExtractorOrchestrator.php:226-247` | **20 typed patterns**: `ipv4, ipv6, email, url, domain, md5, sha1, sha256, iban, bic, wallet_btc, wallet_eth, wallet_xmr, credit_card, phone, telegram_username, discord_username, cve, mitre_attack_id, tracking_number` | `tracking_number` limited to `DHL\|UPS\|FedEx\|USPS\|TNT\|EMS\|Royal Mail\|Colissimo` |
| D60 | Private / reserved IPv4 filter | `IocExtractorOrchestrator.php:262-264`, `:285-299` | Discards `10/8`, `172.16/12`, `192.168/16`, `127/8`; `ip2long()===false` also discards | **Only 4 ranges** — no `169.254/16`, no `100.64/10`, no `0.0.0.0/8`, no multicast, no private IPv6 ranges |
| D61 | Free mail provider exclusion list | `IocExtractorOrchestrator.php:120-124` | **12 entries**: `gmail.com, yahoo.com, outlook.com, hotmail.com, proton.me, protonmail.com, live.com, icloud.com, aol.com, mail.com, yandex.com, zoho.com` | 12 providers; applies only to `derived_from_email` |
| D62 | `IocValidator::validate()` | `src/Application/Communication/IocValidator.php:100-164`; patterns `:29-90` | Per-type regex table (~35 types). **Checksum validation** for `iban` (`Iban::isValid`), `wallet_btc`, `wallet_eth`, `credit_card` (Luhn, `:169-198`), `bank_account`. Unknown type → `false` (`:157-159`) | `subject, x_mailer, registrar, whois_registrar_name, filename, malware_family` use `'/.+/'`; `postal_address` is only a length check `'/^.{10,500}$/s'` (`:89`) |
| D63 | `IocNormalizer` defanging | `src/Application/Communication/IocNormalizer.php:37-92`, `:105-141`, `:229-254` | URL: `http→hxxp`, dots → `[.]`, trailing `/` removed, lowercased; domains lowercased + `[.]`; IBAN without spaces and uppercased; phone without separators except `+`; digests lowercased; `refang()` reverses it | `wallet_*`, `filename`, `message_id`, `subject`, `bank_account` are kept as-is |
| D64 | `IocActionablePolicy::NON_ACTIONABLE_TYPES` | `src/Domain/Communication/Policy/IocActionablePolicy.php:41-77` | **17 types excluded** from the counts: `subject, message_id, x_mailer, return_path, spf_result, dkim_result, dmarc_result, whois_email, whois_registrar_name, registrar, filename, mimetype, cve, malware_family, mitre_attack_id, tracking_number, md5, sha1, sha256` | Affects the counters, **not the export** |
| D65 | `CleanupPlatformContaminationCommand` | `src/UI/Console/CleanupPlatformContaminationCommand.php:39-585` | Phase 5: deletes every `observed_ioc` attached to a message with `direction = 'out'` (`:536-544`); phase 6: orphan indicators (`:546-551`); phase 7: honeypot indicators after `unDefang()` (`:342-345`, `:362-475`). CSV audit to `var/audit/061-cleanup-{ts}.csv`; phases 5–7 in a single transaction with rollback (`:227-245`) | Honeypot matching on **3 IOC types**; **no effect if both lists are empty** (`:563-565`) |

### 4.8 Export blocking of financial IOCs (mule / victim protection)

| # | Name | File:line | Exact scope | Not covered |
|---|---|---|---|---|
| D66 | `IocExportPolicy` — single source of truth for egress | `src/Domain/Communication/Policy/IocExportPolicy.php:31-84` | **Two fail-closed rules**: (1) `verdict === AnalystVerdict::FalsePositive` → never exported; (2) `IocCategory::classify($type) === FINANCIAL` → exported **only if** `verdict === Confirmed` (`:47-58`). `isHeldForReview()` = `verdict === null && classify === FINANCIAL` (`:39-42`) | Composes with, and does not replace, the TLP:RED filter and `IocActionablePolicy` (`:28-29`) |
| D67 | Set of held types | `src/Domain/Communication/IocCategory.php:33-43` | `FINANCIAL_TYPES` = **10 entries**: `iban, bic, swift, bank_account, routing_number, wallet_btc, wallet_eth, wallet_xmr, wallet, credit_card` | `phone`, `email` belong to `CONTACT` and are **not held** |
| D68 | SQL fragment | `IocExportPolicy.php:72-83` | `(f.verdict IS NULL OR f.verdict <> 'false_positive') AND (LOWER(BTRIM(i.type)) NOT IN (…10 types…) OR f.verdict = 'confirmed')`; the type is compared via `LOWER(BTRIM(...))` "so a mixed-case or padded financial type cannot bypass it" (`:68-70`) | Requires a left join on `ioc_analyst_feedback` |
| D69 | Egress call sites | `TaxiiService.php:291`; `IocFeedExporter.php:219`; `ClusterQueryService.php:592`; `ConversationStixExportHandler.php:208`; `IocStixExportHandler.php:76`; `ExportMispController.php:145` | **5 at SQL level + 1 at PHP level** (`isExportable`, MISP). MISP additionally sets `to_ids = ($verdict === Confirmed)` and tags `scambuster:analyst-verdict="…"` (`ExportMispController.php:150-152`) | **The STIX handler keeps IOC rows that have no persisted `indicator_id`** — "IOC rows without a persisted indicator id cannot be verdict-checked; keep legacy behaviour" (`ConversationStixExportHandler.php:227-228`) |
| D70 | Exposure of the hold state in the read API | `src/Application/Communication/IocQueryService.php:180`, `:392` | `'export_held' => IocExportPolicy::isHeldForReview($type, $verdict)`. Comment (`ConversationStixExportHandler.php:199-202`): "the internal UI must keep showing held IOCs so an analyst can review and release them, but they must not leave the platform in a STIX bundle" | — |
| D71 | Release path — analyst verdict | `src/UI/Http/ThreatActor/SubmitIocFeedbackController.php:31-79` | `POST /api/v1/iocs/{indicatorId}/feedback`, `indicatorId` constrained to `[0-9a-f-]{36}`, `#[IsGranted('ioc:feedback')]` (`:32`). Invalid `verdict` → 422; `note` truncated to 1000 characters (`:62`); emits `IOC_FEEDBACK` with the client IP (`:67-76`) | — |
| D72 | Verdict persistence + confidence fallback | `src/Application/ThreatActor/IocFeedbackService.php:33-74` | Upsert on `ioc_analyst_feedback` (`ON CONFLICT (indicator_id) DO UPDATE`); `Confirmed` → `confidence_score = GREATEST(...)`, `FalsePositive` → `confidence_score = :conf` (`:62-74`) | **One row per indicator only** — the latest verdict replaces the previous one |
| D73 | TLP:RED never public filter | `src/Application/Taxii/TaxiiService.php:275`, `:779` | `UPPER(REPLACE(REPLACE(i.tlp,'TLP:',''),'TLP_','')) <> 'RED'`, applied to indicators and to promoted campaigns | **`IocFeedExporter.php:19` states that the analyst feed "does NOT strip TLP:RED — the analyst is trusted"** |

### 4.9 Authentication, authorisation, secrets

| # | Name | File:line | Exact scope | Not covered |
|---|---|---|---|---|
| D74 | Firewalls and access control | `config/packages/security.yaml:16-63` | Firewalls `dev` (disabled), `api_auth` (`^/api/v1/auth`, security disabled), `api` (`^/api/v1`, stateless, JWT + `TaxiiApiKeyAuthenticator`), `main`. Access control: `/api/metrics`, `/api/health`, `/api/v1/admin`, `/api/v1/internal`, `/api/v1/campaign/hunt`, `…/promote`, `…/rules/compile` → `ROLE_ADMIN`; `^/api/v1` → `IS_AUTHENTICATED_FULLY`; `^/api/v1/auth`, `^/logout`, `^/healthz` → `PUBLIC_ACCESS` | — |
| D75 | `PermissionVoter` | `src/Security/PermissionVoter.php:23-59` | `ROLE_ADMIN` → all permissions (`:34-36`); `ROLE_TAXII_FEED` → `ioc:read` only, checked before the InMemoryUser fallback (`:41-44`); `User` entity → `hasPermission()`; InMemoryUser with `ROLE_USER` → all permissions (test environment) | **14 permissions** enumerated in `src/Domain/User/Permission.php:19-40` |
| D76 | `TaxiiApiKeyAuthenticator` | `src/Security/TaxiiApiKeyAuthenticator.php:45-160` | `^/api/v1/taxii2` only (`:48`); key via the `X-TAXII-API-KEY` header or Basic — explicitly not `Authorization: Bearer` (`:29-33`); `MIN_KEY_LENGTH = 32`, below which the feature is disabled (`:63`, `:114-117`); `hash_equals()` comparison (`:86`); generic 401 that never echoes the credential (`:105-112`) | — |
| D77 | TOTP 2FA | `config/packages/scheb_2fa.yaml:1-8` | `digits: 6`, `period: 30`, `algorithm: sha1`, issuer `ScamBuster` | — |
| D78 | OIDC — **detailed in §4.11** | `src/Application/Auth/Oidc/` (7 classes) + `OidcLoginController`, `OidcCallbackController` | Opt-in module, **disabled by default** (`config/services.yaml:26`); 11 deterministic controls listed | See §4.11 |
| D79 | `SecretPolicy` | `src/Security/SecretPolicy.php:20-135` | See §6.4 | **Never enforced outside production** — returns `[]` if `!$isProd` (`:71-73`). **No entropy or length minimum** beyond the repeated-character check |
| D80 | `CheckSecretsCommand` | `src/UI/Console/CheckSecretsCommand.php:29-101` | 7 variables (§6.5) | No effect outside `APP_ENV=prod` (`:57`, `:66-72`) |
| D81 | Honeypot name leak scanner | `scripts/check-honeypot-leak.sh` | Pre-commit hook + preflight gate 9. Reads `local/honeypot-names.txt` (git-ignored); case-insensitive literal matching (`grep -inF`); `staged` mode on `git diff --cached`, `--full` on `git ls-files`; exit 1 with `file:line: matched 'name'` | **"Exits zero (with a one-line skip note) when local/honeypot-names.txt is absent"**; an empty list also means a skip. Substring matching only — **neither fuzzy nor homoglyph-aware** |
| D82 | Preflight gate 9 | `scripts/preflight-check.sh:158-161` | `CURRENT_GATE=9` runs `check-honeypot-leak.sh --full`; 9 gates in total, stopping at the first failure (`:52`) | — |
| D83 | GUARD pre-push hook (optional) | `scripts/hooks/pre-push-guard.sh:1-50` | Triggered on `config/scambuster/\|src/Application/LLM/\|src/Application/Guard/` (`:25`). **By default: only a reminder, the push goes through**; `GUARD_ON_PUSH=1` runs `make guard` and blocks | **Not installed by default** — requires `make guard-hook-install` (`Makefile:239-244`) |

### 4.11 OIDC module — deterministic controls (follow-up pass)

**Opt-in module, disabled by default** [VERIFIED] `config/services.yaml:26`; both
entry points throw `NotFoundHttpException` when it is inactive
(`OidcLoginController.php:37-39`, `OidcCallbackController.php:43-45`).

| # | Control | File:line | Exact scope | Not covered |
|---|---|---|---|---|
| D84 | `state` generation | `OidcStateManager.php:35` | `base64Url(random_bytes(32))` — 32 CSPRNG bytes | — |
| D85 | HMAC-SHA256 signature of the `state` | `OidcStateManager.php:112-115`, verified `:69` with `hash_equals` | The `state` is **self-carried in a signed cookie**, with no server session (declared `:12-13`) | Does not bind the state to a browser identity beyond the cookie |
| D86 | Comparison of the returned `state` | `OidcCallbackController.php:62-64` — `hash_equals` | Constant time | — |
| D87 | `state` lifetime | `OidcStateManager.php:21` — `TTL_SECONDS = 600` (10 min); set `:39`, enforced `:92-94`, cookie expiry `:107-110` | Server-side expiry inside the signed payload + cookie expiry | **No single-use registry**: because the state is stateless, the same value can be replayed within the 600 s window |
| D88 | `nonce` | Generated `OidcStateManager.php:36`; sent `OidcService.php:46`; **verified** against the identity token `OidcService.php:210-212` | Strict `!==` comparison | Not a constant-time comparison |
| D89 | **PKCE S256** | Verifier `OidcStateManager.php:31` (`random_bytes(64)`), challenge `:32` (`sha256`), sent `OidcService.php:47-48`, replayed `:115` | Full PKCE in S256 | — |
| D90 | `iss` issuer pinning | `OidcService.php:191-193` | Compared with `!==` against the `issuer` **taken from the discovery document** (`:61`) | **Not pinned to a static configuration value**: the expected issuer is whatever the discovery URL returns |
| D91 | `aud` audience check | `OidcService.php:195-202` | Handles both string and array; `in_array(..., true)` / `===` against `clientId` | `azp` is checked only if `aud` is an array with more than one element **and** `azp` is present (`:206-208`) |
| D92 | `exp` expiry check | `OidcService.php:214-218` | Rejects expired tokens; missing or non-numeric `exp` → `0` (`:265-268`) → rejected | **`iat` is never read** (search for `iat` over the directory: no result); **no clock-skew tolerance** |
| D93 | UserInfo cross-check | `OidcService.php:63-71` | Calls `userinfo_endpoint` and requires `userInfo['sub'] === idClaims['sub']` | This is the **declared substitute** for signature verification — see §5.2 |
| D94 | `email_verified` | `OidcService.php:82-86` | Rejects any explicit value other than `true` / `"true"` | **A missing claim counts as `true`** (`?? true`, `:82`) |
| D95 | Email domain allowlist | `OidcConfig.php:49-64`, applied `OidcService.php:88-90` | Case-insensitive comparison on the part after the last `@` | **Empty list = any domain accepted** (`:51-53`); shipped default `[]` (`config/services.yaml:38`) |
| D96 | Auto-provisioning gate | `OidcUserProvisioner.php:34-36` | Unknown email + `autoProvision=false` → exception. **Default `false`** (`config/services.yaml:34`) | — |
| D97 | Roles of the provisioned account | `OidcUserProvisioner.php:43` | `OIDC_DEFAULT_ROLES` if set, otherwise `['ROLE_USER']`; shipped default `[]` → `ROLE_USER` | `setPermissions()` is **never called**; no path to `ROLE_ADMIN` |
| D98 | Password of the provisioned account | `OidcUserProvisioner.php:42` | `bin2hex(random_bytes(32))`, hashed | — |
| D99 | State cookie flags | `OidcLoginController.php:45-55` | `secure=true`, `httpOnly=true`, `SameSite=Lax`, path `/api/v1/auth/oidc` | Cleared on success (`:92`), **not on the failure path** (`:79` returns first) |
| D100 | Error message hygiene | `OidcCallbackController.php:79`, `:116-123` | The client only ever sees `'SSO authentication failed.'`; the reason goes to the audit log only | — |

**Identity token signature** [VERIFIED]: **not verified**.
`OidcService.php:162-184` splits the token on `.`, decodes **only segment `[1]`** and
parses it as JSON; the header and the signature are never read. No JWKS retrieval,
no key cache, no library. The docblock `OidcService.php:16-21`
**declares the omission to be intentional**, citing OIDC Core §3.1.3.7 — the token
arrives over the back channel — and points to the UserInfo cross-check (D93) as
substitute evidence.

**Redirect URI** [VERIFIED]: **no scheme constraint**. `redirectUri` is taken
as-is from `OIDC_REDIRECT_URI` (`config/services.yaml:251`) and used without
validation (`OidcService.php:43`, `:112`). Same finding for `successRedirect`
(`OidcCallbackController.php:106`, `:119`). Search for `https|isSecure|str_starts_with`
over the module and both controllers: no result.
**Token delivery**: when `successRedirect` is non-empty, `access_token` and
`refresh_token` are placed in the **URL fragment** of a 302 redirect to that
unvalidated target (`OidcCallbackController.php:99-107`).

---

### 4.12 Other checks from the follow-up pass

| Finding | Evidence | Scope |
|---|---|---|
| **`app:security:check-secrets` is indeed invoked at startup in production** | `infra/docker/backend/docker-entrypoint-prod.sh:29`, with `set -e` (`:5`), **after** `write-prod-env.sh` (`:23`) and **before** the PostgreSQL wait (`:31-41`), the JWT key generation (`:43-53`) and the migrations (`:57`) | Confirms the docblock claim `CheckSecretsCommand.php:19-21` |
| Assertions on required variables before anything else | `docker-entrypoint-prod.sh:10-18` — `DATABASE_URL`, `APP_SECRET` (+ minimum length 12, `:12`), `TOTP_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`, `JWT_PASSPHRASE`; explicit `exit 1` | — |
| `write-prod-env.sh` — allowlist of 25 prefixes | `infra/docker/backend/write-prod-env.sh:6`; values written **raw, without escaping**, unlike the demo entrypoint which quotes them through PHP (`docker-entrypoint-demo.sh:33-46`, pattern `:29-32`) | — |
| **10 `CHECK` constraints** in the database | `Version20251021140000.php:31,34,81`; `Version20251121100100.php:50,53`; `Version20260405120000.php:53`; `Version20260409100000.php:39`; `Version2026073000000000.php:407`; `Version2026073000100000.php:46,47` | All declared inline inside a `CREATE TABLE`. **None** on `app_users`, `message`, `conversation`, `observed_ioc`, `audit_log` |
| `.github/scripts/create-ci-env.sh` — **no real secret** | `set -euo pipefail` (`:10`); every secret-looking value is self-labelled as fake: `ci-test-app-secret-32chars-min!!`, `sk-test-not-a-real-key`, `ci-test-passphrase`, `testpass`, `LLM_PROVIDER=mock` (`:25`) | — |
| `scripts/check-credentials.py` — connectivity probes | 200 lines; exit code = **number of failed checks** (`:195`); IMAP (`:60-83`), SMTP (`:86-117`), LLM (`:129-185`). An HTTP status other than 200/401/403 returns `SKIP` and **0** (`:183-185`) | Secrets are never printed (`:14`) |
| `TestReplyGenerateCommand` | **No `#[AsCommand]` attribute**; legacy registration `setName('app:test:reply-generate')` (`:24`), description `:25`. **Hard-coded** UUID `:33`; read-only diagnostic | Closes question 31 of §12 |
| **`alert.rules.yml` — only 4 rules** | `ScamBusterKillSwitchActive` (warning, `:5-12`), `ScamBusterDependencyDown` (critical, `:14-21`), `ScamBusterMetricsUnreachable` (critical, `:23-30`), `ScamBusterIngestStalled` (warning, `:32-42`) | **No rule** on the authentication failure rate, brute force, limiter exhaustion, injection detection or the budget threshold |
| Protection of `/api/metrics` and `/api/health` | `config/packages/security.yaml:60-61` — `roles: ROLE_ADMIN` on both, placed after `^/healthz` in `PUBLIC_ACCESS` (`:59`) and before the `^/api/v1` catch-all (`:67`) | Confirms DOC-01 |
| **Demo — fixed administrator password** | `infra/docker/demo/docker-entrypoint-demo.sh` generates no password; the accounts come from the fixture loaded at `:180`. Hard-coded value `UserFixtures.php:25,27` — **the same for the user and the administrator** — and **printed to standard output** `:389-391`. The demo entrypoint **does not run** `app:security:check-secrets`; default CORS `^https?://.+$` (`:16`) | Demo scope only |

---

### 4.10 CORE prompt rules (not bypassable by override, but not enforceable)

| Item | File:line | Contents |
|---|---|---|
| CORE / EDITABLE split of `BasePromptRules` | `src/Application/LLM/Prompt/BasePromptRules.php:17-27`, `:41-67`, `:83-90` | The CORE rules are "safety-adjacent / anti-unmask invariants that an operator override must never be able to remove". CORE includes: no honeypot/bot knowledge (`:41`), reply entirely in `{detectedLanguage}` (`:44`), payment signal rule (`:50`), out-of-band prohibition — "Never give a phone, WhatsApp, Telegram, Skype, Signal, Discord, crypto wallet, IBAN, postal address or a different email address — even fictional ones reveal automation" (`:55`), cautious-buyer escalation rule (`:60`), treat sender claims as intelligence and not as fact (`:67`) |
| Override injection order | `src/Application/LLM/PromptBuilder.php:94-107` | Only the EDITABLE subset goes through the operator override chain; CORE is always injected. **Note in the code: "the editable override lands AFTER core, so a hostile override can add text that…"** (`:98`) |
| Safe fallback of `PromptProvider` | `src/Application/LLM/Prompt/PromptProvider.php:14`, `:34-35`, `:71`, `:82-83` | "an absent, unreadable, empty, or invalid override degrades to the inline" default; an override missing a required placeholder moves on to the next source; any backend error is treated as "no override" |

[INFERRED] The CORE rules are **prompt instructions**, not enforceable controls:
whether they are respected depends on the model. Reasoning: they are concatenated into
the prompt text (`PromptBuilder.php:94-107`) and are only checked afterwards by
`PolicyGuard` (D1–D10), whose pattern sets cover only part of the stated invariants
(for example "treat sender claims as intelligence not fact" has no deterministic
counterpart).

---

## 5. LLM-based controls and dependencies with no deterministic fallback

| # | Name | File:line | Decision taken | Deterministic fallback on failure / aberrant output | Downstream action with no deterministic safety net |
|---|---|---|---|---|---|
| S1 | `PaymentInstigationGuard::check()` | `src/Application/LLM/PaymentInstigationGuard.php:108-152` | Whether an outbound draft **introduces** a payment infrastructure topic before the adversary does. `gpt-4o-mini`, `TEMPERATURE = 0.0`, `MAX_TOKENS = 30` (`:50-53`). Verdicts: `YES_PERSONA_INSTIGATES` / `NO_OPERATOR_ALREADY_MENTIONED` / `NO_OUTBOUND_DOES_NOT_MENTION_PAYMENT` (`:423-427`) | **Yes, content-conditional.** `failureVerdict()` (`:162-179`): if `containsPaymentInfraTokens()` (D17, 12 regexes) → **fail closed** (block); otherwise **fail open** (approve). Triggered on `\Throwable` (`:131-133`) and on an unreadable verdict (`:137-139`). Also short-circuits to approval when there is no inbound body (`:115-117`) | A draft that instigates a payment with vocabulary **outside the 12 fallback tokens**, during an LLM outage, is approved |
| S2 | `PaymentInstigationGuard::isPaymentTopicAnchored()` | `PaymentInstigationGuard.php:213-261` | Whether the adversary has already raised payment — feeds the prompt objectives **and the per-attempt skipping of `check()`** (`RetryCoordinator.php:99-103`, `:185-187`) | **Yes: fail closed** (returns `false`) on `\Throwable` (`:233-240`) and on an unreadable verdict (`:255-260`). Docblock: "Failure semantics are the OPPOSITE of check()" (`:207-211`) | **When it returns `true`, the per-attempt `check()` — the one carrying the deterministic fallback — is skipped entirely** for that generation (`RetryCoordinator.php:185-187`) |
| S3 | `OperationalLeakageDetector::check()` | `src/Application/LLM/OperationalLeakageDetector.php:45-102` | Whether the reply discloses operational information, **including through paraphrase** ("the orchestrator", "the platform that runs me"). `gpt-4o-mini`, `T=0.0`, 200 tokens (`:28-31`). Stage 3 (`RetryCoordinator.php:221-248`) | **Fail open, with no re-check.** Returns `LeakageDetectionResult(false)` on an LLM exception (`:59-66`), on a JSON parsing failure (`:70-79`), or when the `leak` field is missing or not a boolean (`:83-89`). Docblock: "The hard gate is the PolicyGuard regex deny-list … it MUST fail open" (`:21-24`) | **The class of paraphrase it targets — anything outside the 6 `FORBIDDEN_PATTERNS` + 10 `OPERATIONAL_LEAKAGE_PATTERNS` — has no deterministic safety net** |
| S4 | `ReplyValidator::validateMultiCriteria()` | `src/Application/LLM/ReplyValidator.php:69-149` | Multi-criteria quality **and security gate**: `naturalness`, `persona_fit`, `ti_value`, `security_pass`. `T=0.4`, 500 tokens (`:95-99`). Rejects if `security_pass=false`, or `naturalness < 2`, or the average is `< 2.5` (`:19-21`) | **No fallback inside the class** — throws `RuntimeException` on invalid JSON (`:141-148`) and on a missing `approved` field (`:168-170`). The caller handles it: `RetryCoordinator.php:260-276` catches, records the attempt, and on the last attempt uses best-of-3 **only if** an earlier attempt had `security_pass === true`, otherwise the frozen fallback (`:266-268`) | **The best-of-3 eligibility flag (`security_pass`) is itself produced by the LLM** (`RetryCoordinator.php:364-366`); no deterministic re-check is run on the selected text beyond the PolicyGuard pass it had already been through |
| S5 | `PromptInjectionLlmAnalyzer::analyze()` — layer 2 | `src/Application/Communication/PromptInjectionLlmAnalyzer.php:73-97` | Classifies inbound injection attempts against a taxonomy of 6 techniques (`:29-34`), returns `risk_score`, `detected_techniques`, `confidence`, `summary`. `gpt-4o-mini`, `T=0.2` (`:57-58`), 1000 tokens; **body truncated to 3000 characters** (`:102-103`); scores clamped to `[0,1]` (`:140-143`) | **Yes: layer 1 (D28–D36).** `PromptInjectionDetector::analyze()` catches the exception and builds the analysis from the pattern matches alone, `modelVersion='pattern_matcher_only'`, `confidence = 0.7` if there are matches, otherwise `0.5` (`PromptInjectionDetector.php:61-69`, `:99-114`). When both layers run: `riskScore = max(patternScore, llmScore)` (`:94`) | **Nothing relies on it**: the whole subsystem is forensic. `IngestPostProcessor::analyzePromptInjection()` persists `injection_analysis` and, if `isHighRisk()` (`riskScore >= 0.7`, `PromptInjectionAnalysis.php:91-94`), emits an `INJECTION_DETECTED` audit event **with outcome `'blocked'`** (`IngestPostProcessor.php:564-577`) — **yet ingestion and the reply pipeline continue** |
| S6 | "Director" stop gate (`ConversationAnalyzer`) | invoked `ReplyHandler.php:75-119`, `:221-232` | Whether the conversation is "burned": if `!$brief->shouldContinue`, `ReplyHandler` **closes the conversation** instead of replying (`:223-231`) | **Yes, fails open towards continuing.** Returns `null` if the analyser is not wired, if `count($allMessages) < 2`, or on any `\Throwable` (`:77-79`, `:84-86`, `:104-110`): "null-safe: a missing analyzer, too few messages, or any failure returns null so replies are never blocked by this gate" (`:69-71`). A malformed brief falls back to `ConversationDirectorBrief::default()` (`ConversationAnalyzer.php:839`, `:946`) | **The decision to close a conversation rests solely on the LLM brief** |
| S7 | `IOCLikelihoodScorer::score()` | `src/Application/LLM/IOCLikelihoodScorer.php:23` (invoked `RetryCoordinator.php:299`) | Scores a reply from 0 to 100 on the likelihood of IOC extraction. **Deterministic heuristic, not an LLM**: `+25` explicit question, `+25` targeted channel, `+15` reference to the last message, `+10` missing IOC types, `−20` proactive action, `−10` repeated question, `−15` generic language (`:15-22`); EN+FR keyword table (`:31-58`) | n/a | Gate at `RetryCoordinator.php:313`: retries if `iocScore < $iocThreshold` (default `60`) **and** attempts remain; **on the last attempt the reply is accepted anyway** |
| S8 | `ScamClassificationHandler` | `src/Application/Communication/ScamClassificationHandler.php` | LLM classification of the scam type, driving persona selection and the lifecycle policy | Catches at `:216`, `:293`; falls back to a generic persona (`:186`, `:304`) and to the `UNKNOWN` type (`:70`, `:371`, `:385`) | `UNKNOWN` maps to the default lifecycle policy (`ConversationLifecycleConfig.php:54`) |
| S9 | `RewardJudge` (LLM judge, offline) | `src/Application/Scambaiting/RewardJudge.php:24,38,64` | Judges the actual outcome of a finished conversation, blended with the mechanical reward (bandit optimisation) | **Yes**: `catch (\Throwable)` → "LLM outcome scoring failed, using mechanical reward" (`:96-97`); `catch (\JsonException)` (`:115`) | Feeds persona optimisation, not a security decision at send time |
| S10 | `EvalRunJudgeCommand` (LLM judge, offline) | `src/UI/Console/EvalRunJudgeCommand.php:17-24`, `:113-137` | Multi-judge harness: a judge model (`--model`, default `gpt-4o`) predicts signals on a rendered IOC | `catch (\Throwable)` per item (`:137`), `catch (\JsonException)` (`:283`) | Evaluation artefact only |

### 5.1 The GUARD subsystem, end to end

**Nature.** A non-regression gate over the **generative** pipeline: it runs the real
reply pipeline on a frozen fixture set, scores each outbound text produced with the
deterministic oracle `SafetyInvariantOracle` (D18–D26), and compares the resulting rates
against a frozen, checksummed baseline [VERIFIED].

| Stage | File:line | Behaviour |
|---|---|---|
| Smoke run | `scambuster:smoke:reply-objective --summary-json=…` (`Makefile:226,233`); in-process variant `src/Infrastructure/Guard/InProcessSmokeRunner.php:27-63` | Produces a summary JSON with `fixtures` (carrying `language` + `out_texts`) and `aggregate`. Unique summary path per invocation `summary-{8 random bytes}.json` so that "a leftover summary from a previous job can never be mistaken for this run's output" (`:29-33`); throws if the file is missing (`:42-44`); `@unlink` in `finally` (`:60-62`). Runs inside the same PHP process so that an `EphemeralPromptOverride` candidate is visible (`:12-16`) |
| Scoring | `src/Application/Guard/CanaryAggregate.php:29-83` | For each `out_texts`, `oracle->violations($text, $fixtureLanguage)`; emits `violation_rates[code]` for the 7 codes, plus `metrics{approved_rate, fallback_rate, attempts_avg}` and `meta{recording_slots, runs, errors, out_texts_scored, oracle_fingerprint}`. "Volatile out-texts are dropped; only rates survive" (`:13-15`) |
| Baseline freeze | `src/UI/Console/GuardBaselineCommand.php:28-115` | Writes the JSON plus a `.sha256` companion (`:99-100`). "regenerating it is an explicit, reviewed commit — the gate never auto-updates it" (`:21-22`) |
| **Baseline in force** | `backend-symfony/tests/Smoke/guard-baseline.json` | `approved_rate 1.0`; `fallback_rate 0.0`; `attempts_avg 1.8941176470588235`; violation rates: **`payment_token 0.294`**, `language_mismatch 0.0353`, all others `0.0`; `meta`: `recording_slots 85`, `runs 85`, `errors 0`, `out_texts_scored 85`, `oracle_fingerprint "374f95367add"`. SHA256 `336a3f06d6d5…4e9162` |
| Loading + integrity | `src/Application/Guard/CanaryBaselineProvider.php:31-85` | `CanaryBaselineException` if the baseline is missing / unreadable / invalid JSON / malformed, and if the `.sha256` does not match (`:83`). **A missing `.sha256` companion is tolerated** (`:69-71`) |
| Comparison | `src/Application/Guard/CanaryBaselineComparator.php:48-147` | In order: (1) different oracle fingerprint → not ok (`:54-66`); (2) evidence integrity: fail closed if `out_texts_scored === 0`, or `errors > 0`, or `scored < ceil(runs * MIN_SCORED_RATIO)` with `MIN_SCORED_RATIO = 0.5` (`:40`, `:72-93`); (3) **two-sided `fallback_rate`**: `abs(delta) > TOLERANCE` (`TOLERANCE = 0.05`) — an increase = "pipeline quality degraded", a decrease = "possible weakened guard letting content through" (`:107-109`); (4) each monitored violation code: baseline zero → flagged on any non-zero candidate; baseline non-zero → flagged if `delta > 0.05`. **The codes in `INFORMATIONAL_CODES` (currently `payment_token` alone) are ignored** (`:116-119`) |
| CLI merge gate | `src/UI/Console/GuardCheckCommand.php:32-158` | Rejects a summary without `fixtures`/`aggregate` (`:65-69`); prints a `signal / baseline / candidate / delta / reason` table; `return $verdict['ok'] ? SUCCESS : FAILURE` (`:83`) |
| Asynchronous worker | `src/UI/Console/GuardCanaryWorkCommand.php:32-93` | One pending job per invocation. `failStale(new \DateTimeImmutable('-90 minutes'))` first (`STALE_TIMEOUT_MINUTES = 90`, `:38` — "A legitimate full run takes ~35 min"), then `claimOldestPending()`. **Loads and integrity-checks the baseline first** so that "a broken trust anchor must fail in milliseconds — never after the ~35-min paid run" (`:71-75`) |
| Availability precondition | `src/Application/Guard/CanaryAvailability.php:25-69` | `isConfigured()`: `mock` → false; `ollama` → true; `anthropic` → requires `ANTHROPIC_API_KEY`; default → requires `LLM_API_KEY`. Keys containing `your-api-key`, `your-key-here`, `not-needed`, `changeme` are treated as unusable (`:33`). "It is a necessary, not sufficient, signal: it cannot see whether the canary-worker process is actually running" (`:21-22`) |
| Weekly CI | `.github/workflows/guard-nightly.yml` | See §9.6. The per-PR CI runs only GUARD's **offline** checks (fingerprint lock, drift tests, comparator) through the unit suite; the real-LLM canary costs "~$0.14, ~35 min" (`:4-7`) |

**On failure**: `guard:check` exits non-zero (failing CI job); the asynchronous worker
records a `REGRESSION` verdict (`GuardCanaryWorkCommand.php:83`) or `markFailed()`
(`:85`). A crashed worker leaves the job in `RUNNING`; the `failStale` sweep of the
next invocation terminates it (`:26-27`, `:54-57`) [VERIFIED].

[INFERRED] **The baseline `payment_token` rate is 0.294 and this code is classified as
informational, therefore non-blocking.** The consequence readable in the code: about
29% of the reference outbound texts contain payment infrastructure vocabulary, and an
increase in that rate cannot fail the gate. Reasoning:
`guard-baseline.json` (`payment_token 0.29411764705882354`) cross-checked with
`SafetyInvariantOracle.php:70-72` (`INFORMATIONAL_CODES`) and
`CanaryBaselineComparator.php:116-119` (explicit skip).


---

## 6. Secrets

### 6.1 Master table

| Secret | Read from | File:line of the read | Form | Consumer |
|---|---|---|---|---|
| `APP_SECRET` | plaintext env variable | `config/services.yaml` (`SmtpDsnEncryptor` binding); constructor `src/Application/Communication/Smtp/SmtpDsnEncryptor.php:33` | plaintext env; **used as key derivation input** | Symfony (cookies/CSRF) **+ derivation of the SMTP DSN encryption key** (`SmtpDsnEncryptor.php:43`) |
| `LLM_API_KEY` | plaintext env | `config/services.yaml:139`, `:531`; `config/packages/llm.yaml:6` | plaintext env | `OpenAIService`, `CanaryAvailability` |
| `ANTHROPIC_API_KEY` | plaintext env, container default | `config/services.yaml:531-533` | plaintext env | `src/Application/Guard/CanaryAvailability.php:19` |
| `JWT_PASSPHRASE` | plaintext env | `config/packages/lexik_jwt_authentication.yaml:5` | plaintext env; protects an RSA private key on disk | LexikJWTAuthenticationBundle |
| JWT key pair | PEM files in `backend-symfony/config/jwt/` | `scripts/generate-jwt-keys.sh:12,27,32,35-36` | private key encrypted with `JWT_PASSPHRASE`; `chmod 600` at generation | LexikJWT |
| JWT rotation | same files + timestamped backup | `scripts/rotate-jwt-keys.sh:18,19,44,48,51-52` | **`chmod 644` on the private key after rotation**, versus `600` at generation | LexikJWT |
| `AUDIT_HMAC_KEY` | plaintext env (64 hex) | `config/services.yaml:511` → `$hmacKeyHex`; documented `src/Application/Audit/AuditHmacChainer.php:19` | plaintext env, raw HMAC key | `AuditHmacChainer`. Missing key → WARNING + chain disabled in dev/test (`:50`), exception in prod (`:44`) |
| `TOTP_ENCRYPTION_KEY` | plaintext env, read directly from `$_ENV`/`$_SERVER` | `src/Infrastructure/Doctrine/Type/EncryptedStringType.php:97` | plaintext env (64 hex = 32 bytes, validated `:99`) | Doctrine type `encrypted_string` |
| User TOTP secrets | column `app_users.totp_secret` | `src/Domain/User/User.php:47-48` | **encrypted at rest**: libsodium `crypto_secretbox` (XSalsa20-Poly1305), stored as `nonce‖ciphertext` in BYTEA (`EncryptedStringType.php:15-17,54-57`) | `DoctrineUserTotpChecker` |
| `TAXII_API_KEY` | plaintext env, empty default | `config/services.yaml:69` | plaintext env; empty string = feature disabled | `App\Security\TaxiiApiKeyAuthenticator`; `config/packages/security.yaml:34` |
| `OIDC_CLIENT_SECRET` | plaintext env | `config/services.yaml:250` | plaintext env | OIDC client |
| `DATABASE_URL` (contains the password) | plaintext env | `config/packages/doctrine.yaml:14`; raw read `src/UI/Console/PreprodClearConversationsCommand.php:42` and `infra/docker/backend/scheduler.sh:105` | plaintext env (DSN with embedded credentials) | Doctrine DBAL; `pg_dump` |
| `POSTGRES_PASSWORD` | plaintext env | `.env.dist:49` | plaintext env | PostgreSQL container |
| `MISP_URL` / `MISP_API_KEY` / `MISP_VERIFY_SSL` | plaintext env, read from `$_ENV` | `src/UI/Console/MispTestCommand.php:33,35,59` | plaintext env | `scambuster:misp:test` |
| `SIEM_PROVIDER` / `SIEM_ENDPOINT` / `SIEM_FORMAT` | plaintext env, read from `$_ENV` | `src/Infrastructure/Siem/SiemCompilerPass.php:51,54,67` | plaintext env (the endpoint may carry credentials in the URL) | Syslog/File/Null exporters |
| `LOGIN_HASH_SALT` | plaintext env, read from `$_ENV` | `src/Application/Auth/LoginHashGenerator.php:14` | plaintext env | `LoginHashGenerator` |
| `N8N_ENCRYPTION_KEY` | plaintext env consumed by the n8n container | `docker-compose.yml:211-214` (comment), `.env.dist:173` | plaintext env; n8n encrypts its own credential store with it | n8n |
| **Engagement IMAP/SMTP credentials** | n8n's internal on-disk credential store | `docker-compose.yml:248` (`./data/n8n:/home/node/.n8n`), comment `:206-214` | encrypted at rest by n8n via `N8N_ENCRYPTION_KEY` | IMAP ingestion workflow |
| `N8N_DEFAULT_USER_PASSWORD` / `_EMAIL` | plaintext env | `.env.dist:178`, `:177` | plaintext env | `n8n/n8n-init.sh` |
| `HONEYPOT_IMAP_*` | plaintext env | `.env.dist:190-194` | plaintext env | IMAP ingestion (n8n side) |
| `INGEST_LOGIN` / `INGEST_PASSWORD` | plaintext env | `.env.dist:128`, `:132` | plaintext env | basic auth on `/api/v1/ingest` |
| `RSPAMD_PASSWORD` | plaintext env | `.env.dist:124` | plaintext env | rspamd controller |
| `MAILER_DSN` | plaintext env (global SMTP fallback) | `.env.dist:232` | plaintext env | Symfony Mailer; fallback when a `MailAccount` has no DSN of its own (`SmtpTransportResolver.php:55`) |
| Per-account SMTP DSN | column `mail_account.smtp_dsn_encrypted` | `src/Domain/Communication/MailAccount.php:29-30` | **encrypted at rest** (§6.2) | `SmtpTransportResolver`, `MailAccountManager` |

### 6.2 Encryption of mail account credentials — exact mechanics

**IMAP credentials are not stored by the Symfony application.** The
`mail_account` table has no IMAP password column [VERIFIED] `MailAccount.php:16-31`;
DDL `migrations/Version20250517162705.php:119`. What is stored:

| Field | File:line | Contents |
|---|---|---|
| `endpoint` | `MailAccount.php:20-21` | IMAP/SMTP host, in clear |
| `login_hash` | `MailAccount.php:21-22` | digest of the login, salted with `LOGIN_HASH_SALT` (`LoginHashGenerator.php:14`) |
| `oauth_scopes` | `MailAccount.php:22-23` | JSON, in clear |
| `email_address` | `MailAccount.php:28-29` | mailbox address, in clear |
| `smtp_dsn_encrypted` | `MailAccount.php:29-30` | base64 of `nonce‖ciphertext` — **the only encrypted field** |

Field-level encryption, implemented in
`src/Application/Communication/Smtp/SmtpDsnEncryptor.php` [VERIFIED]:
- XSalsa20-Poly1305 algorithm via `sodium_crypto_secretbox` (`:10`, `:58`).
- Format `base64(nonce ‖ ciphertext)`, random 24-byte nonce (`:12`, `:57`, `:60`).
- **Key source: `APP_SECRET`**, passed to the constructor then
  `sodium_crypto_generichash($appSecret, '', 32)` (`:14-15`, `:43`). Minimum length
  enforced for `APP_SECRET`: **12 characters** (`:29`, `:35`).
- No fallback on decryption: any failure throws `RuntimeException` (`:66-69,74,80,84,93`).
- The code's own docblock (`:22-23`): "changing `APP_SECRET` makes all existing
  encrypted DSNs unreadable. Future spec will provide a key rotation procedure."

Write: `src/Application/Communication/MailAccountManager.php:70` and `:138`.
Read: `SmtpTransportResolver.php:55`.

A second, independent field-level encryption mechanism: `EncryptedStringType`
(a Doctrine type, key from `TOTP_ENCRYPTION_KEY`, `EncryptedStringType.php:95-107`),
applied only to `User::$totpSecret` (`User.php:47`) [VERIFIED].

[INFERRED] Two field encryption mechanisms coexist with **two distinct key
sources** (derived `APP_SECRET` vs direct `TOTP_ENCRYPTION_KEY`) and two different
integration points (application service vs DBAL type). Reasoning: side-by-side reading
of `SmtpDsnEncryptor.php:43` and `EncryptedStringType.php:97`.

### 6.3 `check-no-vault-resurrection.sh`

`.github/scripts/check-no-vault-resurrection.sh` forbids, under `backend-symfony/src/`:
`VaultClient`, `MailAccountSecretResolver`, `VaultAddImapSecret`, `VaultDeleteImapSecret`,
`MailAccountOnboardCommand`, `hashicorp/vault` (`:15`); `VAULT_TOKEN`, `VAULT_ADDR` (`:35`).
Both checks `exit 1` (`:30`, `:38`) [VERIFIED].

Reason declared by the script itself, quoted verbatim:
> `# Prevent re-introduction of Vault dead code.` (`:2`)
> `# Rationale: Vault was removed (April 2026) because it was dead code since the
> 2026-03-31 n8n migration (commit b090e31). n8n now stores its own IMAP credentials
> encrypted with N8N_ENCRYPTION_KEY in ./data/n8n/.` (`:5-8`)
> `"The n8n IMAP intake holds the production credentials now."` (`:22`)

The same reason is repeated in `docker-compose.yml:205-214` [VERIFIED].

### 6.4 `SecretPolicy.php`

`src/Security/SecretPolicy.php`, a final class with no dependency [VERIFIED]:
- **`PUBLISHED_DEFAULTS`** (`:28-35`) — six literal values: `APP_SECRET=a1b2c3d4…`,
  `TOTP_ENCRYPTION_KEY=`64×`a`, `AUDIT_HMAC_KEY=`64×`b`,
  `N8N_ENCRYPTION_KEY=dev-only-change-in-production-openssl-rand-hex-32`,
  `N8N_DEFAULT_USER_PASSWORD=Scambuster2026!`, `ADMIN_PASSWORD=Un1que$trongPassword2024`.
- **`PLACEHOLDER_MARKERS`** (`:43-53`) — nine case-insensitive substrings:
  `dev-only-change`, `change-in-production`, `changeme`, `change-me`, `changthis`,
  `change-this`, `placeholder`, `example`, `insecure`.
- `validate(array $secrets, bool $isProd)` (`:63`) — **returns `[]` immediately if
  `$isProd` is false** (`:67-69`). Ignores `null` values (`:75-77`).
- `reasonFor(string $value)` (`:93`) rejects, in order: empty string (`:95`);
  a `hash_equals` match against **any one** of the six defaults, including one
  belonging to another variable (`:99-105`); a value made of a single repeated
  character, via `strspn` (`:107-110`); the presence of a placeholder marker
  (`:114-118`); a `your-`/`your_` prefix (`:120-122`).
- Declared intent (`:8-18`): ".env.dist ships valid-but-globally-known keys so the
  stack boots out of the box… It only *strengthens* posture and never enforces outside
  production, so dev/test/e2e keep booting on defaults."

### 6.5 `CheckSecretsCommand.php`

`src/UI/Console/CheckSecretsCommand.php`, command `app:security:check-secrets` (`:27`) [VERIFIED]:
- **`CHECKED`** (`:37-45`) — **seven** variables: `APP_SECRET`, `TOTP_ENCRYPTION_KEY`,
  `AUDIT_HMAC_KEY`, `JWT_PASSPHRASE`, `N8N_ENCRYPTION_KEY`, `N8N_DEFAULT_USER_PASSWORD`,
  `ADMIN_PASSWORD`.
- `$isProd = ($appEnv === 'prod')` from `%kernel.environment%` (`:48-49`, `:58`).
- `readEnv()` (`:91-104`): `$_SERVER` → `$_ENV` → `getenv()`; an explicit empty string
  is kept so that the policy flags it (`:88-89`).
- Violations → "Refusing to boot: insecure secret values detected." + `Command::FAILURE`
  (`:76-83`). Docblock: the prod entrypoint runs it after the environment has been
  materialised and before the migrations (`:19-21`).

**Coverage** [VERIFIED]: 7 variables checked out of the 24 secret-looking variables
listed in §6.6. `POSTGRES_PASSWORD`, `LOGIN_HASH_SALT`, `RSPAMD_PASSWORD`,
`INGEST_PASSWORD`, `HONEYPOT_IMAP_PASSWORD`, `LLM_API_KEY`, `OIDC_CLIENT_SECRET`,
`TAXII_API_KEY`, `MISP_API_KEY` are not in `CHECKED`.

### 6.6 Secret-looking variables in `.env.dist`

| Line | Variable | Default value | Nature |
|---|---|---|---|
| 39 | `APP_SECRET` | `a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4` | plausible-looking (32 hex); in `PUBLISHED_DEFAULTS` |
| 49 | `POSTGRES_PASSWORD` | `postgres` | plausible-looking; **neither in `PUBLISHED_DEFAULTS` nor in `CHECKED`** |
| 67 | `JWT_PASSPHRASE` | `scambuster-jwt-dev-passphrase-2026` | plausible-looking; in `CHECKED`, **not in `PUBLISHED_DEFAULTS`** |
| 81 | `OIDC_CLIENT_SECRET` | *(empty)* | empty |
| 117 | `LOGIN_HASH_SALT` | `scambuster-salt-dev-2026` | plausible-looking; not in `CHECKED` |
| 124 | `RSPAMD_PASSWORD` | `rspamd` | plausible-looking; not in `CHECKED` |
| 128 | `INGEST_LOGIN` | `user@example.com` | placeholder (`example`) |
| 132 | `INGEST_PASSWORD` | `Un1que$$trongPassword2024` | plausible-looking; `$$` is the Compose escape for `$`, so the effective value **equals the `ADMIN_PASSWORD` default** in `PUBLISHED_DEFAULTS`; `INGEST_PASSWORD` is not in `CHECKED` |
| 173 | `N8N_ENCRYPTION_KEY` | `dev-only-change-in-production-openssl-rand-hex-32` | placeholder; in `PUBLISHED_DEFAULTS` and in `CHECKED` |
| 178 | `N8N_DEFAULT_USER_PASSWORD` | `Scambuster2026!` | plausible-looking; in both |
| 193 | `HONEYPOT_IMAP_PASSWORD` | `your-app-password-here` | placeholder (`your-`); not in `CHECKED` |
| 232 | `MAILER_DSN` | `null://null` | no-op transport |
| 308 | `LLM_API_KEY` | `sk-your-api-key-here` | placeholder; not in `CHECKED` |
| 352 | `TOTP_ENCRYPTION_KEY` | 64 × `a` | plausible-looking format; in both |
| 360 | `AUDIT_HMAC_KEY` | 64 × `b` | plausible-looking format; in both |
| 396 | `TAXII_API_KEY` | *(empty)* | empty (= feature disabled, `services.yaml:67`) |
| 310, 407, 412 | `ANTHROPIC_API_KEY`, `MISP_API_KEY` (commented out) | `sk-ant-…`, `your-misp-api-key` | placeholders |

`ADMIN_PASSWORD` appears in `SecretPolicy::PUBLISHED_DEFAULTS:34` and
`CheckSecretsCommand::CHECKED:44` but **has no assignment line in `.env.dist`**
[VERIFIED].

---

## 7. Persistence, data model, personal data

### 7.1 Doctrine entities by context

**Communication** (`src/Domain/Communication/`): `Conversation`→`conversation` (`:9-10`),
`Message`→`message`, `Attachment`→`attachment` (`:9-10`), `ObservedIoc`→`observed_ioc`,
`MessageVector`→`message_vector` (`:9-10`), `MailAccount`→`mail_account` (`:9-10`),
`Persona`→`persona` (`:10`), `ScamType`→`lkp_scam_type` (`:12`), `Channel`→`lkp_channel`
(`:10`), `Direction`→`lkp_direction` (`:10`), `ConversationChannel` (`:10`),
`Ttp`→`lkp_ttp` (`:10`), `TtpObservation`→`ttp_observation` (`:10`).

**CampaignRadar**: `Campaign`→`campaign` (`:13`), `CampaignRule` (`:13`),
`MessageCampaign` (`:12`), `ActorProfile`→`actor_profile` (`:10-11`).

**Audit**: `AuditLog`→`audit_log` (`AuditLog.php:18`), docblock "Immutable after
creation (append-only log)" (`:16`).
**LLM**: `LlmUsageRecord`→`llm_usage` (`:18`).
**Prompt**: `PromptOverride` (`:10-16`), `PromptCanaryJob` (`:10-16`).
**Scambaiting**: `BanditConvergenceLog` (`:10`).
**User**: `User`→`app_users` (`:16`), `RefreshToken` (`:10-16`, the raw token is never
persisted, `:13`).
**Infrastructure**: `PersonaPerformanceStatsEntity`→`persona_performance_stats` (`:20`).

**Tables managed in raw SQL, outside Doctrine** [VERIFIED]:
`indicator` (`migrations/Version20260307190000.php:25-39`; the migration itself says
"non-Doctrine managed, used by IocHandler via raw SQL" `:11,:19`; write
`src/Application/Communication/IocUpsertService.php:294-315`);
`threat_actor_psych_profile` (`migrations/Version2026070600000000.php:28-45`);
`ioc_context` (`Version20260405120000.php:20`); `ioc_analyst_feedback`
(`Version2026070600100000.php:27`); `threat_actor_cluster` /
`threat_actor_cluster_ioc` (`Version20260409100000.php:75-85`).

`PromptInjectionAnalysis` is **not** an entity: a readonly value object
(`src/Domain/Communication/PromptInjectionAnalysis.php:14`) serialised into the
`message.injection_analysis` JSON (`Message.php:20-21`) [VERIFIED].

### 7.2 Fields containing third-party personal data

| Entity / table | Field | File:line | Contents |
|---|---|---|---|
| `Message` | `bodyText` (NOT NULL) | `Message.php:57-58` | full body of the adversary's email |
| `Message` | `bodyHtml` | `Message.php:59-60` | full HTML body |
| `Message` | `subject` | `Message.php:55-56` | free text, may contain names |
| `Message` | `headers` (JSON, NOT NULL) | `Message.php:61-62` | From/To/Cc, Message-ID, `Received` chain → **addresses, display names, originating IP addresses** |
| `Message` | **`rawSource`** | `Message.php:23-24`; written `IngestHandler.php:180` | **full RFC822 source in base64, verbatim** |
| `Message` | `externalMessageId` | `Message.php:26-27` | provider identifier |
| `Message` | `urlAnalysis` (JSON) | `Message.php:16-17` | extracted URLs |
| `Message` | `injectionAnalysis` (JSON) | `Message.php:20-21` | injection findings, contains excerpts of the text |
| `Attachment` | `filename` | `Attachment.php:27-28` | file names |
| `Attachment` | **`ocrText`** | `Attachment.php:41-42` | **OCR text of attachments** — names, IBANs, identity documents, phone numbers |
| `Attachment` | `metadata` (JSON) | `Attachment.php:16-18` | free-form metadata |
| `ObservedIoc` | `context` (`context_observation`, JSON) | `ObservedIoc.php:28-29` | text surrounding the IOC |
| **`indicator`** (raw SQL) | `value`, `value_norm` | `IocUpsertService.php:294-307`; DDL `Version20260307190000.php:25-39` | **the IOC values themselves: email addresses, IPs, phone numbers, IBANs, URLs, wallet addresses** |
| `indicator` | `enrichment` (JSON) | `IocUpsertService.php:296,310` | enrichment payload |
| `MessageVector` | `embedding` (JSON) | `MessageVector.php:18-19` | vector derived from the message content |
| `TtpObservation` | verbatim evidence excerpt + offsets | entity `TtpObservation.php:10`; described in `docs/09_dpia_template.md:38` | **verbatim excerpts from inbound emails** |
| `ActorProfile` | `styleDna` (JSON) | `ActorProfile.php:33-34` | stylometric fingerprint of a third party |
| `ActorProfile` | `infraDna` (JSON) | `ActorProfile.php:35-36` | infrastructure fingerprint (domains, IPs) |
| `Campaign` | `actorGuess` (TEXT) | `Campaign.php:26` | guess at the actor's identity |
| `Campaign` | `profileYaml` (TEXT) | `Campaign.php:48` | profile document |
| **`threat_actor_psych_profile`** (raw SQL) | `behavioural_summary`, `victim_targeting`, `dominant_lever`, `secondary_levers`, `escalation_pattern`, `dominant_stimulus` | `Version2026070600000000.php:29-36` | **LLM-generated psychological profile of a third party**, plus a description of victim targeting |
| `AuditLog` | `ipAddress` (VARCHAR 45) | `AuditLog.php:61` | **IP address** of the actor |
| `AuditLog` | `actorId`, `resourceId`, `details` (JSON) | `AuditLog.php:49,59,55` | actor identity, resource identity, free-form event payload |
| `MailAccount` | `emailAddress`, `endpoint` | `MailAccount.php:28-29,20-21` | operator mailbox, in clear |
| `User` | `email` / `passwordHash` / `totpSecret` | `User.php:29-30 / 33-34 / 47-48` | operator (first party) / hashed / encrypted |

### 7.3 Attachment storage

**The binary content of attachments is not stored by the Symfony application**
[VERIFIED]. The `attachment` table has no blob column (DDL
`migrations/Version20250517162705.php:29`). The entity carries `s3Key` (`:35-36`, setter
`:137-140`) and `encKeyId` (`:37-38`, setter `:142-145`); a search for `setS3Key`
/ `s3_key` returns **only the declaration and the setter themselves** — no caller
in `src/`. `AttachmentHandler.php` contains neither `s3Key`, nor `S3`, nor `filesystem`,
nor `file_put_contents`, nor `Storage`.

Persisted per attachment: `filename`, `mime_type`, `size_bytes`, `content_hash`
(BYTEA), `ocr_text`, `metadata` JSON, `vector_id`. [INFERRED] The only attachment
content present in the database is `ocr_text`, **plus the attachment bytes themselves
included in `message.raw_source`**, since the RFC822 source is stored in full
(`IngestHandler.php:180`, extraction `:198`). Reasoning: the complete RFC822 includes
the base64-encoded MIME parts.

No attachment volume is declared in `docker-compose.yml` [VERIFIED].

### 7.4 `.eml` files

1. **In the database**: yes, `message.raw_source` (nullable TEXT, base64 RFC822),
   `Message.php:23-24`, written `IngestHandler.php:180`.
2. **On disk in the repository**: 99 `.eml` files, all test fixtures
   [VERIFIED]:

| Directory | Count | Nature |
|---|---|---|
| `backend-symfony/tests/Smoke/CialdiniMirrorFixtures/` | 34 | emails named by persuasion lever × language (`01_Authority_director_EN.eml`, `12_Secrecy_between_us_FR.eml`, `26_Urgency_deadline_4h_DE.eml`, `31_Secrecy_between_us_ES.eml`) — used by `CialdiniMirrorSmokeCommand.php` |
| `backend-symfony/tests/Smoke/ReplyObjectiveFixtures/` | 65 | fixtures for `ReplyObjectiveSmokeCommand.php` |

No production `.eml` corpus is present in the repository [VERIFIED].

### 7.5 Embeddings / vectors

| Aspect | Value | Evidence |
|---|---|---|
| Table | `message_vector` | `migrations/Version20250518105021.php:21` |
| Storage type | `embedding JSON NOT NULL` — plain PostgreSQL JSON column | same line; `MessageVector.php:18-19` |
| Dimension | not fixed by the schema — `dim INT NOT NULL` per row | same lines |
| **pgvector** | **not used**: no `CREATE EXTENSION vector`, no `vector(n)` type, no `ivfflat`/`hnsw` index in the 65 migrations | search over the whole of `migrations/` |
| Foreign key | **none**. `message_vector.vector_id` is just a UUID primary key; `message.vector_id` and `attachment.vector_id` are nullable UUIDs **with no FK constraint** | `Version20250518105021.php:21`; `Version20250517162705.php:134,29` |
| Generation | `app:generate-embeddings`, scheduled | `scheduler.sh:62` |

### 7.6 Retention as actually implemented

#### `PurgeRgpdCommand` — `app:purge:rgpd` (`:14`)
33 lines, **no options** (no `--dry-run`, no configurable window). Delegates to
`src/Application/Communication/PurgeService.php` [VERIFIED].

| Method | Criterion | Action | Duration and location |
|---|---|---|---|
| `softDeleteOldOutboundConversations()` (`PurgeService.php:23`) | `status = CLOSED` AND `tsLast < now-6months` AND `deletedAt IS NULL` (`:29-33`) | `softDelete()` (`:39`) → sets `deleted_at`. **No field is cleared, no content is overwritten or anonymised** | **Hard-coded** `'-6 months'` (`PurgeService.php:25`) |
| `hardDeleteOldInboundConversations()` (`:53`) | `tsLast < now-12months` AND `deletedAt IS NOT NULL` (`:58-61`) | `remove()` (`:67`) + `flush()` (`:69`) → physical DELETE | **Hard-coded** `'-12 months'` (`:55`) |

Cascade scope: `message.conv_id` `onDelete: CASCADE` (`Message.php:45`),
`attachment.msg_id` CASCADE (`Attachment.php:25`), `observed_ioc.msg_id` CASCADE
(`ObservedIoc.php:24`).

**`app:purge:rgpd` appears nowhere in `infra/docker/backend/scheduler.sh`**
[VERIFIED]: neither in the declared task list (`:16-24`), nor in the loop (`:40-147`).
A search for `purge:rgpd` in the repository returns only `Makefile`, `CHANGELOG.md`
and the documentation.

#### `WeeklyCleanupCommand` — `app:cleanup:weekly` (`:17`)
142 lines, raw SQL through DBAL. Options (`:31-35`): `--conv-days` (default `90`),
`--llm-days` (`180`), `--canary-days` (`30`), `--dry-run` [VERIFIED].

| Step | Table | Criterion | Action | Duration |
|---|---|---|---|---|
| 1 (`:83-105`) | `conversation` | `status='closed' AND ts_last < threshold AND deleted_at IS NULL` (`:86-88`) | `UPDATE … SET deleted_at = NOW()` (`:96-99`) — soft delete only, **no content deleted or anonymised** | CLI option, default **90 days** (`:32`) |
| 2 (`:107-122`) | `llm_usage` | `created_at < threshold` (`:109`) | `DELETE` (`:116`) | default **180 days** (`:33`) |
| 3 (`:128-141`) | `prompt_canary_job` | `status IN ('succeeded','failed') AND created_at < threshold` (`:130`) | `DELETE` (`:137`); `pending`/`running` never touched (`:124-127`) | default **30 days** (`:34`) |

**Automated**: `scheduler.sh:125-130` runs `app:cleanup:weekly --no-interaction`
on Sunday ≥04:00 UTC, **with no options** — therefore at the 90/180/30 values [VERIFIED].

**Consequence in the code** [INFERRED]: the automatic weekly run soft-deletes closed
conversations at **90 days**, while `app:purge:rgpd` — never scheduled — is the only
routine that applies the 6-month soft delete and **the only one in the whole source
code that physically deletes conversation content**.
Reasoning: comparison of `WeeklyCleanupCommand.php:96-99` (UPDATE only) and
`PurgeService.php:67` (`remove()`), cross-checked with the absence of `purge:rgpd` in
`scheduler.sh`.

#### `CloseStaleConversationsCommand` — `app:close-stale-conversations` (`:30`)
178 lines. **Deletes and anonymises nothing**: it transitions `status` from `OPEN` to
closed through `ConversationClosureService::closeConversationsBatch()` (`:135`) [VERIFIED].
Closing criteria (`shouldClose()`, `:152-177`): inactivity > `timeout_hours` (`:157-162`),
`turnsCount >= max_turns` (`:165-167`), age > `max_duration_days` (`:170-173`).
Durations are **PHP constants**: `src/Application/Communication/ConversationLifecycleConfig.php:22-55`
(`POLICIES`) and `:58` (`DEFAULT_POLICY` = 72 h / 25 turns / 14 d). Examples: `ROMANCE`
336 h / 50 / 60 d (`:24`), `TECH_SUPPORT` 24 h / 20 / 5 d (`:43`), `PHISHING` 48 h / 15 / 7 d (`:40`).
Declared reason for the choice (`:14-16`): "Stored as PHP constants (not DB) because the number
of scam types is small and fixed. No CRUD needed."
**Automated**: `scheduler.sh:47`, on every outer loop iteration (~6 h).

### 7.7 Entities / fields that no purge routine reaches

| Not purged | Evidence |
|---|---|
| **`indicator` table** (IOC values: email addresses, IPs, phone numbers, IBANs, URLs) | no `DELETE FROM indicator`; out of reach of the cascade (no FK to `message`/`conversation`) |
| **`message_vector`** (embeddings) | no FK to `message` (`Version20250518105021.php:21`) → the conversation delete cascade cannot reach it; no routine clears it |
| **`threat_actor_psych_profile`** (behavioural summaries, victim targeting) | FK to `threat_actor_cluster` (`Version2026070600000000.php:49-52`), not to conversations; no time-based purge |
| `threat_actor_cluster`, `threat_actor_cluster_ioc` | no routine |
| `actor_profile` (`style_dna`, `infra_dna`) | no routine |
| `campaign` (`actor_guess`, `profile_yaml`), `campaign_rule`, `message_campaign` | no routine |
| `ttp_observation` (verbatim excerpts) | FK to `message`; reached only by the physical deletion of a conversation, which only `app:purge:rgpd` performs and which is not scheduled |
| `audit_log` (including `ip_address`) | no routine; `gdpr-record-of-processing.md:54` says so itself ("not auto-purged") |
| `ioc_context`, `ioc_analyst_feedback` | no routine |
| `bandit_convergence_log`, `persona_performance_stats` | no routine |
| `mail_account` (including `email_address`) | no routine; deactivation only (`MailAccount.php:71-74`) |
| `app_users`, `refresh_token` | no time-based purge command found |
| `message.raw_source`, `body_text`, `headers`, `attachment.ocr_text` **on soft-deleted rows** | the soft delete only sets `deleted_at` (`PurgeService.php:39`; `WeeklyCleanupCommand.php:96`); these columns keep their full content until a hard delete |
| **Anonymisation** | **no anonymisation, pseudonymisation or redaction routine exists**. The only two write operations are "set `deleted_at`" and "physical DELETE" |
| PostgreSQL backups | `find /backups -name 'scambuster_*.sql.gz' -mtime +7 -delete` (`scheduler.sh:112`) — 7-day rotation of full dumps, which contain everything listed above |

### 7.8 Announced retention versus implemented retention

| Data | Announced (doc:line) | Implemented (code:line) | Match |
|---|---|---|---|
| Conversation — soft delete | "6 months soft-delete" — `docs/compliance/gdpr-record-of-processing.md:53` | **Two values coexist**: `-6 months` (`PurgeService.php:25`, the manual command) and `90` days (`WeeklyCleanupCommand.php:32`, the scheduled command) | Two implementations, two values |
| Conversation — hard delete | "→ 12 months hard-delete" — `gdpr-record-of-processing.md:53` | `-12 months` (`PurgeService.php:55`) | Value matches |
| Mechanism | "`PurgeService` (`app:cleanup:weekly`, **automatic**)" — `gdpr-record-of-processing.md:53` | `app:cleanup:weekly` **does not use** `PurgeService` (raw SQL, `WeeklyCleanupCommand.php:23,95`) and performs **no** hard delete. `PurgeService` is reached only by `app:purge:rgpd`, which is absent from `scheduler.sh` | Mechanism differs from the announcement |
| Email content | "6 months max, **then anonymization**" — `docs/09_dpia_template.md:33` | No anonymisation code | Not implemented |
| Retention control | "PurgeService: **anonymization at 6 months**, hard delete at 12 months" — `docs/09_dpia_template.md:146` | `PurgeService.php:23-45` performs a **soft** delete, not anonymisation | Anonymisation not implemented |
| Enforcement | "enforced via automated purge service" — `docs/09_dpia_template.md:100` | Only the 90/180/30 cleanup is automated (`scheduler.sh:127`) | Partial |
| Email metadata | "aligned with email content retention" — `docs/09_dpia_template.md:35` | `message.headers`, `message.raw_source`; deleted only by the unscheduled command | Through the unscheduled command |
| LLM interaction metadata | "aligned with email content retention" — `docs/09_dpia_template.md:36` | `llm_usage` deleted at **180 days** (`WeeklyCleanupCommand.php:33,116`), independently of the conversation lifecycle | Independent rule |
| TTP evidence excerpt | "aligned with email content retention" — `docs/09_dpia_template.md:38` | reached only by the physical deletion of a conversation | Through the unscheduled command |
| TTP technique code | "**indefinite** (intelligence value, as IOCs)" — `docs/09_dpia_template.md:38` | no purge | Matches |
| Audit log | "12 months (policy); … **not auto-purged**" — `gdpr-record-of-processing.md:54` | no purge | Matches (the documentation states that it is manual) |
| Backups | "daily pg_dump (02:00 UTC), **7-day retention**, verification" — `docs/09_dpia_template.md:160` | `scheduler.sh:100-122`; deletion `:112`; size check `:109` | Matches |
| **IOC values (`indicator`)** | no retention line in either document | no purge | **Not covered by the documentation** |
| **Embeddings (`message_vector`)** | no retention line | no purge, no FK | **Not covered** |
| **Psychological profiles** | no retention line | no purge | **Not covered** |
| Prompt canary jobs | no retention line | deleted at 30 days (`WeeklyCleanupCommand.php:34,130`) | Implemented, not documented |

---

## 8. Logging

### 8.1 Components of the audit subsystem

| Component | Path | Lines |
|---|---|---|
| Entity | `src/Domain/Audit/AuditLog.php` | 207 |
| Event taxonomy (enum) | `src/Domain/Audit/AuditEventType.php` | 95 |
| Writer | `src/Application/Audit/AuditLogger.php` | 177 |
| HMAC chainer | `src/Application/Audit/AuditHmacChainer.php` | 89 |
| Verification command | `src/UI/Console/VerifyAuditChainCommand.php` | 129 |
| Read endpoint | `src/UI/Http/Monitoring/AuditController.php` | 93 |
| Migration (chain + backfill) | `migrations/Version2026041200100000.php` | 121 |
| Runbook | `docs/runbooks/audit-hmac-key-rotation.md` | 53 |
| SIEM severity mapping | `src/Domain/Audit/SiemSeverityMap.php` | 135 |

### 8.2 Schema of the `audit_log` table

Columns [VERIFIED] `AuditLog.php`: `id` (`:24-27`), `event_type` string(50) indexed
(`:19,29-30`), `created_at` indexed (`:20,32-33`), `prev_hmac` nullable BYTEA (`:36-37`),
`row_hmac` nullable BYTEA (`:39-40`), `actor_type` (`:47-48`), `actor_id` indexed
(`:21,49-50`), `action` (`:51-52`), `outcome` (`:53-54`), `details` JSON (`:55-56`),
`resource_type` (`:57-58`), `resource_id` (`:59-60`), `ip_address` VARCHAR(45) (`:61-62`),
`trace_id` (`:63-64`). `created_at` is set in the constructor (`:67`).

### 8.3 HMAC chaining scheme — exact

- Algorithm: `hash_hmac('sha256', $prevHmacBin . $canonical, $key, true)` — raw
  32-byte binary output [VERIFIED] `AuditHmacChainer.php:87`.
- Documented formula: `row_hmac = HMAC-SHA256(key, prev_hmac_bin || canonical_json)` (`:16`).
- Canonicalisation: `ksort()` then `json_encode(..., JSON_THROW_ON_ERROR |
  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` (`:84-85`).
- Fields covered (`AuditLog::toCanonicalRow()`, `:174-186`): `event_type`, `actor_type`,
  `actor_id`, `resource_type`, `resource_id`, `action`, `outcome`, `details`,
  `ip_address`, `trace_id`, `created_at` (ATOM format). **`id`, `prev_hmac` and `row_hmac`
  are excluded**; declared reason `:168-173` (the `id` is 0 before `flush()`).
- Key: `AUDIT_HMAC_KEY`, 64 hex → `hex2bin` (`:19-20,41,59`), wired in
  `config/services.yaml:511-512`. Length-64 validation + `ctype_xdigit` (`:41`).
- Missing/invalid key: `RuntimeException` if `$environment === 'prod'` (`:42-47`);
  otherwise WARNING and `enabled = false`, `compute()` returns `''` (`:49-58,80-82`).
- Head of the chain at write time: `SELECT row_hmac FROM audit_log ORDER BY id DESC LIMIT 1`
  (`AuditLogger.php:157-159`), computed before `persist()`/`flush()` (`:83-89`).
- Backfill of earlier rows: `migrations/Version2026041200100000.php:60-114`,
  batches of 500, same formula (`:91`).

### 8.4 Chain verification

`app:audit:verify-chain` (`VerifyAuditChainCommand.php:28`) [VERIFIED]:
- Walks `id ASC` in batches of 1000 (`:33,52-58`), recomputing with the same chainer (`:89`).
- **Rows whose `row_hmac` is NULL/empty are shown as "skipped" and counted as
  verified, not as gaps** (`:70-76`).
- On a gap: message `ROW_HMAC MISMATCH expected=… actual=…`, counter incremented
  (`:91-99`); **the loop continues and `$prevHmac` moves on to the stored value** (`:101`).
- Exit code 0 / 1 (`:114`).
- Scheduling: daily ~02:00 UTC (`scheduler.sh:91-97`); on failure the script emits
  `CRITICAL: audit:verify-chain FAILED — possible tamper detected` (`:95`) **and the loop
  continues** (`|| echo`).

### 8.5 Writer behaviour on failure

Blocking event list (`AuditLogger.php:30-39`): `AUTH_SUCCESS`, `AUTH_FAILURE`,
`AUTH_TOKEN_EXPIRED`, `AUTH_LOGOUT`, `AUTH_TOKEN_REUSE_DETECTED`, `INJECTION_DETECTED`.
A SIEM export failure on a blocking event → `RuntimeException` rethrown (`:113-119`);
otherwise a warning (`:123-126`). Any throwable inside `log()`: rethrown for blocking
events (`:133-135`), otherwise `logger->warning('[AuditLogger] Failed to
persist audit event')` (`:137-141`) [VERIFIED].

### 8.6 Actions that produce an audit entry

Emission sites found (excerpts — the full table is in the exploration results) [VERIFIED]:
authentication (`AuditAuthListener.php:33,44,56`; `LoginController.php:84,122,143,157,178`;
`TotpLoginController.php:92,136,161,185,205`; `AuthService.php:84,101,113,136,155`;
`OidcCallbackController.php:70,82`); ingestion (`IngestHandler.php:220` MESSAGE_INGESTED;
`IngestPostProcessor.php:209` INGEST_PRE_FILTER_HIT, `:565` INJECTION_DETECTED, `:663`
RATE_LIMIT_EXCEEDED; `IngestController.php:119`); cadence (`ReplyCadenceService.php:208`);
classification (`ScamClassificationHandler.php:144`); reply (`ReplyHandler.php:360`
REPLY_GENERATED; `ReplyCompositionService.php:289` REPLY_SENT; `RetryCoordinator.php:419`
REPLY_RETRY, `:443` REPLY_REJECTED, `:227` LLM_LEAK_BLOCKED); IOC/TTP
(`IocUpsertService.php:352`, `TtpHandler.php:165`, `SubmitIocFeedbackController.php:67`);
lifecycle (`ConversationClosureService.php:90,177`); bandit
(`PersonaOptimizer.php:188,238,284,389`); budget (`BudgetThresholdNotifier.php:74`);
kill switch (`ToggleLlmKillSwitchController.php:79`); exports (`ExportMispController.php:198`,
`CampaignStixExportHandler.php:65`); configuration (`CreatePersonaController.php:147`,
`UpdatePersonaController.php:159,174`, `UpsertPromptOverrideController.php:59`,
`DeletePromptOverrideController.php:39`, `RequestPromptCanaryController.php:75`);
users (`UserCreateCommand.php:108`, `UserSetPasswordCommand.php:86`,
`UserPromoteCommand.php:83`).

Enum case defined **with no emission site in `src/`**: `LLM_SIGNATURE_STRIPPED`
(`AuditEventType.php:78`; referenced only by `CefFormatter.php:71`) [VERIFIED].

### 8.7 Sensitive actions with no call to the audit service

Factual finding: no reference to `auditLogger` / `AuditLogger` in these files [VERIFIED].

`DeleteConversationController.php`, `DeleteMessageController.php`,
`UploadAttachmentController.php`, `DownloadAttachmentController.php`,
`ExportIocsStixController.php`, `ExportConversationStixController.php`,
`ExportIocsFeedController.php`, `SendEmailController.php`, `LogoutController.php`,
`RefreshController.php`, `TotpSetupController.php`, `TotpVerifyController.php`,
the 4 TAXII controllers, `ExportClusterStixController.php`.

Aggregate: **145 `*Controller.php` files under `src/UI/Http`; 12 reference
`auditLogger`** [VERIFIED].

### 8.8 Application logging (Monolog)

`config/packages/monolog.yaml` [VERIFIED]: declared channels `deprecation`, `scambaiting`
(`:2-4`). Processors: `TraceIdProcessor` (`:7-9`), **`PiiMaskingProcessor`** (`:10-12`).

| Env | Handler | Destination | Level |
|---|---|---|---|
| dev | `main` / `llm` / `scambaiting` | `rotating_file` (`max_files` 7) | debug / debug / info (`:17-34`) |
| dev | `console` | console | (`:43-46`) |
| test | `main` + `nested` | `fingers_crossed` (`action_level: error`) + `rotating_file` (`max_files` 3) | debug (`:52-61`) |
| **prod** | `main` | `fingers_crossed`, `action_level: error`, `buffer_size: 50`, channels `!event,!doctrine,!llm` | (`:66-72`) |
| **prod** | `nested` / `llm` / `deprecation` | `stream` → **`php://stderr`**, JSON formatter | debug / info (`:73-92`) |

Rotation: only through `rotating_file` in dev/test. In prod, output goes to stderr;
**no `logging:` block, no `max-size` and no `max-file` in `docker-compose.yml` or
`docker-compose.prod.yml`**; no volume mounted for `var/log` [VERIFIED].

PII masking (`src/Infrastructure/EventListener/Security/PiiMaskingProcessor.php`):
email (`:24`), last IPv4 octet (`:26`), IBAN (`:29`), ETH wallet `0x`+40 hex
(`:31`), card 4×4 digits (`:35`). **Applies to `message` and `context` only**
(`:38-40`); the docblock states "Does NOT affect the audit_log database table" (`:18-19`).

### 8.9 Immutability

- The entity docblock declares it append-only (`AuditLog.php:16`); there is no setter
  for any content field (only `setPrevHmac`/`setRowHmac`, `:141,155`) [VERIFIED].
- The migration states that the PostgreSQL `REVOKE` on UPDATE/DELETE "is a
  post-deployment ops step documented in `docs/runbooks/audit-hmac-key-rotation.md`, not
  embedded in this migration" (`Version2026041200100000.php:20-24`) [VERIFIED].
- **`rg -n "REVOKE" docs/` returns no result**: the referenced step is present neither
  in that runbook (all 53 lines read) nor anywhere else under `docs/` [VERIFIED].
- No database trigger and no database rule in `migrations/` or `config/` [VERIFIED].
- [INFERRED] Integrity evidence therefore rests entirely on the HMAC chain and the daily
  verification command; no WORM storage is configured.
  Reasoning: no applied REVOKE, no trigger, no WORM backend.

### 8.10 Raw content in the logging paths

| Path | What is recorded | Evidence |
|---|---|---|
| `LlmUsageRecord` (`llm_usage`) | provider, model, purpose, `prompt_tokens`, `completion_tokens`, cost, `conversation_id`, date. **No prompt or completion text field** | `src/Domain/LLM/LlmUsageRecord.php:18,28-38,40-52` |
| `PipelineTrace` | conversation, persona, scam type, language, durations, cost, attempts, approved, fallback, components array. **No text**. Stored in the `message.headers` JSON under `pipeline_trace` | `src/Domain/LLM/PipelineTrace.php:11,119-133`; `PipelineTraceHandler.php:13` |
| `ComponentTrace.output` as actually populated | `prompt_builder`: `{text_length, word_count}` (`RetryCoordinator.php:135-138`); `policy_guard`: `{approved, flags, attempt}` (`:155-159`); `reply_validator`: `{approved, reasons, attempt}` (`:279-283`); `ioc_scorer`: `{score, threshold}` (`:302-305`) | no raw text |
| OpenAI client log | `{provider, model, latency_ms, input_messages(count), output_length(strlen), usage}` | `OpenAIClient.php:73-80` |
| Anthropic client log | same | `AnthropicClient.php:104-112` |
| **LLM completion text written to the logs** | `ScamClassifier`: `'response' => substr($response, 0, 500)` when JSON validation fails | `src/Application/LLM/ScamClassifier.php:69` |
| **LLM completion text written to the logs** | `ConversationAnalyzer`: `substr($llmResponse, 0, 500)` | `src/Application/LLM/ConversationAnalyzer.php:852` |
| **LLM completion text written to the logs** | `ContextualEnricher`: `substr($response, 0, 200)` | `src/Application/LLM/ContextualEnricher.php:68` |
| **Full LLM completion written to the logs** | `ReplyValidator`: `'response' => $response` — **not truncated** — on `JsonException` | `src/Application/LLM/ReplyValidator.php:144` |

### 8.11 Metrics

`MetricsController` — `GET /api/metrics` (`:27`), `text/plain; version=0.0.4` (`:128`),
**no `#[IsGranted]` attribute on the class** [VERIFIED]. Exported series:
`scambuster_info{version}` (`:57-59`), `scambuster_conversations_total{status}` (`:63-71`),
`scambuster_messages_total{direction}` (`:75-80`), `scambuster_iocs_total` (`:84-88`),
`scambuster_iocs_unique` (`:91-93`), `scambuster_kill_switch` (`:97-100`),
`scambuster_health_check{service}` (`:104-112`), `scambuster_convergence_ratio` (`:118-123`).

Prometheus: `infra/monitoring/prometheus/prometheus.yml`, rules
`alert.rules.yml` (alerts `ScamBusterKillSwitchActive`, `ScamBusterDependencyDown`,
`ScamBusterMetricsUnreachable`, `ScamBusterIngestStalled`, and following). Grafana:
`infra/monitoring/grafana/dashboards/scambuster-security.json`.

SIEM export: **`SIEM_PROVIDER=none` by default** (`.env.dist:421`); options `file`
(NDJSON) or `syslog` (udp/tcp + CEF) (`.env.dist:425-439`) [VERIFIED].

---

## 9. Tests, CI, scans

### 9.1 Backend test counts (`*Test.php`)

| Directory | `*Test.php` | Total `.php` |
|---|---|---|
| `tests/Unit` | 315 | 317 |
| `tests/Integration` | 107 | 108 |
| `tests/Functional` | 97 | 98 |
| `tests/EndToEnd` | 53 | 55 |
| `tests/Smoke` | 0 | 0 (`.eml` fixtures, `guard-baseline.json`, `.sha256`) |
| `tests/Fake` | 0 | 3 (`FakeLLMClient`, `FakeCanaryJobRepository`, `FakeCanarySmokeRunner`) |
| **Total** | **572** | — |

`Unit` sub-directories: Application 205, Domain 49, UI 28, Infrastructure 22,
Security 5, EventListener 4, Command 2.

Frontend: **138** `*.test.ts(x)` files under `frontend-react/src`, framework
**Vitest** (`package.json:12`, config `vite.config.ts:23-42`), `jsdom`, `msw`,
`vitest-axe`. **No coverage threshold configured** [VERIFIED].

### 9.2 PHPUnit configuration

`phpunit.xml.dist`: suites `integration`, `functional`, `unit`, `endtoend` (`:27-39`).
Coverage: source `src` (`:44`), exclusions `src/DataFixtures`, `src/Service`,
`src/Kernel.php`, 3 Preprod files, **the whole `src/UI/Console` directory** (`:53`), and
7 `src/Command/*` files (`:54-61`) [VERIFIED].

`phpunit.ci.xml`: `failOnWarning="false"`, `failOnDeprecation="false"` (`:9-10`);
the `SymfonyExtension` extension deliberately removed (`:2,13`); suites `integration`
(4 exclusions), `unit`, `endtoend` (2 exclusions) — **no `functional` suite**, declared
reason `ci.yml:103-108` ("~855 controller tests never ran" if it were named) [VERIFIED].

**No minimum coverage threshold is configured in either file** [VERIFIED].

### 9.3 LLM double

The LLM client is bound to a double in both test environments [VERIFIED]:
`config/packages/test/llm.yaml` and `config/packages/e2e/llm.yaml` →
`LLMClientInterface: '@App\Tests\Fake\FakeLLMClient'`.
`tests/Fake/FakeLLMClient.php` returns canned responses based on the shape of the prompt
or on the `purpose` option (`:17,39,46,63,71,82`), defaulting to a fixed French reply (`:91-96`).

**The only real-LLM path in CI is the scheduled GUARD workflow**, conditional on
`LLM_API_KEY` and run with `APP_ENV=dev` precisely because `test` binds the double —
`guard-nightly.yml:61-64,92-95` [VERIFIED].

### 9.4 Guardrail test files

`PolicyGuardTest`, `PolicyGuardConfigTest`, `PolicyGuardHarmOpsecInvariantTest`,
`PolicyGuardOperationalLeakageTest`, `OperationalLeakageDetectorTest`,
`PaymentInstigationGuardTest` (+ doubles `AlwaysApprovePaymentInstigationGuard`,
`SpyPaymentInstigationGuard`), `GuardBaselineIntegrityTest`, `GuardCheckCommandTest`,
`GuardBaselineCommandTest`, `GuardCanaryWorkCommandTest`, `PromptInjectionDetectorTest`,
`PromptInjectionPatternMatcherTest`, `PromptInjectionLlmAnalyzerTest`,
`PromptInjectionDetectorIntegrationTest`, `DetectPromptInjectionCommandTest`,
`PromptInjectionAnalysisTest`, `MessageRedactionServiceTest`,
`LeakageDetectionResultTest`, `SecretPolicyTest`, `ToggleLlmKillSwitchControllerTest`,
`AdminLlmKillSwitchControllerTest`, `KillSwitchFlowTest`, `SignatureLeakRegressionTest`,
`StoreRuleSqlInjectionTest`, `PayloadSizeLimitListenerTest`, `SecurityHeadersListenerTest`,
`TraceIdListenerTest`, `AuditHmacChainerTest`, `AuditLoggerTest`,
`AuditEventQueryServiceTest`, `AuditLogTest`, `AuditEventTypeTest`, `SiemEventTest`,
`SiemSeverityMapTest`, `{File,Null,Syslog}SiemExporterTest`, `AuditControllerTest`,
`RetryCoordinatorAuditTest`, `PersonaOptimizerAuditTest` [VERIFIED].

### 9.5 CI — `.github/workflows/ci.yml`

Triggers: `push` on `main`, `demo` (`:3-5`); `pull_request` on `main` (`:6-7`).
`permissions: contents: read` (`:9-10`) [VERIFIED].

| Job | Contents | What makes it fail |
|---|---|---|
| `static-analysis` (`:13`) | PHPStan `analyse src --memory-limit=1G` (`:36`); `check-no-vault-resurrection.sh` (`:39`) | non-zero PHPStan; non-zero guard script |
| `code-style` (`:41`) | `php-cs-fixer fix --dry-run --diff` (`:64`) | any style deviation |
| `backend-tests` (`:66`) | PHPUnit `--configuration phpunit.ci.xml --testsuite unit,integration --exclude-group compiler-pass` (`:110-116`); compiler-pass tests run in isolation (`:120-123`); RSA-2048 JWT key pair generation (`:135-143`); `endtoend --exclude-group ci-skip` suite (`:166-171`); Codecov upload (`:181-185`) | any PHPUnit failure. Codecov `fail_ci_if_error: false` (`:184`). **The `functional` suite is not run** |
| `security` (`:187`) | `composer audit --format=json` filtered by an inline python3 script (`:216-244`); Gitleaks CLI v8.21.2 downloaded from the GitHub releases, `gitleaks git . --redact --config .gitleaks.toml --exit-code 1` (`:252-257`) | any non-ignored composer advisory (`:238-241`); any gitleaks finding |
| `frontend` (`:259`) | `npm ci` (`:277`), `tsc --noEmit` (`:280`), `npm run lint` (`:283`), `npm run test` (`:286`), `npm run build` (`:289`) | TS/ESLint/Vitest/build errors |
| `container-security` (`:291`) | matrix over 3 Dockerfiles (dev/prod/demo) (`:302-308`); Trivy `exit-code: 1`, `severity: CRITICAL,HIGH`, `ignore-unfixed: true`, `trivyignores: .trivyignore` (`:316-330`); **Trivy in `format: cyclonedx` → `sbom-<name>.cdx.json`** (`:332-337`); artefact kept for 30 days (`:339-344`) | any **fixable** CRITICAL/HIGH CVE in one of the 3 images |

### 9.6 CI — `.github/workflows/guard-nightly.yml`

`schedule: cron '0 5 * * 0'` (Sunday 05:00 UTC, `:18-19`) + `workflow_dispatch` (`:20`);
`concurrency: guard-nightly, cancel-in-progress: false` (`:25-27`) [VERIFIED].

| Job | Detail |
|---|---|
| `preflight` (`:30`) | output `has_key` depending on whether `secrets.LLM_API_KEY` is present (`:36-45`); `::notice::` and skip if absent (`:44`) |
| `guard` (`:47`) | conditional on `has_key == 'true'` (`:50`), `timeout-minutes: 60` (`:52`). Rewrites `.env` to `APP_ENV=dev`, `LLM_PROVIDER=openai`, `LLM_MODEL=gpt-4o-mini` (`:68-71`). Asserts `LLM_PROVIDER = openai`, otherwise exit 1 (`:93-95`). Runs `scambuster:smoke:reply-objective --summary-json=…` (`:98`) then `scambuster:guard:check --baseline=tests/Smoke/guard-baseline.json` (`:101`) |

Header comments: cost ~$0.14 / ~35 min (`:5`); the secret is never written to an
artefact (`:14`) [VERIFIED].

### 9.7 Scanner inventory

| Scanner | Present | Evidence |
|---|---|---|
| gitleaks | **Yes** — v8.21.2, full history (`fetch-depth: 0`), blocking | `ci.yml:246-257`; `.gitleaks.toml` |
| trivy (container) | **Yes** — 3 images, CRITICAL/HIGH, `ignore-unfixed: true`, blocking | `ci.yml:316-330` |
| **SBOM** | **Yes** — CycloneDX via the Trivy action, 30-day artefact | `ci.yml:332-344` |
| phpstan | **Yes** — `analyse src`; composer script `--level=max` (`composer.json:100`) | `ci.yml:36` |
| psalm | **Absent** — no `psalm*`, no composer entry | — |
| php-cs-fixer | **Yes** — blocking dry-run | `ci.yml:64` |
| composer audit (SCA) | **Yes** — blocking on any advisory; `audit.ignored: {}` empty (`composer.json:55-57`) | `ci.yml:208-244` |
| dependabot | **Yes** — 3 ecosystems, all **monthly**: composer `/backend-symfony` (limit 2 PRs), npm `/frontend-react` (limit 2), github-actions `/` (limit 1) | `.github/dependabot.yml:3-33` |
| codecov | **Yes** — `require_ci_to_pass: false` (`:2`), `auto` targets (`:6-11`). **No numeric threshold**; upload with `fail_ci_if_error: false` | `codecov.yml`; `ci.yml:184` |
| **npm audit** | **Absent** in CI — no step in the `frontend` job (`ci.yml:259-289`) | — |
| SAST (semgrep / CodeQL / Snyk) | **Absent** — repository-wide search returns nothing | — |
| DAST (ZAP / Dastardly) | **Absent** | — |
| License analysis | **Absent** | — |
| OpenSSF Scorecard | **Absent** | — |

### 9.8 Controls explicitly looked for and absent

| Control | Result |
|---|---|
| SBOM via **syft** / **cyclonedx-cli** | Absent (the SBOM exists, but is produced only by the Trivy action) |
| **Enforcement** of commit signing | Absent — no branch protection and no verification step in the workflows |
| Artefact signing (**cosign** / sigstore) | Absent |
| **SLSA / provenance attestation** | Absent |
| Dependency pinning **by digest** | Absent for language dependencies (`^` ranges in `composer.json` and `package.json`; the lock files freeze the versions). **GitHub Actions are pinned by SHA** (`ci.yml:18,181,270,311,317,333,340`; `guard-nightly.yml:54`). **Gitleaks is downloaded by version tag with curl and no checksum verification** (`ci.yml:252-255`) |
| Numeric CVE blocking threshold | Absent as a score. Categorical blocking: Trivy `CRITICAL,HIGH` + `exit-code 1` + `ignore-unfixed: true`; composer audit blocks on any advisory |
| Secret scanning in CI | **Present** (gitleaks) |
| Image provenance / attestation | Absent — the images are built inside the job (`docker build`, `:314`) and are never pushed, signed or attested |
| Enforcement of the GUARD pre-push hook in CI | Absent — the hook is optional and is only a reminder by default (`scripts/hooks/pre-push-guard.sh:9-11,54-60`) |

### 9.9 `.trivyignore`

14 lines, **zero CVE entry**. Content quoted [VERIFIED]:
> "Intentionally EMPTY. This file suppresses *fixable* CVEs we consciously accept; there
> are currently none. — Fixable base-image CVEs are patched at build time by
> `apt-get upgrade` in the Dockerfiles… — Unfixable base CVEs (no upstream patch — e.g.
> perl-base, kernel headers, ncurses) are skipped by the CI Trivy job's
> `ignore-unfixed: true`… Add an entry (with a dated justification) only for a CVE that
> HAS a fix but that we deliberately choose not to apply yet."

### 9.10 `.gitleaks.toml`

`[extend] useDefault = true` (`:11-12`). Justification header (`:1-7`): "The
repository ships only template/example/dev-default credentials — no real secrets
(verified over the full history)." [VERIFIED]

**Allowed expressions** (`:18-29`): `sk-proj-abc123...`, `sk-your-api-key-here`,
`sk-e2e-not-real`, `sk-test-not-a-real-key`,
`YOUR_[A-Z0-9_]*(KEY|TOKEN|SECRET|PASSWORD)`, `your-app-password-here`,
`a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4`, `change-me-generate-with-openssl`,
`dev-only-change-in-production`, `demo-secret-change-in-production`.

**Allowed paths** (`:33-42`), header "Files that contain ONLY templates, examples,
or disposable dev/test/demo defaults by design": `\.env\.dist`, `\.env\.test`,
`\.env\.e2e`, `docs/QUICKSTART\.md`, `docs/13_misp_integration\.md`,
`docker-compose\.demo\.yml`, `infra/docker/demo/docker-entrypoint-demo\.sh`,
`\.github/scripts/create-ci-env\.sh`.

`AUDIT_HMAC_KEY=bbbb…` (`.env.dist:360`, `.env.test:121`) falls under the path
allowance [VERIFIED].

### 9.11 Release process

| Item | State |
|---|---|
| Git tags | **0** |
| Release workflow | **None** — only `ci.yml` and `guard-nightly.yml` exist |
| CHANGELOG | `CHANGELOG.md` (23,333 bytes). Header: "The format is based on Keep a Changelog … adheres to Semantic Versioning" (`:5-6`). Top section: `## [Unreleased]` (`:9`) |
| Versioning scheme | SemVer declared (`CHANGELOG.md:6`). `1.3.0` appears as an OpenAPI example (`MetricsController.php:39`); the runtime version comes from `HealthCheckHandler` (`:56`) |

---

## 10. Dependencies and versions

### 10.1 Backend — `backend-symfony/composer.json`

PHP constraint `">=8.2"` (`:7`); extensions `ext-ctype`, `ext-iconv` (`:8-9`).
Symfony line pinned by `extra.symfony.require: "7.4.*"` (`:107`).
`minimum-stability: stable`, `prefer-stable: true` (`:4-5`); `audit.ignored: {}` (`:55-57`).
**35 direct dependencies** in `require`, **13** in `require-dev` [VERIFIED].

Resolved versions of the most sensitive packages (`composer.lock`):

| Package | Resolved |
|---|---|
| symfony/framework-bundle | v7.4.13 |
| symfony/security-bundle | v7.4.13 |
| symfony/http-kernel | v7.4.13 |
| symfony/http-foundation | v7.4.13 |
| symfony/http-client | v7.4.14 |
| symfony/serializer | v7.4.10 |
| symfony/validator | v7.4.10 |
| symfony/rate-limiter | v7.4.13 |
| symfony/security-csrf | v7.4.8 |
| lexik/jwt-authentication-bundle | v3.2.0 |
| doctrine/orm — doctrine/dbal | 3.6.7 — 3.10.5 |
| guzzlehttp/guzzle | 7.15.2 |
| symfony/mailer — symfony/mime | v7.4.12 — v7.4.13 |
| zbateson/mail-mime-parser — php-mime-mail-parser | 3.0.5 — 1.7.4 |
| scheb/2fa-bundle — scheb/2fa-totp — spomky-labs/otphp | v7.13.1 — v7.13.1 — 11.5.0 |
| monolog/monolog — twig/twig — ramsey/uuid | 3.10.0 — v3.27.0 — 4.9.2 |

**No LLM SDK** [VERIFIED]: `firebase/php-jwt` and `web-token/jwt-library` are absent
from `composer.lock`; scanning the 114 production packages for
`openai|anthropic|llm|ollama|mistral` returns no result. LLM calls go through raw
`symfony/http-client`.

### 10.2 Frontend

React **19.2.4**, Vite **8.0.1**, TypeScript **5.9.3**, Vitest **4.1.4**, axios **1.13.6**,
zustand **5.0.12**, `@tanstack/react-query` **5.91.3**, react-router-dom **6.30.3**,
recharts **3.8.0**, msw **2.12.14**, jsdom **29.0.1** [VERIFIED].
Direct: 10 `dependencies` + 25 `devDependencies` = **35**. Lock file `lockfileVersion 3`,
**436** entries.
**No authentication or cryptography library**: a search for
`jwt|crypto|jose|bcrypt|oauth|auth0` in the package names of the lock file returns nothing
[VERIFIED].

### 10.3 Docker base images

| Location | Image | Tag | Pinned by digest |
|---|---|---|---|
| `infra/docker/backend/Dockerfile:2,29` | `php` | `8.3.27-cli` | No |
| `infra/docker/backend/Dockerfile.prod:11,21,39` | `node` / `php` / `php` | `20-alpine` / `8.3.27-cli` / `8.3.27-fpm` | No |
| `infra/docker/frontend/Dockerfile:1` | `node` | `20-alpine` | No |
| `infra/docker/demo/Dockerfile.backend:7,64` | `php` | `8.3.27-cli` | No |
| `infra/docker/demo/Dockerfile.frontend:7,20` | `node` / `nginx` | `20-alpine` / **`alpine`** (floating tag) | No |
| `docker-compose*.yml` | `postgres` | `15-alpine` | No |
| `docker-compose*.yml` | `redis` | `7-alpine` | No |
| `docker-compose{,.prod}.yml` | `n8nio/n8n` | `1.114.3` | No |
| `infra/monitoring/docker-compose.yml:13,27` | `prom/prometheus` / `grafana/grafana` | `v2.54.1` / `11.2.0` | No |

**No image is pinned by `@sha256:`** [VERIFIED]. PHP is pinned to the exact patch
(`8.3.27`); n8n, Prometheus and Grafana to an exact version; postgres, redis, node
and nginx use floating major/minor tags.

### 10.4 Direct versus transitive

| Ecosystem | Direct | Total locked | Transitive |
|---|---|---|---|
| Composer (prod) | 35 | 114 | 79 |
| Composer (dev) | 13 | 68 | 55 |
| Composer (combined) | 48 | 182 | 134 |
| npm | 35 (10 + 25) | 436 entries | ~401 |

---

## 11. Outdated documentation found

> Register opened in phase 0 and fed until the end of the audit (rule R3: in case of
> contradiction, **the code prevails**). Each row cites both sides.

| # | Doc (file:line) | Claim in the documentation | Reality of the code (file:line) | Nature of the gap |
|---|---|---|---|---|
| DOC-01 | `docs/12_api_quick_reference.md:37-38` | "GET \| `/api/health` \| **No**" and "GET \| `/api/metrics` \| **No** \| Prometheus text format" | `config/packages/security.yaml:60-61` — `{ path: ^/api/metrics, roles: ROLE_ADMIN }`, `{ path: ^/api/health, roles: ROLE_ADMIN }` | Documented as needing no authentication; they require `ROLE_ADMIN`. `docs/compliance/risk-register.md:12` records this hardening as **CLOSED**, so doc 12 was not updated |
| DOC-02 | `docs/12_api_quick_reference.md:186-188, 204-211` | 9 API endpoints marked "auth: **No**" (`/scambaiting/stats`, `/campaign/candidates`, `/campaign/transpile`, `/campaign/rule`, …) | `config/packages/security.yaml:67` — `{ path: ^/api/v1, roles: IS_AUTHENTICATED_FULLY }` | All `/api/v1` paths are authenticated |
| DOC-03 | `docs/compliance/gdpr-record-of-processing.md:53` | "6 months soft-delete → 12 months hard-delete \| `PurgeService` (`app:cleanup:weekly`, **automatic**)" | `WeeklyCleanupCommand.php:32-33` — defaults `90` / `180` days, raw DBAL, **never calls `PurgeService`**, **no hard delete** | The named automatic command purges at 90 days, does not hard-delete, and does not use the service that is cited |
| DOC-04 | `docs/compliance/data-classification.md:50` | "soft-deleted at 6 months, hard-deleted at 12 months (`PurgeService`, **automatic**)" | `PurgeService.php:25,55` is reachable only from `app:purge:rgpd`, which is **absent from `scheduler.sh:17-24`** and from the production `Makefile` | The 6/12-month logic exists but is **never scheduled** |
| DOC-05 | `docs/04_security_guardrails.md:61` | "Content layer: **Anonymized** or deleted after 6 months per DPIA scope" | `PurgeService.php:29-33` filters on `status = CLOSED` only; the body goes through `softDelete()`. `MessageAnonymizer` is used only by `ContextualEnricher.php:26` (prompt building) | Open/abandoned conversations are never touched; **no anonymisation exists in the purge path** |
| DOC-06 | `docs/04_security_guardrails.md:376`; `gdpr-record-of-processing.md:57` | "**13 fine-grained permissions** (…)" with an enumeration | `src/Domain/User/Permission.php:19-40` — **14 cases**; `:33` `case CAMPAIGN_WRITE = 'campaign:write';` is missing from the documented list | Both the count and the enumeration are wrong |
| DOC-07 | `docs/04_security_guardrails.md:121`; `README.md:274` | "**PolicyGuard** (rule-based) blocks: … **Illegal offers** \| Drugs, weapons, CSAM" | `PolicyGuard.php:47-181` — the only sets are `FORBIDDEN_PATTERNS`, `OPERATIONAL_LEAKAGE_PATTERNS`, `THREAT_PATTERNS`, `AUTHORITY_PATTERNS`, `PII_PATTERNS`, `OUT_OF_BAND_CHANNEL_PATTERNS`; search for `drug\|weapon\|CSAM\|firearm` over `backend-symfony/src` → **no result** | A blocking category is announced **with no matching rule at all** |
| DOC-08 | `docs/04_security_guardrails.md:356` | "`Content-Security-Policy: **default-src 'none'**` … Implemented via `SecurityHeadersListener`" | `SecurityHeadersListener.php:44` — `"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; …"` | Directive value differs from the documented one. The seven other headers on the same line do match |
| DOC-09 | `docs/09_dpia_template.md:107` and `:120` | "**16** automation-revealing keyword patterns are blocked" | `PolicyGuard.php:47-54` — `FORBIDDEN_PATTERNS` contains **6** entries | Count wrong by a factor of ~2.7 |
| DOC-10 | `docs/02_value_proposition.md:161`; `docs/05_evaluation_methodology.md:177` | "**GPT-4o (generation)** + GPT-4o-mini (validation)" | `.env.dist:306` — `LLM_MODEL=gpt-4o-mini`; `config/packages/llm.yaml:4` injects the same parameter into the generator (`:163-172`) and into the validator (`:137-141`) | **No generation/validation split**; the shipped default is `gpt-4o-mini` on both sides. `docs/03_high_level_architecture.md:384` states the correct behaviour — the two documents also contradict each other |
| DOC-11 | `docs/05_evaluation_methodology.md:164` | "Database Constraints \| **RLS policies** \| Multi-tenant isolation" | Search for `ROW LEVEL SECURITY` / `CREATE POLICY` over `migrations/` and `src/` → no result; `migrations/Version2026041112000000.php:35` — `ALTER TABLE app_users DROP COLUMN tenant_id` | Control listed as implemented, **with no implementation**; the only multi-tenant artefact was removed (`docs/06_roadmap.md:149` confirms it) |
| DOC-12 | `docs/compliance/risk-register.md:11` | "R2 \| Refresh token stored plaintext; no reuse-detection cascade; refresh not audited \| **OPEN**" | `RefreshToken.php:55` — `return hash('sha256', $rawToken);`; `AuthService.php:99-106` — `revokeFamily(...)` + `AuditEventType::AUTH_TOKEN_REUSE_DETECTED` | All three sub-risks **are addressed in the code**; the register says they are not |
| DOC-13 | `docs/compliance/risk-register.md:19` | "R10 \| No SSO/OIDC (enterprise IAM) \| **IN PROGRESS**" | `src/Application/Auth/Oidc/OidcService.php` (269 lines) + `OidcStateManager`, `OidcUserProvisioner`, routes `/api/v1/auth/oidc/{login,callback}`, `.env.dist:75-95` | A complete OIDC authenticator has shipped; `docs/06_roadmap.md:57` lists it under "Recently shipped", the register does not |
| DOC-14 | `docs/12_api_quick_reference.md:273` | "`app:close-stale-conversations` \| **Daily**" | `infra/docker/backend/scheduler.sh:17` — "every 6h", run at `:46-48` | Documented frequency is wrong. `docs/08_getting_started.md:544` says "Every 6h" — an internal contradiction inside the documentation |
| DOC-15 | `.env.dist:367-369` | `CAMPAIGN_PROMOTION_PPV_THRESHOLD=0.85`, `CAMPAIGN_PROMOTION_MIN_HITS=5`, `CAMPAIGN_PROMOTION_MIN_LEAD_TIME_SEC=10800` | `src/Application/Campaign/CampaignPromoter.php:16-18` — `private const PPV_THRESHOLD = 0.85; MIN_HITS = 5; MIN_LEAD_TIME_SEC = 10800;`, returned verbatim by `getThresholds()` (`:176-178`) | **Hard-coded constants**; the three variables are read nowhere → **setting them has no effect** |
| DOC-16 | `.env.dist:123-124, 146, 148, 323` | `RSPAMD_URL`, `RSPAMD_PASSWORD`, `DEBUG_IMAPFLOW`, `SCORE_RISK_MIN`, `REPLY_HISTORY_LAST_N` | Repository-wide search: these names appear only in `.env.dist`, `.env.test`, `.env.e2e`, `docker-compose.demo.yml`, `.github/scripts/create-ci-env.sh` — **no PHP, no configuration YAML and no n8n workflow reads them** | **5 documented settings that are not consumed** |
| DOC-17 | `docs/12_api_quick_reference.md:3` | "**All endpoints** grouped by domain" | `src/UI/Http/**` declares **132 distinct `/api/...` paths**; the document contains **95 lines** | ~40 live API endpoints omitted, including `/api/v1/2fa/setup`, `/api/v1/auth/oidc/{login,callback}`, `/api/v1/admin/llm/killswitch`, the nine `/api/v1/monitoring/analytics/*`, `/api/v1/prompt-overrides*`, `/api/v1/communication/reply/{msgId}/send-email`, `/api/v1/clusters`, `/api/v1/stats/*` |
| DOC-18 | `docs/03_high_level_architecture.md:183` | "Evaluation: Automated benchmark suite (**7 quality metrics**, 3 CLI commands)" | `src/Application/Evaluation/ReplyQualityAnalyzer.php:36-45` — the `$metrics` array carries **9** computed results | Count contradicted by the code **and** by `docs/05_evaluation_methodology.md:229` ("9 quality metrics"); the class docblock (`:17`) says "10 metrics total", which matches neither |
| DOC-19 | `README.md:277` | "**GDPR**: data minimization, retention policies, **encryption at rest**" | `src/Domain/Communication/Message.php:57` — `#[ORM\Column(name: 'body_text', type: 'text')]` (plain column); `EncryptedStringType` is used only in `Domain/User/User.php:45` | **Message content is stored in clear text.** `docs/compliance/data-classification.md:37` says so explicitly ("Field-level encryption of bodies is **not** applied") — the README contradicts both the code and its own compliance document |
| DOC-20 | `GOVERNANCE.md:25` | "**Tags**: Each release is tagged in Git (e.g., `v2.3.0`)" | `git tag -l \| wc -l` → **0**; no release workflow in `.github/workflows/` | No tag exists; the release process that is described is not applied |
| DOC-21 | `SECURITY.md:38` | "**Audit trail**: All operations logged for traceability" | 145 `*Controller.php` files under `src/UI/Http`; **12 reference `auditLogger`**. With no audit entry: `DeleteConversationController`, `DeleteMessageController`, `UploadAttachmentController`, `DownloadAttachmentController`, `ExportIocsStixController`, `ExportConversationStixController`, `ExportIocsFeedController`, `SendEmailController`, the 4 TAXII controllers, `ExportClusterStixController` | "All operations" is contradicted: deletions, attachment uploads/downloads and **four of the six export surfaces** emit no audit event |
| DOC-22 | `docs/09_dpia_template.md:33` and `:146` | "6 months max, **then anonymization**"; "PurgeService: **anonymization at 6 months**" | No anonymisation, pseudonymisation or redaction routine exists in `src/`; `PurgeService.php:23-45` performs a **soft** delete | Overlaps with DOC-05, recorded separately because it concerns the DPIA, a document that can be relied upon |
| DOC-23 | `migrations/Version2026041200100000.php:20-24` | The PostgreSQL `REVOKE` on UPDATE/DELETE of `audit_log` is "documented in `docs/runbooks/audit-hmac-key-rotation.md`" | `rg -n "REVOKE" docs/` → **no result**; the runbook was read in full (53 lines) | The operational step that actually makes the log append-only is **documented nowhere**, even though the migration refers to it |
| DOC-24 | `docs/runbooks/audit-hmac-key-rotation.md:32-33` | Rotation step 3: "Run a rebuild script" | No chain rebuild script exists under `scripts/`, `bin/` or `src/UI/Console/` | Rotation procedure pointing to a tool that does not exist |
| **DOC-25** | `docs/04_security_guardrails.md:400` | "**PII masked in exported events (emails hashed, IPs truncated)**" | All three formatters emit `actorId` and `ipAddress` **verbatim**: `JsonFormatter.php:29,33`; `EcsFormatter.php:40,44`; `CefFormatter.php:95,99`. The `details` array is serialised **without filtering** (`JsonFormatter.php:35`, `EcsFormatter.php:60`, `CefFormatter.php:120`). Search for `hash\|mask\|truncat\|anonym\|sha256\|redact` over `src/Infrastructure/Siem/` and `SiemEvent.php`: **no result**. The only transformation is the escaping of CEF metacharacters (`CefFormatter.php:129-136`), which preserves the value | **No masking, no hashing and no truncation exists.** `actorId` carries an email address in clear text on the OIDC path (`OidcCallbackController.php:83`) |

**Internal contradictions within the documentation** (both sides are documentation,
neither is the code): DOC-10 (`02_value_proposition` vs `03_high_level_architecture`), DOC-14
(`12_api_quick_reference` vs `08_getting_started`), DOC-18
(`03_high_level_architecture` vs `05_evaluation_methodology` vs the docblock),
DOC-19 (`README` vs `data-classification`).

**Documentation verified as matching the code** (checked, no gap): `docs/14_key_management.md`
(RS256, `token_ttl: 900`, RSA-2048); the rate-limiting table in
`docs/04_security_guardrails.md:101-112`; "33 event types" (`:157,360,390`) against the
33 cases in `AuditEventType.php`; the 1 MB / 50 MB payload caps (`:380-382`) against
`PayloadSizeLimitListener.php:35-41`; `docs/22_metrics_catalog.md`;
`docs/15_siem_integration.md`; `docs/13_misp_integration.md` (including the admission that
there is no MISP push); `docs/16_taxii_server.md` and `docs/11_opencti_integration.md`
(collection UUIDs); `docs/20_enterprise_sso.md`; the counts of 36 IOC types / 14 scam
types / 27 TTPs / 27 personas; the reward weights 0.40/0.25/0.25/0.10;
`docs/19_data_quality_audit.md:67`; ports 3002/8081/5678; every `make` target cited in
the documentation exists in the `Makefile`.

---

## 12. What I could not verify

> **Updated after the follow-up pass.** Questions **17** (invocation of
> `check-secrets` by the production entrypoint), **28** (controls in the OIDC module),
> **29** (`check-credentials.py`), **30** (`create-ci-env.sh`) and **31**
> (`TestReplyGenerateCommand`) are **closed** — see §4.11 and §4.12. The remaining
> questions below are still open.

Phrased as questions, in line with R8.

**On actual execution and deployment**
1. Is `app:purge:rgpd` invoked by an external cron, a `make` target run in
   production, or a Kubernetes `CronJob` outside `scheduler.sh` — or has the 6/12-month
   retention never run in production?
2. Has the `REVOKE UPDATE/DELETE ON audit_log` referenced by
   `migrations/Version2026041200100000.php:20-24` been applied in any environment,
   given that it appears in no file of the repository?
3. What is the value of `LLM_BUDGET_ENFORCEMENT_MODE` in production, given that the
   shipped default is `warning` (`ReplyHandler.php:41`, `.env.dist:343`)?
4. Which SIEM provider is actually configured in production, given that the shipped
   default is `SIEM_PROVIDER=none` (`.env.dist:421`)?
5. Are the `operatorTestSenders`, `honeypotEmailAddresses` and `honeypotDomains` lists —
   on which guards D57 and D65 depend — populated in the reference deployment, or are
   these guards without effect by default?
6. Is `SCAMBUSTER_SAFE_DOMAINS` set to anything other than its `*` default in
   production, and is the `safelist_eligible` flag consumed anywhere (n8n workflow,
   SMTP layer) to block a send?
7. Is the GUARD pre-push hook installed on the operator's machine (`GUARD_ON_PUSH=1`),
   or does the GUARD gate only run once a week in CI?

**On human intervention and the approval path**
8. Is there a state machine or a configuration requiring a **separate** analyst
   approval between the draft and the send? `/send-email`
   (`SendEmailController.php:48`) and `/reply/{msgId}/sent` (`MarkReplySentController.php:66`)
   are guarded only by `#[IsGranted('reply:generate')]`, the same permission as
   generation — can an n8n principal holding that permission generate **and**
   send with no human in the loop?
9. `WF-REPLY-SEND-v1.json` carries `"active": false` while the other three workflows
   are active — is sending deliberately disabled by default, and where is that
   documented?
10. Does any consumer (n8n, dashboard, alert) act on
    `injection_analysis.risk_score >= 0.7`, or does injection detection stay
    purely forensic despite the `'blocked'` audit outcome?

**On data and storage**
11. Are the 99 `.eml` fixtures synthetic, or derived from scam emails actually
    received — which would make them third-party personal data committed to the public
    repository?
12. `Attachment::$s3Key` and `$encKeyId` have setters with no caller in
    `src/`: is the binary storage of attachments done outside the Symfony code
    (n8n, an external service), or is this set of columns dead?
13. If attachment bytes are stored somewhere, which key does `enc_key_id`
    reference and where is that cryptographic material held?
14. `message_vector` has no foreign key to `message`: does any process reconcile
    orphan vectors after a conversation is hard-deleted?
15. Where is the honeypot IMAP password consumed — does n8n read
    `HONEYPOT_IMAP_PASSWORD` at run time, or does a copy also live in
    `./data/n8n/`?

**On secrets and cryptography**
16. `ADMIN_PASSWORD` appears in `SecretPolicy::PUBLISHED_DEFAULTS` and in
    `CheckSecretsCommand::CHECKED` but has no line in `.env.dist`: where is it
    defined and which component consumes it?
17. Does the production entrypoint actually invoke `app:security:check-secrets`?
    The claim is in the docblock (`CheckSecretsCommand.php:19-21`) but the
    `infra/docker/backend/` scripts were not read on this point.
18. `scripts/rotate-jwt-keys.sh:51` sets the private key to `chmod 644` where
    `generate-jwt-keys.sh:35` sets it to `600` — is this difference
    intentional?
19. `INGEST_PASSWORD` is `Un1que$$trongPassword2024`: does the `$$` resolve to the
    default value of `ADMIN_PASSWORD` at run time, and is the same credential intended
    for both uses?
20. Rotating `APP_SECRET` makes all encrypted SMTP DSNs unreadable
    (`SmtpDsnEncryptor.php:22-23`): does a rotation procedure exist outside the repository?

**On encryption at rest and infrastructure**
21. `docs/04_security_guardrails.md:222` announces "Infrastructure-layer encryption
    (volume/disk)": is volume encryption configured anywhere in the deployment, or is
    it entirely up to the operator with no artefact in this repository?
22. Is an outbound proxy enforced at the Docker daemon or host level, given that none
    is configured in the repository (`framework.yaml:17-18` with no `proxy`)?
23. Is `/api/metrics` effectively protected in production, and what is the "admin
    scrape token" mentioned in `infra/monitoring/prometheus/alert.rules.yml`?

**On CI and the supply chain**
24. What are the branch protection settings on `main` (required checks, signature
    requirement, number of reviews)? Not determinable from the files.
25. Are the ~97 functional tests run in any automated pipeline,
    or only locally through `make test`, as `ci.yml:103-108` states?
26. What is the coverage rate actually measured, and does Codecov block PRs
    given `require_ci_to_pass: false` and `fail_ci_if_error: false`?
27. Is the CycloneDX SBOM produced on each run kept, signed or published
    beyond GitHub's 30-day artefact retention?

**On components not read in this pass**
28. Do the 7 classes in `src/Application/Auth/Oidc/` contain deterministic
    controls (nonce/state TTL, issuer and audience pinning, default role at
    provisioning) that should appear in §4?
29. `scripts/check-credentials.py` has no header: what exactly does it validate?
30. What does `.github/scripts/create-ci-env.sh` do, invoked by every CI job but not read?
31. `TestReplyGenerateCommand.php`: what are its declared command name and
    description?
32. `RSPAMD_URL` is unused in this repository even though the ingestion DTO carries
    `rspamd` fields (`IngestRawRequestDto.php:30`): is there an external ingestion
    component outside the repository that calls rspamd?
33. `.env.dist:146-148` describes an "IMAPFlow Watcher" with no corresponding code or
    service: is this component still deployed, and where does its code live?

---

*End of phase 0.*

