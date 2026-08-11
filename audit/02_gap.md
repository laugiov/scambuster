# Phase 2 — Gap analysis

> **Framing.** Pilot scenario **S2** — NIS2-regulated entity, essential entity,
> self-hosted, **EU scope with no national assumption**. What is analysed is **the
> configuration shipped by default** (`.env.dist`, `docker-compose.prod.yml`,
> documentation), that is, what a third-party deployer receives, and not one
> particular deployment.
>
> **Rule applied.** No gap without a legal source quoted with its text and article.
> Architect judgements with no reference framework are moved to the "Non-binding
> opinions" section and **do not count** as gaps.
>
> **Sources used** (established in phase 1B, `audit/01_scope.md` §B):
> Regulation (EU) 2024/1689 Art. 50(1) · Directive (EU) 2022/2555 Art. 20, 21(1),
> 21(2)(a) to (j), 23 · Regulation (EU) 2016/679 Art. 5, 6, 9, 14, 16, 17, 32, 35 ·
> Regulation (EU) 2024/2847.
>
> **Severity scale.** *Blocking* = a regulated third-party deployer cannot go live
> without addressing the point. *Major* = go-live possible under a documented
> reservation, fix expected in the first cycle. *Moderate* = to be handled as work
> allows. *Minor* = identified debt.

---

## 1. AI system transparency

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-01** | An AI system intended to interact directly with natural persons must be **designed and developed** so that the person is informed they are interacting with an AI system. Obligation of the **provider**. The exemption is reserved for systems authorised by law to detect, prevent, investigate or prosecute criminal offences | Regulation (EU) 2024/1689, **Art. 50(1)** — applicable since **2 August 2026** | Three mechanisms enforce the opposite: `src/Application/LLM/PolicyGuard.php:47-54` (`FORBIDDEN_PATTERNS`, 6 patterns blocking `I am a bot`, `automated system`, `artificial intelligence`); `src/Application/LLM/Prompt/BasePromptRules.php:41` (CORE rule declared non-overridable); `src/Application/Guard/SafetyInvariantOracle.php:153-165` (code `automation_reveal` treated as a violation by the GUARD gate) | **No disclosure is possible, and the absence of disclosure is actively defended by three independent controls.** The exemption does not cover a private NIS2 entity | **Blocking** | The exemption could be widened by a national authorisation granted to the deployer, or the "unless this is obvious" clause could apply if a regulator held that a professional scammer operating at scale is deemed to be on notice. Neither reading is settled, and the second is contradicted by the very design of the product |
| **G-02** | Where disclosure is required, it must be perceptible within the interaction itself, and not buried in general terms and conditions | Regulation (EU) 2024/1689, Art. 50(1); Commission guidelines of **20 July 2026** | No disclosure mechanism exists in the composition path: `src/Application/Communication/ReplyCompositionService.php:311` composes and sends with no added header or notice | No insertion point for a notice of artificial nature in the outgoing email | **Blocking** (depends on G-01) | Technically trivial once G-01 is settled; not worth addressing while the question of principle is open |

---

## 2. Inference sovereignty and exit from dependence on an external API

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-03** | The controller implements technical measures guaranteeing data confidentiality, and uses only processors providing sufficient guarantees | Regulation (EU) 2016/679, **Art. 32(1)** and **Art. 28(1)**; Directive (EU) 2022/2555, **Art. 21(2)(d)** (supply chain security) | The full body of third-party emails is sent to `%llm.api_url%`, default `https://api.openai.com/v1` (`.env.dist:307`, `src/Infrastructure/LLM/Provider/OpenAIClient.php:48`) | Third-party personal content — including data that may fall under Art. 9 — leaves the entity's perimeter for an external inference provider, **as shipped by default** | **Blocking** | The transfer can be covered contractually by a processing agreement and a no-training clause; many regulated entities already operate this way. The argument holds for lawfulness, but not for demonstrability — see G-04 |
| **G-04** | Measures must be **verifiable**: the entity must be able to establish that no data leaves | Regulation (EU) 2016/679, Art. 32(1)(d) (process for regular testing and evaluation); Directive (EU) 2022/2555, Art. 21(2)(f) | **7 call sites hard-code an OpenAI model identifier**: `ReplyValidator.php:103`, `OperationalLeakageDetector.php:28`, `PaymentInstigationGuard.php:50`, `ConversationAnalyzer.php:27`, `ConversationHistoryService.php:229`, `ConversationQualityAuditor.php:77`, `EmbeddingService.php:18` | **Setting `LLM_PROVIDER=ollama` does not switch the whole system over.** The entity cannot demonstrate that nothing leaves, even when a local provider is configured | **Blocking** | An informed third party can audit the 7 sites and patch them; the project is MIT-licensed. A valid argument for a deployer with PHP engineering capacity, worthless for the others |
| **G-05** | Same | Same as G-04 | `src/Application/LLM/EmbeddingService.php:20` — constant `API_URL = 'https://api.openai.com/v1/embeddings'`, called at `:68` through `HttpClientInterface` **directly**, without going through `LLMClientInterface` | The embeddings service **has no provider abstraction at all**: it cannot be switched over, not even by configuration | **Blocking** | Embeddings carry no plain text on the way out — only on the way in. Weak argument: it is the message text that is sent to be vectorised |
| **G-06** | Same | Same as G-04 | `src/Infrastructure/LLM/OpenAIService.php:51` — endpoint `https://api.openai.com/v1/chat/completions` hard-coded, a second `LLMServiceInterface` interface coexisting with the hexagonal port (`config/services.yaml:525-532`) | **Two competing LLM abstractions**, one of which cannot be switched over and is wired to the preprod generator | **Major** | This path only serves preproduction data generation, never production. An acceptable argument — provided the service is not deployed, which the `preprod` compose profile does not guarantee |

---

## 3. Zoning and isolation of the engagement layer

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-07** | Information system security and risk analysis policies, including segmentation | Directive (EU) 2022/2555, **Art. 21(2)(a)** | A single bridge network `scambuster` (`docker-compose.yml:261-262`); no `internal: true`; no `network_mode` restriction | **No segmentation between the engagement zone (n8n, IMAP, SMTP) and the processing zone (PostgreSQL, Redis, backend).** The component that talks to adversaries shares the network of the data store | **Blocking** | A regulated deployer will redo its own network anyway; the shipped compose file is a development template. A serious argument — but `docker-compose.prod.yml` is presented as the production target and carries the same flaw |
| **G-08** | Same, outbound flow control aspect | Directive (EU) 2022/2555, Art. 21(2)(a) | `framework.http_client` enabled with no `proxy` and no scope-restricted client (`config/packages/framework.yaml:17-18`); the only occurrences of `no_proxy` are in the auto-generated stub `config/reference.php:484,537`, which is not applied | **No outbound allowlist and no outbound proxy.** Any code execution vulnerability in the processing zone has free Internet egress | **Blocking** | Egress filtering is classically a matter for the host infrastructure, not the application. An acceptable argument, provided the product **documents** the list of legitimate destinations — which it does not |
| **G-09** | Human resources security, access control policies and asset management | Directive (EU) 2022/2555, **Art. 21(2)(i)**; policy on the use of cryptography, **Art. 21(2)(h)** | n8n holds the production IMAP/SMTP credentials, encrypted by `N8N_ENCRYPTION_KEY` in `./data/n8n` (`docker-compose.yml:248`; `.github/scripts/check-no-vault-resurrection.sh:5-8`: "n8n now stores its own IMAP credentials") | **The most exposed component holds the production credentials**, on the same network as the database | **Major** | This is the result of a documented and owned decision — the removal of Vault in April 2026 — motivated by the fact that Vault was dead code. Reverting would reintroduce the complexity that justified the removal |
| **G-10** | Security in acquisition, development and maintenance, including vulnerability handling | Directive (EU) 2022/2555, **Art. 21(2)(e)** | `src/Application/Communication/EmailParsingService.php:275` — `$mimeType = $part->getContentType() ?? 'application/octet-stream'`; size cap of 25 MB (`:27`), exclusion of `inline` parts (`:308-314`) | **No allowlist or denylist of MIME types** on attachments coming from adversaries, processed in the exposed zone | **Major** | Binary content is not persisted by the application (§7.3) and only the OCR'd text is kept; the exploitation surface is therefore that of the MIME parser, which is up to date (`zbateson/mail-mime-parser 3.0.5`) |

---

## 4. Integrity and chain of custody of IOCs through to the SIEM

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-11** | Data must be adequate, relevant and limited to what is necessary | Regulation (EU) 2016/679, **Art. 5(1)(c)** | The financial export block covers **10 types** (`src/Domain/Communication/IocCategory.php:33-43`) out of ~36 extracted (`IocExtractor.php:30-58`). A TAXII dissemination path is shipped and documented, targeting an external CTI platform (`TaxiiService.php`, `docs/11_opencti_integration.md`) | **Domains, URLs, IPs and email addresses — the bulk of the CTI feed — leave with no human verdict**, even though they may point to innocent third parties | **Blocking** | These types are the very object of a CTI feed; holding them all behind a human verdict would make the product unusable by a single person. The argument is strong and calls for a graduated response, not a total block |
| **G-12** | Right to rectification and right to erasure, including towards recipients to whom the data was disclosed | Regulation (EU) 2016/679, **Art. 16, 17(2) and 19** | No originating node identifier and no withdrawal mechanism in the export path: `src/Application/Taxii/TaxiiService.php:291`, `src/Application/Export/IocFeedExporter.php:219` | **An IOC exported to OpenCTI cannot be recalled.** The entity cannot satisfy an erasure request covering data already shared | **Major** | CTI sharing traditionally operates in push-and-forget mode; no TAXII standard mandates withdrawal. An acceptable argument on the state of the art, not on the obligation |
| **G-13** | Data is kept only for as long as necessary | Regulation (EU) 2016/679, **Art. 5(1)(e)** | **The `indicator` table has no purge routine**; it is not reached by the conversation delete cascade (no foreign key to `message`) — §7.7 | IOC values — email addresses, IBANs, phone numbers, third-party IPs — are kept **indefinitely** | **Major** | The intelligence value of an IOC is precisely its persistence; `docs/09_dpia_template.md:38` explicitly claims indefinite retention for TTPs "as IOCs". The debate is about the legal qualification, not the mechanism |
| **G-14** | Same as G-03: guarantees on recipients | Regulation (EU) 2016/679, Art. 5(1)(c) and **Art. 44** (transfers to third countries) | `n8n/workflows/WF-EXTRACT-AND-ENRICH-IOC.json:114,136,224` — the IOC value supplied by the adversary is submitted to **urlscan.io** and **VirusTotal** | Transmission of possibly personal data to two third-party services, outside any control by the entity and **outside the perimeter observed by PolicyGuard and by the GUARD gate** | **Major** | These two services are standard SOC tooling; their use is routine and documented. A solid argument for practice, a weak one for demonstrating compliance — and it does not address the side channel that reveals the honeypot (threat T3) |
| **G-15** | Incident handling: detection, response, reporting | Directive (EU) 2022/2555, **Art. 21(2)(b)** and **Art. 23** | `SIEM_PROVIDER=none` by default (`.env.dist:421`); `file`, `syslog` and `null` adapters available (`src/Infrastructure/Siem/SiemCompilerPass.php:51-67`) | **On installation, no security event leaves the system.** The deployer has to discover and enable the connector | **Major** | A `none` default is the conservative choice: it avoids writing to an unconfigured collector. A legitimate argument — but it should then come with a refusal to start in production, as `CheckSecretsCommand` already does for secrets |

---

## 5. Auditability and immutability of logs

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-16** | Ensure the integrity of the data processed | Regulation (EU) 2016/679, **Art. 32(1)(b)**; Directive (EU) 2022/2555, Art. 21(2)(a) | `backend-symfony/migrations/Version2026041200100000.php:20-24` defers the `REVOKE UPDATE/DELETE ON audit_log` to an operational step "documented in `docs/runbooks/audit-hmac-key-rotation.md`"; **`rg -n "REVOKE" docs/` returns no result** | **The audit table is immutable in no environment**, and the step that would make it so is documented nowhere | **Major** | The HMAC chain already detects any alteration; the `REVOKE` only adds defence in depth. A valid argument **if** the verification were blocking — but `scheduler.sh:95` logs `CRITICAL` and **continues the loop** |
| **G-17** | Policies on the use of cryptography | Directive (EU) 2022/2555, **Art. 21(2)(h)** | `src/Application/Audit/AuditHmacChainer.php:19-20` — `AUDIT_HMAC_KEY` read from the environment of the application process, the very process that writes the rows | **The sealing key is held by the sealing process**: the property obtained is detection of accidental alteration, not resistance to an operator | **Major** | In S2 the purpose is not evidentiary (`01_scope.md` §B.4 X5): the relevant threat model is accidental alteration and external intrusion, not the malicious operator. An acceptable argument — this is what downgrades this gap from "blocking" to "major" |
| **G-18** | Incident handling and access logging | Directive (EU) 2022/2555, Art. 21(2)(b) and 21(2)(i) | **145 `*Controller.php` files under `src/UI/Http`; 12 reference `auditLogger`.** With no audit entry: `DeleteConversationController`, `DeleteMessageController`, `UploadAttachmentController`, `DownloadAttachmentController`, `ExportIocsStixController`, `ExportConversationStixController`, `ExportIocsFeedController`, `SendEmailController`, the 4 TAXII controllers, `ExportClusterStixController` | **Deletions and 4 of the 6 export surfaces emit no audit record.** Yet `SECURITY.md:38` states "All operations logged for traceability" | **Major** | Exports are traced indirectly by the reverse proxy's HTTP access logs. A weak argument: those logs carry neither the identity of the application actor, nor the volume exported, nor the IOC identifiers involved |
| **G-19** | Data minimisation, and security of processing | Regulation (EU) 2016/679, Art. 5(1)(c) and Art. 32 | `src/Application/LLM/ReplyValidator.php:144` — `'response' => $response`, **the full completion text, untruncated**, written to the application log on `JsonException`. Three other sites truncate at 200 or 500 characters (`ScamClassifier.php:69`, `ConversationAnalyzer.php:852`, `ContextualEnricher.php:68`) | Generated text, which may contain content derived from personal data, goes into the application log — which in production writes to **`php://stderr`** with no rotation configured (`config/packages/monolog.yaml:73-77`; no `logging:` block in the compose files) | **Moderate** | The path is only reached on malformed JSON, a rare case. An acceptable argument on frequency, not on nature |
| **G-20** | Same | Regulation (EU) 2016/679, Art. 32 | `src/Infrastructure/EventListener/Security/PiiMaskingProcessor.php:18-19` — docblock: "Does NOT affect the audit_log database table"; applies to `message` and `context` only (`:38-40`) | PII masking **does not cover the audit table**, which nonetheless stores `ip_address` and a free-form JSON `details` field (`AuditLog.php:55,61`) | **Moderate** | The audit table must precisely retain the actor's identity to be useful; masking there would be counterproductive. A fair argument for `actor_id`, debatable for `details`, whose content is unbounded |

---

## 6. Identities and access

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-21** | Access control policies; separation of roles | Directive (EU) 2022/2555, **Art. 21(2)(i)**; approval and supervision by the management body, **Art. 20** | `src/UI/Http/Communication/SendEmailController.php:48` and `MarkReplySentController.php:66` are guarded by `#[IsGranted('reply:generate')]` — **the same permission as generation**. The shipped pipeline chains generation and sending with no stop point (`WF-REPLY-GENERATE-V2.json` → `WF-REPLY-SEND-v1.json`) | **No separation between producing a draft and committing the entity towards a third party.** An n8n principal holding a single permission does both | **Blocking** | Full automation is the product's value proposition; requiring human validation of every message would destroy throughput and natural cadence. A strong argument — it calls for a separation of **permissions** rather than systematic validation |
| **G-22** | Same, secret management aspect | Directive (EU) 2022/2555, Art. 21(2)(h) and (i) | `src/Security/SecretPolicy.php:67-69` — returns `[]` immediately outside production; `CheckSecretsCommand.php:37-45` checks **7 variables**. Not checked: `POSTGRES_PASSWORD` (default `postgres`), `LOGIN_HASH_SALT`, `RSPAMD_PASSWORD`, `INGEST_PASSWORD`, `HONEYPOT_IMAP_PASSWORD`, `LLM_API_KEY`, `OIDC_CLIENT_SECRET`, `TAXII_API_KEY`, `MISP_API_KEY` | **7 variables checked out of 24 that look like secrets.** `POSTGRES_PASSWORD=postgres` passes the production startup check | **Major** | The check is explicitly designed as a minimal safeguard ("It only *strengthens* posture", `:8-18`) and the database is not exposed in production (`docker-compose.prod.yml:42`, with no `ports:`). An acceptable argument for PostgreSQL, not for `HONEYPOT_IMAP_PASSWORD` or `LLM_API_KEY` |
| **G-23** | Use of multi-factor authentication | Directive (EU) 2022/2555, **Art. 21(2)(j)** | TOTP available (`config/packages/scheb_2fa.yaml:1-8`), OIDC available and **opt-in** (`OIDC_ENABLED`, `.env.dist:75`) | [INFERRED] Nothing in the shipped configuration mandates multi-factor authentication for operator accounts; it is offered, not required. Reasoning: `OIDC_ENABLED` is a flag, and no access control in `security.yaml` requires `IS_AUTHENTICATED_2FA` | **Major** | The product provides both mechanisms; enabling them is a matter of deployer policy. An acceptable argument — the gap then bears on the absence of a refusal to start in production without MFA active |

---

## 7. Software lifecycle — SBOM, CVEs, security advisories, versions

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-24** | Reporting of actively exploited vulnerabilities and severe incidents, per product and per version | Regulation (EU) 2024/2847 — reporting obligations applicable on **11 September 2026** | `git tag -l | wc -l` → **0**; no release workflow; `CHANGELOG.md:9` headed `## [Unreleased]`; `SECURITY.md:5-7`: supported versions = "main \| Yes" | **No identifiable version.** The publisher can designate neither the version affected by a vulnerability nor the one that fixes it; the deployer cannot declare what it is running | **Blocking** | ScamBuster might fall under the CRA's free and open source exemption, in which case the obligation does not fall on the publisher. An unsettled point — it appears among the open questions, and it does not remove the operational need to version |
| **G-25** | Supply chain security — the entity must take account of third-party vulnerabilities and the security of integrated products | Directive (EU) 2022/2555, **Art. 21(2)(d)** | A CycloneDX SBOM **is** produced by the Trivy action (`.github/workflows/ci.yml:332-337`) but **only as a CI artefact kept for 30 days** (`:339-344`); never attached to a version, never published | **The deployer cannot obtain the SBOM of what it is running.** The mechanism exists, the distribution is missing | **Blocking** | A deployer can regenerate the SBOM itself from source. Technically a valid argument, contractually worthless: it does not prove correspondence with the deployed artefact |
| **G-26** | Same | Directive (EU) 2022/2555, Art. 21(2)(d) | **No image is pinned by `@sha256:`**; `nginx:alpine` on a floating tag (`infra/docker/demo/Dockerfile.frontend:20`); `postgres:15-alpine`, `redis:7-alpine`, `node:20-alpine` on moving tags. The GitHub actions, by contrast, **are** pinned by SHA (`ci.yml:18,181,270,311,317,333,340`) | Two builds of the same commit can produce two different images | **Major** | Pinning by digest also freezes the base image's security fixes, and the Dockerfiles run `apt-get upgrade` at build time (`.trivyignore:6-7`). This is an owned and defensible trade-off, but it is written down nowhere |
| **G-27** | Same | Directive (EU) 2022/2555, Art. 21(2)(d) | `.github/workflows/ci.yml:252-255` — Gitleaks v8.21.2 downloaded by tag from GitHub releases, **with no checksum verification** | A security tool enters the build chain with no integrity check | **Moderate** | The tool produces no shipped artefact; compromising it would falsify a check without altering the product. A fair argument, of limited scope |
| **G-28** | Policies and procedures to assess the effectiveness of measures | Directive (EU) 2022/2555, **Art. 21(2)(f)** | `backend-symfony/phpunit.ci.xml` declares **no `functional` suite**; the reason is written down in `ci.yml:103-108`. **97 functional test files** are run in no pipeline | The third of the test coverage that bears on controllers is never run automatically | **Major** | The documented reason is technical and honest — naming the suite silently turned ~855 controller tests red. The workaround is legitimate; it is its permanence that is the problem |
| **G-29** | Same | Directive (EU) 2022/2555, Art. 21(2)(f) | `codecov.yml:2` — `require_ci_to_pass: false`; `auto` targets (`:6-11`); CI upload with `fail_ci_if_error: false` (`ci.yml:184`); **no numeric coverage threshold** in `phpunit.xml.dist` or `phpunit.ci.xml` | No coverage regression can fail an integration | **Moderate** | Coverage thresholds mostly produce box-ticking tests; their absence is a widespread and defensible engineering choice |

---

## 8. Degraded mode and recovery

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-30** | Business continuity, backup management, disaster recovery | Directive (EU) 2022/2555, **Art. 21(2)(c)** | Three guards fail **open** on the same cause — unavailability of the inference provider: `OperationalLeakageDetector.php:59-89` (returns "no leak"), `PaymentInstigationGuard.php:162-179` (approves outside the 12 fallback tokens), `ReplyHandler.php:104-110` (the director brief returns `null`, "replies are never blocked by this gate") | **A single outage degrades three security controls at once, without the service stopping.** The residual deterministic fallback is limited to 12 payment vocabulary patterns | **Blocking** | Each fail open is individually justified in the code, with an explicit reason (`OperationalLeakageDetector.php:21-24`: "The hard gate is the PolicyGuard regex deny-list … it MUST fail open"). The argument holds guard by guard; it no longer holds for the three together, because the failure cause is common |
| **G-31** | Incident handling | Directive (EU) 2022/2555, Art. 21(2)(b) | `src/Application/Communication/PromptInjectionDetector.php:18` — "Detection is forensic -- it does not block ingestion or modify the reply pipeline"; `IngestPostProcessor.php:564-577` emits an audit record with outcome **`'blocked'`** even though nothing is blocked | **The audit record contradicts the actual behaviour.** An analyst reading the log will conclude that a high-risk injection was neutralised | **Major** | The `outcome` field can be read as "the event was classified as blocking", not "the action was blocked". A defensible reading internally, indefensible in an incident file |
| **G-32** | Backups and recovery | Directive (EU) 2022/2555, Art. 21(2)(c) | `infra/docker/backend/scheduler.sh:101-124` — daily `pg_dump`, **size** check (`:109`), 7-day rotation (`:112`) | [INFERRED] No tested restore procedure exists in the repository: the check covers the file size, not its restorability. Reasoning: searched for `pg_restore` in `scripts/`, `infra/` and `Makefile` — no result | **Moderate** | An unrestored backup is still a backup; restore testing is classically an operational procedure, not a product deliverable |
| **G-33** | Same | Directive (EU) 2022/2555, Art. 21(2)(c) | `scheduler.sh:112` — backup retention of **7 days**, containing all the data, including data that has been soft-deleted | [INFERRED] Backup retention and the data retention policy are not articulated together: data soft-deleted at 90 days survives in the dumps for up to 7 days more. Reasoning: the two mechanisms have no point of contact in the code | **Minor** | The 7-day window is short and the backup/purge articulation is a classic problem with no elegant solution |

---

## 9. Data protection — retention, minimisation, information

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-34** | Storage limitation | Regulation (EU) 2016/679, **Art. 5(1)(e)** | `app:purge:rgpd` — the only command applying soft delete at 6 months and **the only one in the source code that hard-deletes** (`PurgeService.php:25,55`) — **does not appear in `infra/docker/backend/scheduler.sh`** (neither at `:16-24` nor at `:40-147`) | **The announced 6/12-month retention is never applied.** The scheduled command, `app:cleanup:weekly`, soft-deletes at 90 days and hard-deletes nothing | **Blocking** | The deployer can schedule the command itself. An acceptable argument **if** it were documented as needing scheduling — but `docs/compliance/gdpr-record-of-processing.md:53` states the opposite: "`app:cleanup:weekly`, automatic" |
| **G-35** | Same | Regulation (EU) 2016/679, Art. 5(1)(e) | **No anonymisation, pseudonymisation or redaction routine exists** in `src/`. The only two operations are "set `deleted_at`" and "physical DELETE" | `docs/09_dpia_template.md:33` and `:146` announce anonymisation at 6 months that has no implementation | **Major** | Anonymising a free-form email body is an open problem; hard deletion is a safer substitute than a poor implementation of it. A solid technical argument — the gap then bears on the claim, not on the absence |
| **G-36** | Same | Regulation (EU) 2016/679, Art. 5(1)(e) and Art. 9 | The `threat_actor_psych_profile` table (`migrations/Version2026070600000000.php:29-36`) stores `behavioural_summary`, `victim_targeting`, `escalation_pattern` — **a psychological profile of a third party generated by an LLM**. No purge; no foreign key to conversations | Behavioural profiles of natural persons are kept indefinitely and **appear in no record of processing** — neither `gdpr-record-of-processing.md` nor `09_dpia_template.md` | **Blocking** | A profile of an adversary's modus operandi is intelligence, not a profile of a person. A defensible argument **up to the moment when** the cluster is linked to an identifiable individual — which is exactly what IOC clustering does |
| **G-37** | Information to data subjects where the data was not collected from them; the disproportionate effort exception is interpreted strictly and comes with appropriate measures, including making the information publicly available | Regulation (EU) 2016/679, **Art. 14(1), 14(2), 14(5)(b)** | No mechanism to inform third parties mentioned in the exchanges. No public availability of processing information | Third parties — victims, mules, persons mentioned — are neither informed nor covered by a substitute measure | **Major** | The disproportionate effort exception is plausible: these people cannot be reliably identified from a scammer's email. A serious argument — but the exception requires **appropriate measures**, including public information, which does not exist |
| **G-38** | Data protection impact assessment | Regulation (EU) 2016/679, **Art. 35** | `docs/09_dpia_template.md` — the file name says "template"; the document describes controls that are not implemented (anonymisation, 16 patterns, RLS) | [INFERRED] The deliverable is a template and not an assessment that was carried out, and it describes a system that is not the one in the code. A deployer relying on it would produce a false DPIA. Reasoning: three claims verifiably contradicted by the code (DOC-05, DOC-09, DOC-11) | **Blocking** | A template is exactly what a publisher should provide — the DPIA is the controller's responsibility. A fair argument in principle; it does not cover the fact that the template describes controls that do not exist |
| **G-39** | Processing of special categories of data | Regulation (EU) 2016/679, **Art. 9(1)** | No sorting or detection of Art. 9 data at ingestion. `MessageAnonymizer` (`:23-37`) masks 5 patterns, **and only for prompt construction** (`ContextualEnricher.php:26`), never for storage | Health, opinion or orientation data present in an email body or an OCR'd attachment is stored without qualification and without an Art. 9(2) exception | **Major** | Automatic detection of Art. 9 data in free text is unreliable and would produce just as many false negatives. An acceptable technical argument; it calls for a response through data minimisation at ingestion rather than through detection |

---

## 10. Accreditation documentation

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-40** | Policies and procedures to assess the effectiveness of risk management measures | Directive (EU) 2022/2555, **Art. 21(2)(f)**; product technical documentation, Regulation (EU) 2024/2847 | **25 proven documentation/code contradictions** (`00_inventory.md` §11), including: announced controls with no implementation (DOC-07 "drugs, weapons, CSAM"; DOC-11 RLS policies), false counts (DOC-06 permissions, DOC-09 patterns), encryption at rest announced and absent (DOC-19), retention announced as automatic and not scheduled (DOC-03, DOC-04) | **The documentation set cannot serve as the basis for a risk acceptance**: a deployer relying on it will describe a system that does not exist | **Blocking** | The code is authoritative and readable; an engineer can resolve each contradiction alone. An acceptable argument for a deployer with PHP engineering capacity, worthless for a risk acceptance signed by a management body |
| **G-41** | Same | Same | `migrations/Version2026041200100000.php:20-24` references an operational step in a runbook that does not contain it; `docs/runbooks/audit-hmac-key-rotation.md:32-33` references a "rebuild script" that exists nowhere | Two critical operational procedures point to documentation that does not exist | **Major** | A gap that is cheap to fix; its severity comes from the fact that it bears precisely on the two procedures that condition log integrity |

---

## 11. Gaps from the additional verification pass

> Added after closing the verifications that had remained open (`00_inventory.md`
> §4.11 and §4.12). The identifiers continue the numbering.

| ID | Requirement | Legal source | Current state [VERIFIED] | Gap | Severity | Best argument for not addressing it now |
|---|---|---|---|---|---|---|
| **G-42** | Data minimisation; security of processing | Regulation (EU) 2016/679, **Art. 5(1)(c)** and **Art. 32** | The three SIEM formatters emit `actorId` and `ipAddress` verbatim (`JsonFormatter.php:29,33`; `EcsFormatter.php:40,44`; `CefFormatter.php:95,99`) and serialise `details` with no filtering. Searched for any masking under `src/Infrastructure/Siem/`: **no result**. `actorId` carries a plain-text email address on the OIDC path (`OidcCallbackController.php:83`) | **No masking on an output path**, even though `docs/04_security_guardrails.md:400` states the opposite (DOC-25) | **Major** | An enterprise SIEM is a trusted system, a legitimate recipient of actor identifiers: masking there would destroy investigative capability. A fair argument for `actorId`, debatable for `details`, whose content is unbounded, and with no effect on the documentation contradiction |
| **G-43** | Access control policies | Directive (EU) 2022/2555, **Art. 21(2)(i)** | `OIDC_REDIRECT_URI` and `successRedirect` are used **with no scheme constraint and no allowlist** (`OidcService.php:43,112`; `OidcCallbackController.php:106,119`); when `successRedirect` is non-empty, `access_token` and `refresh_token` are placed in the **URL fragment** of a 302 redirect to that target (`:99-107`) | Access and refresh tokens are handed to an unvalidated redirect target | **Major** | The module is **opt-in and disabled by default** (`config/services.yaml:26`), and the target is set by operational configuration, not by user input. The severity depends entirely on the deployer's rigour — which is exactly what a product intended for a third party must not assume |
| **G-44** | Incident handling: detection and response | Directive (EU) 2022/2555, **Art. 21(2)(b)** | `infra/monitoring/prometheus/alert.rules.yml` contains only **4 rules**: kill switch, dependency unavailable, metrics unreachable, ingestion stopped | **No alert on any security event**: no authentication failure rate, no brute force, no rate limiter exhaustion, no injection detection, no budget threshold — even though audit event types exist for all of them | **Major** | Alerting rules are classically a matter for the deployer's monitoring, and the product already exposes the events in the database and to the SIEM. An acceptable argument — the gap then bears on the absence of a shipped reference rule set |

**One point raised and not retained as a gap.** The signature of the OIDC identity token
is not verified (`OidcService.php:162-184`). The code **declares the omission
intentional**, invoking OIDC Core §3.1.3.7 — the token being obtained through the back
channel — and puts in place a UserInfo cross-check as substitute evidence
(`:63-71`). This reading of the specification is correct. The residual point is carried
as non-binding opinion A-09.

---

## Non-binding opinions

> Architect judgements that **I link to no reference framework**. They are not gaps
> and must not be treated as such.

| # | Opinion | Factual basis |
|---|---|---|
| A-01 | Classifying `payment_token` as an informational code, with a baseline of **0.294**, means that ~29% of the reference outputs contain payment infrastructure vocabulary and that an increase can never fail the GUARD gate. This is a design choice I find hard to defend, but no reference framework forbids it | `tests/Smoke/guard-baseline.json`; `SafetyInvariantOracle.php:70-72`; `CanaryBaselineComparator.php:116-119` |
| A-02 | The 25 injection detection patterns are **English-only** while the fixture corpus is EN/FR/DE/ES. A clear imbalance, with no legal obligation of language coverage | `PromptInjectionPatternMatcher.php:19-124`; fixtures `tests/Smoke/CialdiniMirrorFixtures/` |
| A-03 | Homoglyph normalisation **degrades silently** if the intl extension is absent (`:273-274`), with nothing flagging it at startup. A design flaw, not a compliance one | `PromptInjectionPatternMatcher.php:273-274` |
| A-04 | Two competing LLM abstractions and two field encryption mechanisms with two distinct key sources (`APP_SECRET` derived, `TOTP_ENCRYPTION_KEY` direct) constitute consistency debt | `config/services.yaml:525-532`; `SmtpDsnEncryptor.php:43`; `EncryptedStringType.php:97` |
| A-05 | The private IP filter covers only 4 ranges — neither `169.254/16`, nor `100.64/10`, nor the IPv6 private ranges. Extraction quality, not compliance | `IocExtractorOrchestrator.php:285-299` |
| A-06 | Rotating `APP_SECRET` makes all encrypted SMTP DSNs unreadable, and the code acknowledges this by pointing to a "future spec". Owned and documented debt | `SmtpDsnEncryptor.php:22-23` |
| A-07 | `getQuarantinedCount()` is a stub returning 0, which makes quarantining invisible to monitoring | `SenderFloodDetector.php:84-89` |
| A-08 | A fixed `MIN_HOURS_BETWEEN_REPLIES = 6` creates an exploitable timing signature across several conversations. Threat T1 is real, but **made moot by G-01** if transparency is imposed | `ReplyCadenceService.php:27` |
| A-09 | Not verifying the OIDC identity token signature complies with OIDC Core §3.1.3.7 **provided** the token endpoint is reached over TLS. But the expected issuer comes from the discovery document itself (`OidcService.php:61,191-193`) and no scheme assertion exists: the chain of trust rests entirely on `OIDC_DISCOVERY_URL`. A design observation, with no binding reference framework | `OidcService.php:16-21`, `:61`, `:107-117` |
| A-10 | The OIDC `state` is stateless and signed, therefore **replayable within its 600 s window** for lack of a single-use registry (`OidcStateManager.php:12-13`, `:21`); `iat` is never read and no clock drift tolerance is provided (`:214-218`) | `OidcStateManager.php:21`; `OidcService.php:214-218` |
| A-11 | An **absent** `email_verified` claim counts as `true` (`OidcService.php:82`), and the empty domain allowlist — the shipped value — accepts any domain (`OidcConfig.php:51-53`). Since automatic provisioning is disabled by default, the effect is bounded | `OidcService.php:82-90`; `config/services.yaml:34,38` |

---

## The 5 gaps genuinely blocking for the pilot scenario

Selection under constraint: these are the five points **without which a regulated
third-party deployer cannot go live**, ordered by decreasing severity.

| Rank | Gap | Groups | Why this one and not another |
|---|---|---|---|
| **1** | **G-01/G-02 — AI system transparency** | G-01, G-02 | The only gap that puts the **viability of the product** in question in the chosen scenario, rather than its quality. An obligation applicable since 2 August 2026, falling on the **publisher**, and actively opposed by three mechanisms in the code. Every other gap is fixed by design work; this one first requires a decision of principle |
| **2** | **G-21 — No separation between generating and sending** | G-21 | The shipped pipeline chains generation and sending with no stop point. `/send-email` carries the same permission as generation. A NIS2 management body must approve and supervise the measures (Art. 20); it cannot supervise an act that commits the entity towards a third party when no control distinguishes it from generating a draft |
| **3** | **G-03/G-04/G-05 — Inference sovereignty** | G-03, G-04, G-05 | The full content of third-party emails leaves by default, and **setting a local provider is not enough**: 7 hard-coded sites and an embeddings service with no abstraction. The entity cannot demonstrate control of the flow, which is distinct from the lawfulness of the transfer |
| **4** | **G-07/G-08 — Zoning and egress filtering** | G-07, G-08 | The component that speaks IMAP to adversaries and downloads their attachments shares the network of the data store, with no egress filtering at all. This is the technical precondition for all the others: with no zone, neither G-03 nor G-11 can be demonstrated |
| **5** | **G-24/G-25 — Identifiable version and distributed SBOM** | G-24, G-25 | With no tag and no SBOM attached to a version, the deployer can neither declare what it is running under NIS2 Art. 21(2)(d), nor receive a usable security advisory. **CRA deadline of 11 September 2026.** Low fix cost, high leverage on everything else |

### Gaps I would leave out of the immediate scope

Sorted by reason for exclusion, with the argument retained.

| Excluded | Reason |
|---|---|
| **G-30 — correlated fail opens** | Blocking on the merits, but **conditional on G-01**: if transparency is imposed, `OperationalLeakageDetector` changes purpose and the reasoning about fail opens has to be redone. Addressing it first would be building on an assumption currently under revision |
| **G-11 — non-financial IOCs with no verdict** | The argument for not addressing it is strong: holding all types behind a human verdict would make the product unusable by a single person. Calls for a **graduated response** in phase 3, not a block |
| **G-34 — unscheduled purge** | A one-line fix in `scheduler.sh`. The real blocker is not the purge but **G-40**, the documentation that states the opposite |
| **G-36 — psychological profiles** | Blocking on the merits, but the qualification — adversary intelligence or profile of a person — is a **legal decision for the deployer**, not a product gap. To be documented, not built |
| **G-38 — DPIA still a template** | The DPIA is the controller's responsibility. The real product gap is that the template describes controls that do not exist, and is therefore **absorbed by G-40** |
| **G-16, G-17, G-18 — immutability and audit coverage** | Downgraded by the choice of S2: digital evidence rules are out of scope (`01_scope.md` §B.4 X5). They remain NIS2 Art. 21(2) gaps, to be addressed in the first cycle, not before go-live |
| **G-23 — MFA not mandatory** | Both mechanisms exist; the gap is the absence of a refusal to start. A trivial fix, with no structuring effect |
| **G-06, G-27, G-29, G-32, G-33** | Moderate to minor, arguments for not addressing them acceptable as they stand |
| **G-19, G-20 — PII in logs** | Moderate; the volume is low and the path is rare |
| **G-31 — misleading `'blocked'` audit** | A label fix. Real, but with no cost and no structure |

---

## What I could not verify — phase 2

1. Does ScamBuster fall under the free and open source exemption of Regulation (EU)
   2024/2847, or under the "open source steward" category — which would decide whether
   G-24 is a legal gap or only an operational need?
2. Do the Commission guidelines of 20 July 2026 address the asynchronous channel:
   where should the notice of artificial nature appear in an email?
3. Has a data protection authority published a position on honeypot mailboxes and
   automated engagement with an unsolicited sender?
4. Is the target deployer classified as an **essential** or an **important** entity —
   the supervision obligations, and therefore the severity of several gaps, depend on
   it?
5. Does a modus operandi profile attached to a cluster of IOCs constitute personal data
   within the meaning of Art. 4(1) when the cluster identifies no named person? The
   answer tips G-36 one way or the other.
6. Do the IOCs already exported to OpenCTI contain data on third parties who are not
   scammers, and does the OpenCTI node redistribute to third parties?
7. Is `docker-compose.prod.yml` presented as a supported production target, or as an
   example — which changes the severity of G-07 and G-08?
8. Can the `preprod` compose profile be active in production, which would make
   `OpenAIService` (G-06) reachable outside preproduction?
9. Is there a known technical reason preventing `EmbeddingService` from going through
   `LLMClientInterface`, or is it an omission?
10. Has a backup restore ever been carried out successfully, in any environment
    whatsoever?
11. Is the PHP `intl` extension guaranteed present in the shipped images — on which
    depends whether A-03 is a theoretical or an actual risk?
12. What is the publisher's position on the fact that half of the product's security
    apparatus protects an invariant that Art. 50(1) prohibits in the chosen scenario?
13. Were the `reply:generate` permission and a possible separate send permission ever
    considered, and rejected for a reason I did not find?
14. Does the CycloneDX SBOM produced by Trivy cover the PHP and npm dependencies, or
    only the system packages of the image?

---

*End of phase 2 — protocol stop STOP 2.*
