# Phase 0 — Inventaire factuel

> **Nature de ce document.** Descriptif pur. Aucun jugement, aucune recommandation.
> Statuts : `[VÉRIFIÉ]` = lu dans un fichier (chemin + ligne). `[DÉDUIT]` = inférence,
> raisonnement explicité. `[INCONNU]` = non déterminable depuis les fichiers.
>
> Commit audité : `f3b2188` — branche `claude/scambuster-security-audit-ftmolg`.
> Méthode : exploration déléguée à 6 sous-agents en lecture seule, consignes
> « chemin + ligne obligatoire, commandes exactes rejouables ».

---

## 0. Métadonnées du dépôt

| Fait | Valeur | Statut |
|---|---|---|
| Nombre de commits dans l'historique public | 10 | [VÉRIFIÉ] `git rev-list --count HEAD` |
| Premier commit | `7e71739` — 2026-08-05 — « ScamBuster initial public release » | [VÉRIFIÉ] `git log --reverse` |
| Dernier commit | `f3b2188` — 2026-08-10 — « Also exclude false positives from cluster anchor persistence (#46) » | [VÉRIFIÉ] `git log -1` |
| Étendue temporelle de l'historique | 2026-08-05 → 2026-08-10 (6 jours) | [VÉRIFIÉ] `git log --format='%ad' --date=format:'%Y-%m' \| sort \| uniq -c` → `10 2026-08` |
| Contributeurs | 2 identités, une seule personne physique : `laugiov@users.noreply.github.com` (9), `laurent.giovannoni@filigran.io` (1) | [VÉRIFIÉ] `git shortlog -sne HEAD` |
| Tags git | **0** | [VÉRIFIÉ] `git tag -l \| wc -l` → `0` |
| Signature des commits | 9 commits portent une signature SSH non vérifiable dans cet environnement (`%G?` = `E`, `gpg.ssh.allowedSignersFile` absent) ; 1 commit non signé (`7e71739`, `%G?` = `N`) | [VÉRIFIÉ] `git log --format='%h\|%G?\|%GS\|%s'` |
| Licence | MIT, « Copyright (c) 2025-2026 Laurent Giovannoni » | [VÉRIFIÉ] `LICENSE:1-3` |
| Volume | 1905 fichiers hors `.git` ; 1283 `.php`, 196 `.tsx`, 99 `.eml`, 92 `.ts`, 56 `.md`, 43 `.yaml` | [VÉRIFIÉ] `find . -path ./.git -prune -o -type f -print \| wc -l` puis `\| sed 's/.*\.//' \| sort \| uniq -c \| sort -rn` |
| Cibles Makefile | 96 | [VÉRIFIÉ] `grep -cE '^[a-zA-Z0-9_.-]+:' Makefile` |

**Écart historique / production.** Le sujet de l'audit indique une mise en production
en novembre 2025 ; l'historique git public commence le 2026-08-05 par un commit unique
« initial public release ». [DÉDUIT] L'historique publié est un squash : les 9 mois
d'évolution antérieurs ne sont pas dans ce dépôt, donc ni les décisions de conception,
ni les correctifs de sécurité passés, ni l'évolution des garde-fous ne sont traçables
depuis git. Raisonnement : 10 commits couvrant 6 jours pour 1905 fichiers et
66 migrations dont la plus ancienne est datée `Version20250510093411`
(mai 2025) — les migrations témoignent d'une histoire que les commits ne portent pas.

---

## 1. Arborescence réelle et rôle de chaque module

### 1.1 Couches de `backend-symfony/src` [VÉRIFIÉ] `ls -d backend-symfony/src/*/`

| Couche | Chemin | Contenu |
|---|---|---|
| Application | `backend-symfony/src/Application` | 19 sous-modules |
| Domain | `backend-symfony/src/Domain` | 11 sous-modules |
| Infrastructure | `backend-symfony/src/Infrastructure` | 11 sous-modules |
| Security | `backend-symfony/src/Security` | 5 classes à plat |
| UI | `backend-symfony/src/UI` | `Console`, `Http` |
| DataFixtures | `backend-symfony/src/DataFixtures` | `Campaign`, `Communication`, `Scambaiting`, `User` |

#### Sous-modules Application [VÉRIFIÉ] `ls -d backend-symfony/src/Application/*/`

| Module | Fichier témoin | Rôle |
|---|---|---|
| Audit | `src/Application/Audit/AuditHmacChainer.php` | Journalisation d'événements d'audit, chaînage HMAC, audit qualité des conversations |
| Auth | `src/Application/Auth/AuthService.php`, `.../Oidc` | Login, vérification TOTP (`TotpVerifier.php`), OIDC, génération de login-hash |
| Campaign | `src/Application/Campaign/CampaignHunter.php`, `DSLTranspiler.php` | Chasse et profilage de campagnes, promotion, transpilation de règles DSL, export STIX campagne |
| Clustering | `src/Application/Clustering/IocClusteringService.php` | Regroupement d'acteurs par IOC, analyse temporelle |
| Communication | `src/Application/Communication/IngestHandler.php` — **66 fichiers** | Ingestion email, threading, extraction/normalisation/validation d'IOC, classification, scoring de risque, génération et composition de réponse, envoi SMTP, personas, TTP, détection d'injection de prompt |
| Evaluation | `src/Application/Evaluation/BanditAnalyzer.php`, `IocExtractionMetrics.php` | Génération de corpus, métriques juge-LLM, intervalles de confiance, analyse qualité de réponse |
| Export | `src/Application/Export/IocFeedExporter.php` | Export du flux d'IOC |
| Guard | `src/Application/Guard/PromptCanaryService.php` | Smoke runs de canari de prompt, comparaison au baseline |
| LLM | `src/Application/LLM/ContextAnalyzer.php`, `EmbeddingService.php`, `CostEstimator.php`, `Director/` | Orchestration LLM, enrichissement contextuel, embeddings, estimation de coût |
| Meta | `src/Application/Meta/ConfigHandler.php` | Exposition de config runtime, copie/purge preprod |
| Monitoring | `src/Application/Monitoring/HealthCheckHandler.php` | Analytique, health checks, supervision d'autonomie et de cycle de vie, notification de seuil budgétaire |
| Prompt | `src/Application/Prompt/PromptOverrideHandler.php` | Surcharges de prompt par l'opérateur, jobs de canari, validation de corps |
| Scambaiting | `src/Application/Scambaiting/PersonaOptimizer.php`, `BanditConvergenceReporter.php` | Sélection/optimisation de persona, miroirs cognitifs, clôture de conversation |
| Stats | `src/Application/Stats/FinancialRevealTimingService.php` | Statistiques de timing de révélation financière et d'urgence |
| Stix | `src/Application/Stix/ConversationStixExportHandler.php`, `ExtensionSchemaValidator.php` | Construction de bundles STIX 2.1, validation d'extensions personnalisées |
| Taxii | `src/Application/Taxii/TaxiiService.php` | Service de collections/objets TAXII |
| ThreatActor | `src/Application/ThreatActor/ThreatActorPsychProfileGenerator.php`, `AbuseReportGenerator.php` | Profilage psychologique d'acteur, rapports d'abus, retour analyste sur IOC |
| Ttp | `src/Application/Ttp/TtpHandler.php` | Upsert et requêtage d'observations TTP |
| User | `src/Application/User/UserPasswordValidator.php` | Politique de mot de passe |

#### Sous-modules Domain [VÉRIFIÉ] `ls -d backend-symfony/src/Domain/*/`

| Module | Fichier témoin | Contenu |
|---|---|---|
| Audit | `src/Domain/Audit/AuditLog.php`, `SiemEvent.php`, `SiemSeverityMap.php` | Entité journal d'audit, types d'événements, mapping de sévérité SIEM |
| CampaignRadar | `src/Domain/CampaignRadar/Campaign.php`, `CampaignRule.php`, `ActorProfile.php` | Entités campagne + interface de dépôt + enum de statut |
| Clustering | `src/Domain/Clustering/Service/ClusterStixIdGenerator.php`, `ValueObject/NormalizedIocValue.php` | Génération d'ID STIX de cluster, VO de valeur d'IOC normalisée |
| Communication | `src/Domain/Communication/Conversation.php`, `Attachment.php`, `Channel.php`, `Direction.php` | Entités cœur conversation/message/canal + interfaces de dépôt |
| Exception | `src/Domain/Exception/DomainException.php` | Exception de domaine de base |
| LLM | `src/Domain/LLM/PipelineTrace.php`, `ComponentTrace.php`, `LlmUsageRecord.php` | Traces et enregistrements d'usage LLM, événements/exceptions |
| Prompt | `src/Domain/Prompt/PromptOverride.php`, `PromptCanaryJob.php` | Entités de surcharge de prompt et de job de canari, enum `CanaryJobStatus` |
| Scambaiting | `src/Domain/Scambaiting/PersonaPerformance.php`, `BanditConvergenceLog.php`, `ConversationMetrics.php` | Entités de performance de persona, convergence bandit, métriques |
| ThreatActor | `src/Domain/ThreatActor/ThreatActorPsychProfile.php`, `CialdiniLever.php`, `AnalystVerdict.php` | Entité profil psychologique, enum leviers de Cialdini, enum verdict analyste |
| User | `src/Domain/User/User.php`, `Permission.php`, `RefreshToken.php` | Entités utilisateur/permission/refresh-token |
| Validation | `src/Domain/Validation/LeakageDetectionResult.php`, `StructuredCorrection.php` | VO de résultat de détection de fuite et de correction structurée |

#### Sous-modules Infrastructure [VÉRIFIÉ] `ls -d backend-symfony/src/Infrastructure/*/`

| Module | Fichier témoin | Contenu |
|---|---|---|
| Audit | `src/Infrastructure/Audit/HttpRequestContext.php` | Capture du contexte de requête HTTP pour l'audit |
| Auth | `src/Infrastructure/Auth/DoctrineUserTotpChecker.php` | Vérificateur TOTP sur Doctrine |
| Campaign | `src/Infrastructure/Campaign/Doctrine/` | Dépôts Doctrine campagne |
| Doctrine | `src/Infrastructure/Doctrine/Repository/` (12 fichiers), `Type/EncryptedStringType.php` | Dépôts ORM + type DBAL personnalisé `EncryptedStringType` |
| EventListener | `src/Infrastructure/EventListener/KernelExceptionListener.php`, `LlmUsageListener.php`, `ConversationEndedListener.php` | Écouteurs noyau / auth / conversation / usage LLM |
| Guard | `src/Infrastructure/Guard/InProcessSmokeRunner.php` | Exécuteur de smoke de canari en processus |
| LLM | `src/Infrastructure/LLM/Provider/{AnthropicClient,OpenAIClient,OllamaClient,MockLLMClient}.php`, `LLMProviderCompilerPass.php` | Clients LLM concrets + passe de compilation DI |
| Mailer | `src/Infrastructure/Mailer/TransportFactory.php` | Construction du transport mail |
| Preprod | `src/Infrastructure/Preprod/ConversationGenerator.php`, `IocGenerator.php`, `ScamTemplates.php` | Génération de données préprod synthétiques |
| Prompt | `src/Infrastructure/Prompt/CachedDbPromptOverrideSource.php`, `CompositePromptOverrideSource.php` | Sources de surcharge de prompt (BDD / fichier) |
| Siem | `src/Infrastructure/Siem/Adapter/{FileSiemExporter,SyslogSiemExporter,NullSiemExporter}.php`, `SiemCompilerPass.php` | Exporteurs SIEM + passe de compilation DI |

#### Couche Security [VÉRIFIÉ] `ls backend-symfony/src/Security/`

`PermissionVoter.php`, `CustomAccessDeniedHandler.php`, `SecretPolicy.php`,
`TaxiiApiKeyAuthenticator.php`, `TestCsrfTokenManager.php`, `TestTokenAuthenticator.php`.

#### Couche UI/Http

**148 attributs `#[Route]`** [VÉRIFIÉ] `grep -rn "#\[Route" backend-symfony/src/UI/Http --include="*.php" | wc -l` → `148`.

Répartition par sous-dossier : `Admin` (2), `Auth` (10), `Campaign` (10), `Clustering` (7),
`Communication` (~50), `Dto` (21 DTO de réponse), `Internal` (1), `Meta` (1), `Personas` (2),
`Monitoring` (24), `Prompt` (7), `Scambaiting` (7), `Stats` (2), `Taxii` (4), `ThreatActor` (2),
`Ttp` (9), `User` (4), plus `HealthController.php`.

### 1.2 `backend-symfony/config` [VÉRIFIÉ] `ls -R backend-symfony/config`

| Chemin | Contenu |
|---|---|
| `config/packages/` (26 fichiers) | `doctrine.yaml`, `security.yaml`, `llm.yaml`, `rate_limiter.yaml`, `scheb_2fa.yaml`, `lexik_jwt_authentication.yaml`, `monolog.yaml`, `nelmio_api_doc.yaml`, `nelmio_cors.yaml`, `campaign.yaml`, `scambuster.yaml`, `lock.yaml`, `cache.yaml`, `mailer.yaml` |
| `config/packages/{e2e,test,prod}/` | Surcharges par environnement (`e2e/` 6 fichiers, `test/` 5, `prod/doctrine.yaml`) |
| `config/scambuster/scambuster.defaults.yaml` | Scalaires réglables par l'opérateur ; **un seul paramètre présent** : `scambuster.reward.llm_weight: 0.7` [VÉRIFIÉ] `config/scambuster/scambuster.defaults.yaml:9` |
| `config/scambuster/prompts/` | **Contient uniquement `README.md`** ; documente une résolution de surcharge par dépôt de fichiers `<clé>.txt` via `PromptProvider` [VÉRIFIÉ] `config/scambuster/prompts/README.md:1-12` |
| `config/stix-schemas/` | `x_scambuster_context.schema.json`, `x_scambuster_mirror.schema.json`, `x_scambuster_ttp_sighting.schema.json` |
| Racine config | `bundles.php`, `services.yaml`, `services_test.yaml`, `services_e2e.yaml`, `bootstrap.php`, `preload.php`, `reference.php`, `routes.yaml` |

Chaîne de surcharge : `config/packages/scambuster.yaml:9-10` importe les valeurs par défaut
puis `../scambuster/scambuster.yaml` avec `ignore_errors: not_found` [VÉRIFIÉ].

### 1.3 `backend-symfony/migrations` [VÉRIFIÉ] `ls backend-symfony/migrations`

- 65 classes de migration `.php` + un répertoire `data/` (66 entrées).
- Plage de noms : `Version20250510093411.php` (mai 2025) → `Version2026080700000000.php`.
- **Deux conventions de nommage coexistent** : horodatage 14 chiffres et 16 chiffres.
- `migrations/data/` : `insert_personas.sql`, `link_scam_types_personas.sql`,
  `personas_27.json`, `preprod_reference_data.sql`.

### 1.4 `frontend-react/src` [VÉRIFIÉ] `ls frontend-react/src/*`

| Chemin | Contenu |
|---|---|
| `App.tsx`, `main.tsx`, `index.css` | Racine applicative / point d'entrée Vite / CSS global |
| `src/pages/` | 34 pages (+ tests colocalisés) : `Dashboard`, `Conversations`, `ConversationDetail`, `ConversationMonitoring`, `IocExplorer`, `IocDetail`, `Clusters`, `ClusterDetail`, `Campaigns`, `CampaignDetail`, `TtpExplorer`, `TtpDetail`, `Personas`, `PersonaMatrix`, `PersonaMirror`, `Theater`, `Analytics`, `Impact`, `LlmCosts`, `PipelineMonitor`, `InjectionMonitoring`, `ConvergenceHistory`, `StixExport`, `PromptCustomization`, `Settings`, `Login` |
| `src/components/` | `clusters`, `conversation`, `feedback`, `impact`, `ioc`, `layout`, `personas`, `promptOverrides`, `theater`, `ttp`, `ui` |
| `src/api/` | `client.ts`, `endpoints.ts`, `__tests__` |
| `src/hooks/` | 30+ hooks de données + `MaskModeProvider.tsx` |
| `src/lib/` | `format.ts`, `csv.ts`, `iocCategory.ts`, `clusterVerdict.ts`, `actorColors.ts`, `domainVariants.ts` |
| `src/store/` | `authStore.ts` (store unique) |
| `src/types/` | `api.ts`, `threatActor.ts`, `ttp.ts` |
| `src/i18n/` | `index.ts` + `locales/` |

### 1.5 `n8n/workflows` [VÉRIFIÉ] lecture JSON des 4 fichiers

| Fichier | `active` | Déclencheur | Enchaînement factuel des nœuds |
|---|---|---|---|
| `WF-INTAKE-EMAIL-V2.json` | `true` | `n8n-nodes-base.emailReadImap` (`customEmailConfig: ["UNSEEN"]`, `downloadAttachments: true`) | IMAP → `Extract Email Data` → `Retrieve Token` (POST `/api/v1/auth/login`) → `Prepare Payload` → `Ingest Email` (POST `/api/v1/communication/ingest/raw`) → `Get Risk Assessment` (GET `/message/{msg_id}/risk`) → `Decision Gate` → `Trigger Reply Generation` ou `Skip Reply` ; boucle `splitInBatches` vers `WF-EXTRACT-AND-ENRICH-IOC` |
| `WF-REPLY-GENERATE-V2.json` | `true` | `executeWorkflowTrigger` | `00_auth_login` → `10_fetch_context` → `20_generate` (POST `/reply/generate`) → `40_store_draft` → `Prepare SEND data` → appel `WF-REPLY-SEND-v1` |
| `WF-REPLY-SEND-v1.json` | **`false`** | `executeWorkflowTrigger` | `00_auth_login` → `10_fetch_reply` → `12_fetch_compose` → `Calculate Human Delay` → `Wait Until Send Time` → `20_backend_send` (POST `/reply/{msg_id}/send-email`) → `22_extract_message_id` → `30_confirm` → `40_notify` → `respondToWebhook` |
| `WF-EXTRACT-AND-ENRICH-IOC.json` | `true` | `executeWorkflowTrigger` | `Retrieve Token` → `Extract IOCs via Backend API (LLM)` → `Parse API Response` → `Filter URLs & Domains` → **`URLScan: Scan URL`** + **`VirusTotal: Scan URL`** → `Wait 1 Minute` → récupération des rapports → `Merge URLscan + VT` → `Map Enrichment Data` → PATCH `/api/v1/iocs/{obs_id}/enrich` |

### 1.6 `infra/` [VÉRIFIÉ] `find infra -type f`

| Chemin | Contenu |
|---|---|
| `infra/docker/backend/` | `Dockerfile`, `Dockerfile.prod`, `docker-entrypoint-prod.sh`, `write-prod-env.sh`, `nginx-prod.conf`, `supervisord.conf` (php-fpm + nginx, `:9,:18`), `scheduler.sh`, `canary-worker.sh`, `prod-seed-reference.sql` |
| `infra/docker/frontend/Dockerfile` | Image frontend |
| `infra/docker/nginx/nginx.conf` | Config nginx de dev |
| `infra/docker/demo/` | `Dockerfile.backend`, `Dockerfile.frontend`, `docker-entrypoint-demo.sh`, `nginx-demo.conf.template` |
| `infra/monitoring/` | `docker-compose.yml` (prometheus `prom/prometheus:v2.54.1` `:13`, grafana `grafana/grafana:11.2.0` `:27`), `prometheus/prometheus.yml`, `prometheus/alert.rules.yml`, `grafana/dashboards/scambuster-security.json`, `grafana/provisioning/` |

### 1.7 `scripts/` [VÉRIFIÉ] en-têtes de chaque script

| Script | Objet déclaré |
|---|---|
| `scripts/check-env.sh:2` | Validation des variables d'environnement avant démarrage |
| `scripts/check-honeypot-leak.sh:3` | Garde contre la fuite accidentelle de noms de honeypot |
| `scripts/doctor.sh:3` | Contrôle de santé environnement et connectivité |
| `scripts/generate-jwt-keys.sh:2` | Génération d'une paire de clés RSA 2048 RS256 dans `backend-symfony/config/jwt/` |
| `scripts/rotate-jwt-keys.sh:2` | Rotation des clés JWT RS256 sans interruption |
| `scripts/install-pre-commit-hook.sh:3` | Installation de `check-honeypot-leak.sh` en hook pre-commit |
| `scripts/hooks/pre-push-guard.sh:3` | Hook pre-push rappelant/imposant (via `GUARD_ON_PUSH=1`) le garde LLM réel |
| `scripts/preflight.sh:3` | Contrôles avant quickstart destructif |
| `scripts/preflight-check.sh:3` | Exécuteur local à 8 portes |
| `scripts/validate-install.sh:2` | Validation d'installation |
| `scripts/validate-n8n-workflows.sh:2` | Validation des JSON n8n contre les valeurs en dur |
| `scripts/check-credentials.py` | Aucun en-tête de description [INCONNU] |
| `scripts/honeypot-names.txt.example` | Exemple de liste de noms de honeypot |
| `scripts/test-llm/test-{all,anthropic,openai,ollama}.sh` | Tests de connectivité par fournisseur LLM |

### 1.8 `docs/` [VÉRIFIÉ] `find docs -type f`

| Groupe | Fichiers |
|---|---|
| Produit / concept (01–08) | `01_problem_statement`, `02_value_proposition`, `03_high_level_architecture`, `04_security_guardrails`, `05_evaluation_methodology`, `06_roadmap`, `07_faq`, `08_getting_started` |
| Conformité / juridique | `09_dpia_template`, `10_threat_model`, `compliance/{README,breach-notification-procedure,data-classification,data-processing-agreements,gdpr-record-of-processing,mule-victim-account-policy,risk-register}` |
| Intégrations CTI | `11_opencti_integration`, `13_misp_integration`, `15_siem_integration`, `16_taxii_server` |
| API / exploitation | `12_api_quick_reference`, `14_key_management`, `17_email_provider_setup`, `20_enterprise_sso` |
| Qualité / métriques | `18_data_validation`, `19_data_quality_audit`, `22_metrics_catalog`, `24_analyst_feedback` |
| Guides d'écran analyste | `21_threat_actor_profiling`, `23_reading_the_threat_actor_screen`, `25_prompt_customization`, `26_reading_the_ttp_screens` |
| Déploiement / démo | `AI_DEPLOYMENT`, `DEMO`, `QUICKSTART` |
| Runbooks | `runbooks/{RACI,audit-hmac-key-rotation,incident-response-plan,n8n-credentials,post-mortem-template,production-deployment,totp-key-rotation}` |

### 1.9 Services `docker-compose*.yml` [VÉRIFIÉ]

| Fichier | Service | Image / build | Note |
|---|---|---|---|
| `docker-compose.yml:2` | `postgres` | `postgres:15-alpine` | |
| `docker-compose.yml:17` | `postgres-preprod` | `postgres:15-alpine` (`:22`) | `profiles: ["preprod"]` (`:21`) |
| `docker-compose.yml:41` | `redis` | `redis:7-alpine` | |
| `docker-compose.yml:56` | `backend-dev` | build `context: .` (`:58`) | `command: php -S 0.0.0.0:8080 -t public` (`:74`) |
| `docker-compose.yml:78` | `backend-test` | hérite | `profiles: ["test"]` (`:82`) |
| `docker-compose.yml:97` | `backend-e2e` | hérite | `profiles: ["test"]` (`:100`) |
| `docker-compose.yml:115` | `backend-preprod` | hérite | `profiles: ["preprod"]` (`:118`) |
| `docker-compose.yml:139` | `scheduler` | image backend | `command: sh /opt/scheduler.sh` (`:143`) |
| `docker-compose.yml:166` | `canary-worker` | image backend | `profiles: ["canary"]` (`:168`) |
| `docker-compose.yml:186` | `frontend` | build (`:188`) | |
| `docker-compose.yml:215` | `n8n` | `n8nio/n8n:1.114.3` (`:216`) | |
| `docker-compose.prod.yml:16,42,58,67,105` | `app`, `postgres`, `redis`, `n8n`, `scheduler` | build / `postgres:15-alpine` / `redis:7-alpine` / `n8nio/n8n:1.114.3` / build | volumes `prod-pgdata`, `prod-n8ndata`, `prod-backups` (`:129-131`) |
| `docker-compose.demo.yml:22,38,48,98` | `postgres`, `redis`, `backend`, `frontend` | `postgres:15-alpine`, `redis:7-alpine`, build, build | **ni `n8n` ni `scheduler`** |
| `infra/monitoring/docker-compose.yml:12,26` | `prometheus`, `grafana` | `prom/prometheus:v2.54.1`, `grafana/grafana:11.2.0` | pile de supervision séparée |

---

## 1.10 Chemin d'entrée d'un email entrant

[VÉRIFIÉ] Trace complète, du trigger IMAP au handler.

| Étape | Composant | Preuve |
|---|---|---|
| 1 | Trigger IMAP n8n lit les messages `UNSEEN` | `n8n/workflows/WF-INTAKE-EMAIL-V2.json`, nœud `IMAP Email Trigger` |
| 2 | Authentification : `POST {SCAMBUSTER_API_URL}/api/v1/auth/login` | idem, nœud `Retrieve Token` |
| 3 | `POST /api/v1/communication/ingest/raw` | idem, nœud `Ingest Email` |
| 4 | Contrôleur | `src/UI/Http/Communication/IngestController.php:24` (préfixe), `:77` (`#[Route('/raw', methods:['POST'])]`), `:25` (`#[IsGranted('conversation:write')]`), `:79` (`__invoke`) |
| 5 | Désérialisation DTO + validation | `IngestController.php:82` (`IngestRawRequestDto`), `:96` (validateur) |
| 6 | Limitation de débit par compte | `IngestController.php:107-129` — limiteur `ingest_per_account`, événement d'audit `RATE_LIMIT_EXCEEDED` |
| 7 | Handler | `src/Application/Communication/IngestHandler.php:23` (classe), `:40` (`ingest(IngestRawRequestDto): array`) |
| 7a | Parsing email | `IngestHandler.php:53` — `EmailParsingService::parseEmail()` |
| 7b | Résolution compte/canal/direction | `IngestHandler.php:62` (`EntityReferenceResolver::resolve`), repli en ligne `:70-85` |
| 7c | Déduplication par Message-ID | `IngestHandler.php:88` (`threadResolver->findExistingMessage`), retour `status: already_exists` `:90-94` |
| 7d | Résolution/création de fil | `IngestHandler.php:97` (`resolveConversation`), `:108` (`createNewConversation`), `:118` (`reopenIfNeeded`) |
| 7e | Post-traitement | `IngestHandler.php:248` (`postProcessor->processAfterIngest`), `:251` (`checkSenderRateLimits`) |
| 7f | Périmètre du post-traitement | `src/Application/Communication/IngestPostProcessor.php:22-26` (docblock) : « IOC extraction, scam classification, risk scoring, prompt injection detection, and rate limiting » |
| 8 | Relecture du risque | `GET /message/{msgId}/risk` → `src/UI/Http/Communication/GetMessageRiskController.php:49` |
| 9 | Porte de décision → génération de réponse | `WF-INTAKE-EMAIL-V2.json`, nœud `Decision Gate` → `WF-REPLY-GENERATE-V2.json` |
| 10 | Génération | `POST /reply/generate` → `src/UI/Http/Communication/GenerateReplyController.php:54` (route), `:73` → `src/Application/Communication/ReplyHandler.php:126` |
| 11 | Envoi | `POST /reply/{msgId}/send-email` → `src/UI/Http/Communication/SendEmailController.php:47` → `ReplyHandler.php:432` (`sendEmail`) ; composition `:406`, marquage envoyé `:416` |
| 12 | Branche IOC | `POST /message/{msgId}/extract-iocs` → `src/UI/Http/Communication/ExtractIocsController.php:97` ; puis PATCH `/api/v1/iocs/{id}/enrich` |

Point d'entrée interne adjacent : `GET /api/v1/internal/mail-account/active`
[VÉRIFIÉ] `src/UI/Http/Internal/MailAccountActiveController.php:12`.

---

## 1.11 Commandes console

68 classes de commande + 1 trait (`ResolvesPasswordInput.php`) sous
`backend-symfony/src/UI/Console` [VÉRIFIÉ] `ls backend-symfony/src/UI/Console`.
Descriptions verbatim des attributs `#[AsCommand]`.

### 1.11.1 Ingestion / classification / hygiène IOC
| Commande | Description | Preuve |
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

### 1.11.2 Clustering / acteur / campagne
| Commande | Description | Preuve |
|---|---|---|
| `app:clustering:backfill` | Backfill threat-actor clusters for all existing conversations | `ClusteringBackfillCommand.php:24` |
| `app:clustering:export-stix` | Export threat-actor clusters as STIX 2.1 bundles | `ClusterExportStixCommand.php:23` |
| `app:compute:cluster-sophistication` | Compute sophistication level for all clusters from conversation metrics | `ComputeClusterSophisticationCommand.php:24` |
| `app:verify:cluster-quality` | Verify cluster anchor IOC quality and detect potential artifacts | `VerifyClusterQualityCommand.php:26` |
| `app:actor:compute-psych-profiles` | Generate the per-cluster threat-actor psychological profile (one LLM call per cluster) | `ComputeActorPsychProfilesCommand.php:25` |
| `app:generate-actor-profiles` | Generate actor fingerprints for campaigns with sufficient conversation data | `GenerateActorProfilesCommand.php:17` |

### 1.11.3 TTP
| Commande | Description | Preuve |
|---|---|---|
| `scambuster:ttp:backfill` | Preview (default) or apply TTP extraction over historical inbound messages. | `TtpBackfillCommand.php:45` |
| `scambuster:ttp:audit-sample` | Export a random sample of TTP observations (WITH raw evidence) for internal manual precision audit. | `TtpAuditSampleCommand.php:42` |
| `scambuster:ttp:demo-seed` | Seed deterministic, plausible TTP observations for the demo dataset (no LLM). | `TtpDemoSeedCommand.php:51` |

### 1.11.4 Scambaiting / personas / bandit
| Commande | Description | Preuve |
|---|---|---|
| `app:evaluate:bandit-analysis` | Analyze epsilon-greedy persona selection convergence per scam type | `AnalyzeBanditCommand.php:18` |
| `app:bandit:daily-report` | Log daily convergence snapshot for each active scam type | `BanditDailyReportCommand.php:17` |
| `app:rewards:calculate` | Calculate rewards for all CLOSED conversations without a reward | `CalculateRewardsCommand.php:18` |
| `app:close-stale-conversations` | Close conversations based on per-scam-type lifecycle policies | `CloseStaleConversationsCommand.php:30` |
| `app:persona:compute-mirrors` | Generate the Cognitive Mirror cache (one LLM call per persona x scam type pair) | `ComputePersonaMirrorsCommand.php:28` |
| `app:link-scam-types-personas` | Link existing ScamTypes to their appropriate Personas | `LinkScamTypesPersonasCommand.php:15` |
| `app:fix:risk-scores` | Recalculate risk scores for all conversations using current formula | `RecalculateRiskScoresCommand.php:23` |
| `scambuster:strip-pending-signatures` | Strip queued outbound replies whose body still contains a signature block | `StripPendingSignaturesCommand.php:28` |

### 1.11.5 Évaluation / métriques
| Commande | Description | Preuve |
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

### 1.11.6 GUARD / canari de prompt / smoke
| Commande | Description | Preuve |
|---|---|---|
| `scambuster:guard:baseline` | Freeze a canary baseline (stable metrics + safety-violation rates) from a smoke summary JSON. | `GuardBaselineCommand.php:25` |
| `scambuster:guard:canary:work` | Process one pending prompt-canary validation job (run the candidate smoke, compare vs baseline, store the verdict). | `GuardCanaryWorkCommand.php:29` |
| `scambuster:guard:check` | Merge-gate: diff a candidate smoke summary against the frozen baseline; exit non-zero on regression. | `GuardCheckCommand.php:29` |
| `scambuster:smoke:cialdini-mirror` | Smoke harness — drive reply pipeline, capture Cialdini-mirror detection in strategic_suggestions. | `CialdiniMirrorSmokeCommand.php:37` |
| `scambuster:smoke:reply-objective` | Smoke harness — drive reply pipeline on .eml fixtures and dump per-test artifacts. | `ReplyObjectiveSmokeCommand.php:33` |
| `scambuster:prompt:diag` | Show which operator prompt overrides and settings are active (read-only). | `PromptDiagCommand.php:23` |

### 1.11.7 Sécurité / audit / conformité
| Commande | Description | Preuve |
|---|---|---|
| `app:security:check-secrets` | Refuse known-default or weak secrets in production (fail-fast guardrail) | `CheckSecretsCommand.php:27` |
| `app:audit:verify-chain` | Verify the HMAC chain of the audit_log table | `VerifyAuditChainCommand.php:28` |
| `app:purge:rgpd` | Purge conversations and messages according to RGPD rules. | `PurgeRgpdCommand.php:14` |
| `app:cleanup:weekly` | Soft-delete old closed conversations and purge stale LLM usage + prompt-canary-job records | `WeeklyCleanupCommand.php:17` |
| `app:llm:check-budget` | Check the LLM monthly budget threshold and emit an audit warning event if exceeded | `CheckLlmBudgetCommand.php:24` |

### 1.11.8 Utilisateurs / auth
`app:user:create` (`UserCreateCommand.php:32`), `app:user:list` (`UserListCommand.php:21`),
`app:user:promote` (`UserPromoteCommand.php:27`), `app:user:set-password`
(`UserSetPasswordCommand.php:28`), `login-hash:generate` (`GenerateLoginHashCommand.php:15`).

### 1.11.9 Comptes mail
| Commande | Description | Preuve |
|---|---|---|
| `app:mail-account:add` | Add a mail account with optional per-account SMTP DSN (encrypted at rest) | `MailAccountAddCommand.php:31` |
| `app:mail-account:list` | List all mail accounts (NEVER reveals SMTP DSN) | `MailAccountListCommand.php:18` |
| `app:mail-account:rotate-smtp` | Replace the encrypted SMTP DSN for an existing mail account | `MailAccountRotateSmtpCommand.php:23` |
| `app:mail-account:disable` | Soft-disable a mail account (sets is_active = false) | `MailAccountDisableCommand.php:22` |

### 1.11.10 Connecteurs d'export
`scambuster:misp:test` (`MispTestCommand.php:15`), `app:siem:export`
(`SiemExportCommand.php:22`), `app:siem:test` (`SiemTestCommand.php:23`).

### 1.11.11 Démo / préprod / debug
`scambuster:demo:load` (`LoadDemoDataCommand.php:32`), `preprod:generate-conversations`
(`PreprodGenerateConversationsCommand.php:22`), `preprod:copy-conversations`
(`PreprodCopyConversationsCommand.php:15`), `preprod:clear-conversations`
(`PreprodClearConversationsCommand.php:16`), `app:test-context`
(`TestContextCommand.php:16`), `app:test-conversation-context`
(`TestConversationContextCommand.php:16`), `TestReplyGenerateCommand.php` [INCONNU] nom/description.

---

## 1.12 Handlers asynchrones (Symfony Messenger)

**Aucun.** [VÉRIFIÉ]

- `grep -rn "AsMessageHandler\|MessageHandlerInterface\|MessageBusInterface" backend-symfony/src/ backend-symfony/config/` → 0 occurrence.
- `grep -rn "symfony/messenger\|symfony/scheduler" backend-symfony/composer.json` → 0 occurrence.
- `grep -rn -A20 "messenger" backend-symfony/config/packages/framework.yaml` → 0 occurrence.

`src/Application/Communication/MessageHandler.php` est un handler métier de message
email, pas un handler Messenger (namespace `App\Application\Communication`, aucun attribut
Messenger) [VÉRIFIÉ].

[DÉDUIT] L'asynchrone est réalisé par des conteneurs à boucle shell (§5), pas par un bus
de messages. Raisonnement : absence totale de Messenger, présence de `scheduler.sh` et
`canary-worker.sh` en `command:` de services compose.

---

## 1.13 Tâches planifiées

Aucun `crontab` ni composant Symfony Scheduler dans le dépôt [VÉRIFIÉ]
`grep -rln "crontab\|cron" --include="*.yml" --include="*.yaml" --include="*.conf" --include="Dockerfile*" .`
→ seul `.github/workflows/guard-nightly.yml` correspond.

### 1.13.1 Conteneur `scheduler` — boucle shell
`infra/docker/backend/scheduler.sh`, service `docker-compose.yml:139`
(`command: ["sh","/opt/scheduler.sh"]` `:143`) ; équivalent prod `docker-compose.prod.yml:105`.
Interrupteur `SCHEDULER_ENABLED=false` (`scheduler.sh:9`).

| Cadence | Commande | Preuve |
|---|---|---|
| Toutes les 6 h | `app:close-stale-conversations` | `scheduler.sh:47` |
| 6 h | `app:rewards:calculate` | `scheduler.sh:52` |
| 6 h | `app:detect-prompt-injection` | `scheduler.sh:57` |
| 6 h | `app:generate-embeddings --limit=500` | `scheduler.sh:62` |
| 6 h | `app:ioc:compute-context --with-llm --budget-usd=1.00 --limit=200` | `scheduler.sh:67` |
| Quotidien ≥06:00 UTC | `app:bandit:daily-report` | `scheduler.sh:74` |
| Quotidien ≥06:00 UTC | `app:generate-actor-profiles` | `scheduler.sh:78` |
| Quotidien ≥06:00 UTC | `app:actor:compute-psych-profiles --budget-usd=1.00` | `scheduler.sh:84` |
| Quotidien ≥02:00 UTC | `app:audit:verify-chain` | `scheduler.sh:94` |
| Quotidien ≥02:00 UTC | `pg_dump` → `/backups/scambuster_<date>.sql.gz`, rétention `-mtime +7` | `scheduler.sh:101-124` |
| Hebdomadaire dimanche ≥04:00 UTC | `app:cleanup:weekly` | `scheduler.sh:128` |
| Toutes les 30 min | `app:clustering:backfill` | `scheduler.sh:139` |
| Toutes les 30 min | `app:llm:check-budget` | `scheduler.sh:145` |

### 1.13.2 Conteneur `canary-worker`
`infra/docker/backend/canary-worker.sh`, service `docker-compose.yml:166`,
`profiles: ["canary"]` (`:168`). Draine `scambuster:guard:canary:work` toutes les
`CANARY_WORKER_POLL_SECONDS` (défaut 60, `canary-worker.sh:32-37`) ; interrupteur
`CANARY_WORKER_ENABLED=false` (`canary-worker.sh:10`) [VÉRIFIÉ].

### 1.13.3 Planification CI
`.github/workflows/guard-nightly.yml:18-19` — `cron: '0 5 * * 0'` (dimanche 05:00 UTC)
+ `workflow_dispatch` [VÉRIFIÉ].

### 1.13.4 Planification n8n
Aucun nœud `scheduleTrigger`. Le seul déclencheur périodique est le nœud IMAP de
`WF-INTAKE-EMAIL-V2.json` (`forceReconnect: 2`, pas de clé `pollTimes`).
`WF-REPLY-SEND-v1.json` utilise un nœud `wait` piloté par un nœud code
`Calculate Human Delay` [VÉRIFIÉ].

---

## 2. Points de sortie réseau du code

### 2.1 Backend — sorties HTTP

| # | Destination (hôte — en dur / variable) | Protocole | Chemin déclencheur | Déclencheur |
|---|---|---|---|---|
| E1 | `%llm.api_url%` + `/chat/completions` — **`LLM_API_URL`**, défaut `https://api.openai.com/v1` (`.env.dist:307`), câblé `config/packages/llm.yaml:5,45` | HTTPS POST | `src/Infrastructure/LLM/Provider/OpenAIClient.php:48` (const `:21`) | Tout appelant de `LLMClientInterface::chat()` si `LLM_PROVIDER=openai` |
| E2 | `https://api.anthropic.com/v1/messages` — **en dur** | HTTPS POST | `src/Infrastructure/LLM/Provider/AnthropicClient.php:165` (const), `:217` (appel) | idem si `LLM_PROVIDER=anthropic` ; clé `ANTHROPIC_API_KEY` (`llm.yaml:55`) |
| E3 | `$baseUrl` + `/api/chat` — **`OLLAMA_BASE_URL`**, défaut `http://localhost:11434` (`llm.yaml:7,64`) | HTTP POST | `src/Infrastructure/LLM/Provider/OllamaClient.php:50` (const `:23`) | idem si `LLM_PROVIDER=ollama` |
| — | *(aucune socket)* `MockLLMClient` | — | `src/Infrastructure/LLM/Provider/MockLLMClient.php:18` | `LLM_PROVIDER=mock` |
| E4 | `https://api.openai.com/v1/embeddings` — **en dur** | HTTPS POST | `src/Application/LLM/EmbeddingService.php:20` (const `API_URL`), `:68` (appel) | Commande `app:generate-embeddings` |
| E5 | `https://api.openai.com/v1/chat/completions` — **en dur** (service historique `OpenAIService`) | HTTPS POST | `src/Infrastructure/LLM/OpenAIService.php:51` | `ConversationGenerator` (`src/Infrastructure/Preprod/ConversationGenerator.php:339`) ; alias DI `config/services.yaml:525-532` |
| E6 | `http://scambuster-backend-preprod:8080/api/v1/auth/login` — **en dur** | HTTP POST | `src/Infrastructure/Preprod/ConversationGenerator.php:584` | Génération préprod |
| E7 | `http://scambuster-backend-preprod:8080/.../extract-iocs` — **en dur** | HTTP POST | `src/Infrastructure/Preprod/ConversationGenerator.php:620-622` | Génération préprod |
| E8 | Endpoint token OIDC (issu du document de découverte) | HTTPS POST | `src/Application/Auth/Oidc/OidcService.php:107` | Callback OIDC |
| E9 | Endpoint userinfo OIDC | HTTPS GET | `src/Application/Auth/Oidc/OidcService.php:144` | Callback OIDC |
| E10 | Document de découverte OIDC — **`OIDC_DISCOVERY_URL`** (`config/services.yaml:248`, `.env.dist:78`) | HTTPS GET | `src/Application/Auth/Oidc/OidcService.php:231` | Démarrage/callback OIDC (`OIDC_ENABLED`, `.env.dist:75`) |
| E11 | MISP `{MISP_URL}/servers/getVersion` — `$_ENV['MISP_URL']` | HTTPS GET | `src/UI/Console/MispTestCommand.php:33`, `:54` | Commande `scambuster:misp:test`. Vérification TLS pilotée par **`MISP_VERIFY_SSL`** (`:59`) |

### 2.2 Backend — sorties non-HTTP

| # | Destination | Protocole | Chemin | Déclencheur |
|---|---|---|---|---|
| E12 | Serveur SMTP de **`MAILER_DSN`** (`config/packages/mailer.yaml:3` ; `.env.dist:232` défaut `null://null`) | SMTP/SMTPS | `src/Application/Communication/ReplyCompositionService.php:311` (`sendEmail`), résolution `:352-357` | `POST /reply/{msgId}/send-email`, appelé par n8n `WF-REPLY-SEND-v1.json:53` |
| E13 | SMTP par compte, DSN chiffré en base (`smtp_dsn_encrypted`) | SMTP/SMTPS | `src/Application/Communication/Smtp/SmtpTransportResolver.php:59` (déchiffrement), `:68` (`fromDsn`), `:76` (`new Mailer`) | Même chemin d'envoi |
| E14 | Collecteur syslog — **`SIEM_ENDPOINT`** (`udp://` ou `tcp://`), activé par **`SIEM_PROVIDER=syslog`** (`.env.dist:421`, exemples `:428-440`) | UDP/TCP brut | `src/Infrastructure/Siem/Adapter/SyslogSiemExporter.php:122` (`fsockopen`), `:134` (`fwrite`), sonde `:67` | `app:siem:export`, `app:siem:test` |
| E15 | PostgreSQL — **`DATABASE_URL`** (`.env.dist:53`) | TCP/pgsql | `config/packages/doctrine.yaml` | Toute persistance |
| E16 | Redis — **`REDIS_URL`** (`.env.dist:59`) | TCP | `config/packages/cache.yaml` | Cache, limiteurs de débit, cache du kill switch |

**Absences constatées** [VÉRIFIÉ] par recherche négative : aucun `curl_*`, `GuzzleHttp`,
`imap_open`, `Webklex`, `dns_get_record`, `gethostbyname`, `checkdnsrr`, `whois`,
VirusTotal, AbuseIPDB, urlscan, Shodan, client TAXII, Sentry, ni pushgateway Prometheus
dans les sources PHP. `App\Application\Taxii\TaxiiService` est un **fournisseur de flux
côté serveur** (`/api/v1/taxii2`), pas un client : aucune sortie. `IocFeedExporter` ne
contient aucun client HTTP. L'intégration Vault est documentée comme retirée
(`.env.dist:102-108`, `docker-compose.yml:198-204`).

### 2.3 n8n — nœuds sortants

| # | Destination | Protocole | Nœud (fichier:ligne) | Déclencheur |
|---|---|---|---|---|
| E17 | Boîte IMAP — identifiant n8n `ScamBuster IMAP` ; hôte/port via **`HONEYPOT_IMAP_HOST/PORT/SECURE`** (`.env.dist:190-194`) | IMAP/IMAPS | `WF-INTAKE-EMAIL-V2.json:15`, réf. identifiant `:22-26` | Point d'entrée du système |
| E18 | `{{SCAMBUSTER_API_URL}}/api/v1/auth/login` — **`SCAMBUSTER_API_URL`** (`.env.dist:183`, défaut `http://backend-dev:8080`) | HTTP POST | `WF-INTAKE-EMAIL-V2.json:45` ; `WF-EXTRACT-AND-ENRICH-IOC.json:18` ; `WF-REPLY-GENERATE-V2.json:20` ; `WF-REPLY-SEND-v1.json:147` | Chaque exécution |
| E19 | `.../communication/ingest/raw` | HTTP POST | `WF-INTAKE-EMAIL-V2.json:91` | Après lecture IMAP |
| E20 | `.../message/{id}/risk` | HTTP | `WF-INTAKE-EMAIL-V2.json:127` | Après ingestion |
| E21 | `.../message/{msg_id}/extract-iocs` | HTTP POST | `WF-EXTRACT-AND-ENRICH-IOC.json:51` | Sous-workflow |
| E22 | `.../iocs/{obs_id}/enrich` | HTTP | `WF-EXTRACT-AND-ENRICH-IOC.json:287` | Après fusion d'enrichissement |
| **E23** | **urlscan.io** — nœud `n8n-nodes-base.urlScanIo`, identifiant `urlScanIoApi` | HTTPS | `WF-EXTRACT-AND-ENRICH-IOC.json:119` (scan ; URL cible `:114` = `{{ $json.value }}`), `:208` (rapport) | Enrichissement d'IOC de type URL/domaine |
| **E24** | **`https://www.virustotal.com/api/v3/urls`** — **en dur** | HTTPS POST | `WF-EXTRACT-AND-ENRICH-IOC.json:136`, identifiant `:160` | Enrichissement d'IOC |
| E25 | `https://www.virustotal.com/api/v3/analyses/{id}` — **en dur** | HTTPS GET | `WF-EXTRACT-AND-ENRICH-IOC.json:224`, identifiant `:248` | Sondage du rapport |
| E26 | `.../conversation/{conv_id}/context`, `/reply/generate`, `/reply/draft` | HTTP | `WF-REPLY-GENERATE-V2.json:46`, `:70`, `:101` | Sous-workflow de génération |
| E27 | `.../reply/{msg_id}`, `/compose`, `/send-email`, `/sent` | HTTP | `WF-REPLY-SEND-v1.json:6`, `:29`, `:53`, `:90` | Sous-workflow d'envoi (cause de E12/E13) |

**Fait notable** [VÉRIFIÉ] `WF-EXTRACT-AND-ENRICH-IOC.json:114` : la valeur soumise à
urlscan.io et VirusTotal est **l'IOC fourni par l'adversaire lui-même**.

### 2.4 Scripts shell

| Destination | Protocole | Fichier:ligne |
|---|---|---|
| `${LLM_API_URL:-https://api.openai.com/v1}/chat/completions` | HTTPS | `scripts/test-llm/test-openai.sh:8,23,51,76` |
| `https://api.anthropic.com/v1/messages` (en dur) | HTTPS | `scripts/test-llm/test-anthropic.sh:22,51,77` |
| `${OLLAMA_BASE_URL:-http://localhost:11434}` `/api/tags`, `/api/chat` | HTTP | `scripts/test-llm/test-ollama.sh:8,17,58,85,103` ; `test-all.sh:51-52` |

### 2.5 Ports publiés (compose)

| Fichier:ligne | Service | Port publié |
|---|---|---|
| `docker-compose.yml:29-30` | `postgres-preprod` | `5433:5432` (toutes interfaces) |
| `docker-compose.yml:67-68` | `backend-dev` | `8081:8080` |
| `docker-compose.yml:128-129` | `backend-preprod` | `8082:8080` |
| `docker-compose.yml:194-195` | `frontend` | `3002:5173` |
| `docker-compose.yml:245-246` | `n8n` | `${N8N_HTTP_PORT:-5678}:${N8N_PORT:-5678}` |
| `docker-compose.yml:93,111,142,170` | `backend-test`, `backend-e2e`, `scheduler`, `canary-worker` | `ports: []` |
| `docker-compose.prod.yml:33-34` | `app` | `${APP_BIND_HOST:-127.0.0.1}:${APP_PORT:-8080}:8080` (loopback par défaut) |
| `docker-compose.prod.yml:93-94` | `n8n` | `${N8N_BIND_HOST:-127.0.0.1}:${N8N_HTTP_PORT:-5678}:5678` (loopback par défaut) |
| `docker-compose.prod.yml:42,58` | `postgres`, `redis` | aucune strophe `ports:` |
| `infra/monitoring/docker-compose.yml:23-24,39-40` | `prometheus` (9090), `grafana` (3003:3000) | publiés |

Prometheus fonctionne en **pull**, cible `backend:8080`
(`infra/monitoring/prometheus/prometheus.yml:23`) ; le backend expose `/api/metrics`
(`src/UI/Http/Monitoring/MetricsController.php:27`). Pas de pushgateway [VÉRIFIÉ].

### 2.6 Filtrage de sortie

**Aucune liste blanche de sortie ni configuration de proxy sortant n'existe dans le code
ni dans l'infra** [VÉRIFIÉ]. `framework.http_client` est activé sans `proxy`, `no_proxy`
ni client à portée restreinte : `config/packages/framework.yaml:17-18`. Les seules
occurrences de `no_proxy` sont dans le stub de référence auto-généré
(`config/reference.php:484,537`), non appliqué.
Réseaux Docker : un seul bridge `scambuster` (`docker-compose.yml:261-262`) ; aucun
`internal: true`, aucune restriction `network_mode`.
Les seules listes blanches présentes sont **entrantes / applicatives** : origine CORS
**`CORS_ALLOW_ORIGIN`** (`config/packages/nelmio_cors.yaml:3`, `.env.dist:140`) et liste
sûre de destinataires de réponse **`SCAMBUSTER_SAFE_DOMAINS`** (`.env.dist:251`,
**défaut `*`**), appliquée à `src/Application/Communication/ReplyCompositionService.php:140,145`.

---

## 3. Appels LLM

### 3.1 Couche d'abstraction fournisseur

| Élément | Fichier:ligne |
|---|---|
| Port `App\Application\LLM\Port\LLMClientInterface::chat()` | `src/Application/LLM/Port/LLMClientInterface.php:13,27` |
| Adaptateur OpenAI (URL de base configurable) | `src/Infrastructure/LLM/Provider/OpenAIClient.php:19` |
| Adaptateur Anthropic (**URL en dur** `:165`) | `src/Infrastructure/LLM/Provider/AnthropicClient.php:163` |
| Adaptateur Ollama (`OLLAMA_BASE_URL`) | `src/Infrastructure/LLM/Provider/OllamaClient.php:21` |
| Adaptateur Mock (sans réseau) | `src/Infrastructure/LLM/Provider/MockLLMClient.php:16` |
| Bascule de fournisseur (lit `$_ENV['LLM_PROVIDER']`) | `src/Infrastructure/LLM/LLMProviderCompilerPass.php:25-29,33,49-52` ; alias par défaut → OpenAI `config/packages/llm.yaml:92` |
| **Seconde interface historique** `LLMServiceInterface::complete()` | `src/Infrastructure/LLM/LLMServiceInterface.php:10,20` ; impl. `OpenAIService.php:34` ; alias `config/services.yaml:525-526` ; câblée uniquement au générateur préprod (`config/services.yaml:542`) |

[DÉDUIT] Il existe **deux** abstractions LLM concurrentes : le port hexagonal
`LLMClientInterface` (11 appelants applicatifs) et l'interface historique
`LLMServiceInterface` liée en dur à `https://api.openai.com`. Raisonnement : les deux
interfaces coexistent dans `config/services.yaml` ; `OpenAIService.php:51` ignore
`LLM_API_URL`. Le service embeddings n'utilise **ni l'une ni l'autre** : il appelle
`HttpClientInterface` directement (`EmbeddingService.php:8,23`).

### 3.2 Tous les appels LLM distincts

| Cas d'usage | Appel (fichier:ligne) | Modèle | Emplacement du prompt | Endpoint paramétrable | Abstraction |
|---|---|---|---|---|---|
| Génération de réponse (boucle de reprise) | `src/Application/LLM/RetryCoordinator.php:707` ; options `:700-703`, `purpose=reply_generation` | `%llm.model%` ← **`LLM_MODEL`** (`.env.dist:306`, défaut `gpt-4o-mini`), injecté `llm.yaml:172`, accesseur `:863-866` ; temp 0.6, max_tokens 400 | `PromptBuilder` ; clé surchargeable `persona_style_rules` (`src/Application/LLM/PromptBuilder.php:105`) | Oui via `LLM_API_URL` | `LLMClientInterface` |
| Validation de réponse (juge LLM) | `src/Application/LLM/ReplyValidator.php:109` ; options `:96-105` | **en dur** `gpt-4o-mini` (`:103`), temp 0.4, max_tokens 500 | fourni par `PromptBuilder` | niveau fournisseur | `LLMClientInterface` |
| Détecteur de fuite opérationnelle (2ᵉ juge) | `src/Application/LLM/OperationalLeakageDetector.php:53` ; options `:54-57` | **en dur** const `MODEL='gpt-4o-mini'` (`:28`) | en dur dans le PHP | niveau fournisseur | `LLMClientInterface` |
| Garde d'instigation de paiement (2 appels) | `src/Application/LLM/PaymentInstigationGuard.php:125`, `:227` ; options `:126-129`, `:228-231` | **en dur** const `MODEL='gpt-4o-mini'` (`:50`) | en dur dans le PHP | niveau fournisseur | `LLMClientInterface` |
| Analyse de conversation / anti-répétition (Director) | `src/Application/LLM/ConversationAnalyzer.php:111` ; options `:116-120` | **en dur** const `ANALYZER_MODEL='gpt-4o'` (`:27`), temp 0.3 (`:28`) | défauts en ligne + clés surchargeables `conversation_director_strategy` (`:246`), `conversation_director_tone` (`:251`) | niveau fournisseur | `LLMClientInterface` |
| Classification d'arnaque | `src/Application/LLM/ScamClassifier.php:57` ; options `:58-60` | pas de clé `model` → défaut fournisseur | heredocs en dur `:184-265` (système), `:269-275` (utilisateur) | niveau fournisseur | `LLMClientInterface` |
| Extraction d'IOC | `src/Application/Communication/IocExtractor.php:105` ; options `:106-108` | pas de clé `model` → défaut fournisseur | heredocs en dur `:193`, `:282` | niveau fournisseur | `LLMClientInterface` |
| Extraction de TTP | `src/Application/LLM/TtpExtractor.php:181` ; options `:182-184` | défaut fournisseur ; `max_tokens` = const `MAX_TOKENS` | `PromptProvider::resolve()` `:164`, clé `ttp_extraction` (`PromptCatalog.php:295`) | niveau fournisseur | `LLMClientInterface` |
| Enrichissement contextuel d'IOC | `src/Application/LLM/ContextualEnricher.php:61` ; options `:50-52` | défaut fournisseur | `PromptProvider::resolve()` `:144`, clé `contextual_enrichment` (`PromptCatalog.php:38`) | niveau fournisseur | `LLMClientInterface` |
| Résumé d'historique de conversation | `src/Application/Communication/ConversationHistoryService.php:233` ; options `:227-230` | **en dur** `gpt-4o-mini` (`:229`) | en dur dans le PHP | niveau fournisseur | `LLMClientInterface` |
| Détection d'injection de prompt (analyseur LLM) | `src/Application/Communication/PromptInjectionLlmAnalyzer.php:83` ; options `:89-92` | **`PROMPT_INJECTION_MODEL`** (`.env.dist:296`, défaut `gpt-4o-mini`) + **`PROMPT_INJECTION_TEMPERATURE`** (`:298`, défaut 0.2), câblé `llm.yaml:254-255` ; drapeau **`PROMPT_INJECTION_ENABLED`** (`.env.dist:294`, `llm.yaml:262`) | const `SYSTEM_PROMPT` en dur `:21` | niveau fournisseur | `LLMClientInterface` |
| Audit qualité de conversation (juge) | `src/Application/Audit/ConversationQualityAuditor.php:85` ; options `:77-80` | **en dur** `gpt-4o` (`:77`), temp 0.2, max_tokens 1000 | const `SYSTEM_PROMPT` `:23-33` ; heredoc `:196-238` | niveau fournisseur | `LLMClientInterface` |
| Juge de récompense (clôture de conversation) | `src/Application/Scambaiting/RewardJudge.php:89` ; options `:94` | défaut fournisseur | `PromptProvider::resolve('reward_judge')` `:78`, défaut `PromptCatalog.php:275` | niveau fournisseur | `LLMClientInterface` |
| Génération de miroir de persona | `src/Application/Scambaiting/PersonaMirrorGenerator.php:66` ; options `:70-73` | const `MODEL` (`:274`) ; temp 0.3, max_tokens 350 ; `PROMPT_VERSION='v1'` `:39` | const `SYSTEM_PROMPT` `:40` ; heredoc `:171-204` | niveau fournisseur | `LLMClientInterface` |
| Profilage psychologique d'acteur | `src/Application/ThreatActor/ThreatActorPsychProfileGenerator.php:65` ; options `:69-72` | const `MODEL` (`:69`) ; temp 0.3, max_tokens 500 ; `PROMPT_VERSION='v1'` `:30` | const `SYSTEM_PROMPT` `:34` ; heredoc `:195-224` | niveau fournisseur | `LLMClientInterface` |
| Profilage de campagne | `src/Application/Campaign/CampaignProfiler.php:143` ; options `:144-147` | défaut fournisseur ; temp 0.3, max_tokens 800 | `Campaign\PromptBuilder::buildCampaignProfilerPrompts()` (`:136`) | niveau fournisseur | `LLMClientInterface` |
| Compilation de règles de campagne (DSL) | `src/Application/Campaign/RuleCompiler.php:121` ; options `:122-125` | défaut fournisseur ; temp 0.2, max_tokens 1000 | `Campaign\PromptBuilder::buildRuleCompilerPrompts()` (`:115`) | niveau fournisseur | `LLMClientInterface` |
| Éval — test prompt V2 | `src/UI/Console/EvalTestPromptV2Command.php:122` ; options `:126-129` | option CLI `--model`, défaut `gpt-4o-mini` (`:61`, `:72`) | const `SYSTEM_PROMPT` `:46` | niveau fournisseur | `LLMClientInterface` |
| Éval — exécution du juge | `src/UI/Console/EvalRunJudgeCommand.php:116` ; options `:120-123` | option CLI `--model`, défaut `gpt-4o` (`:57`, `:67`), temp 0.0 | dans la commande | niveau fournisseur | `LLMClientInterface` |
| Générateur de conversations préprod | `src/Infrastructure/Preprod/ConversationGenerator.php:339` ; temp 0.8, max_tokens 3000 (`:340-342`) | **`LLM_MODEL`** via `config/services.yaml:532` | heredoc en dur `:302-338` | **Endpoint en dur** `https://api.openai.com/v1/chat/completions` (`OpenAIService.php:51`) | `LLMServiceInterface` (historique) |
| **Embeddings** | `src/Application/LLM/EmbeddingService.php:68` | **en dur** const `MODEL='text-embedding-3-small'` (`:18`), `DIMENSIONS=1536` (`:19`) | s.o. | **Endpoint en dur** `https://api.openai.com/v1/embeddings` (`:20`) | **aucune** — `HttpClientInterface` direct (`:8,:23`) |

**Synthèse de la paramétrabilité du modèle** [DÉDUIT] : sur 21 sites d'appel, **7 codent
le nom du modèle en dur** (`ReplyValidator`, `OperationalLeakageDetector`,
`PaymentInstigationGuard`, `ConversationAnalyzer`, `ConversationHistoryService`,
`ConversationQualityAuditor`, `EmbeddingService`), 2 via option CLI, 1 via variable
dédiée, le reste hérite de `LLM_MODEL`. Raisonnement : lecture directe des clés `model`
dans chaque tableau d'options cité ci-dessus. Les modèles en dur sont tous des
identifiants **OpenAI** (`gpt-4o`, `gpt-4o-mini`, `text-embedding-3-small`).

**Les embeddings sont calculés via API distante, pas localement** [VÉRIFIÉ]
`EmbeddingService.php:20,68`.

### 3.3 Surface de déclenchement

| Cas d'usage | Déclencheur |
|---|---|
| Génération / validation / fuite / garde paiement / director | `POST /api/v1/communication/reply/generate` (`GenerateReplyController.php:54`), via n8n `WF-REPLY-GENERATE-V2.json:70` |
| Extraction d'IOC | `POST /message/{msgId}/extract-iocs` (`ExtractIocsController.php:97`), n8n `WF-EXTRACT-AND-ENRICH-IOC.json:51` |
| Extraction de TTP | `POST /message/{msgId}/extract-ttps` (`ExtractTtpsController.php:100`) ; `scambuster:ttp:backfill` (`TtpBackfillCommand.php:54`) |
| Classification | `POST /conversation/{convId}/classify` (`ClassifyConversationController.php:68`), `/auto-classify` (`AutoClassifyConversationController.php:83`) ; `scambuster:classify:backfill-unknown` ; **et post-traitement d'ingestion** (`IngestPostProcessor`, réf. `IngestHandler.php:21`) via `POST /ingest/raw` |
| Analyse d'injection de prompt | post-traitement d'ingestion ; `app:detect-prompt-injection` |
| Profilage / compilation de règles de campagne | `POST /campaign/{id}/profile` (`ProfileCampaignController.php:16`), `POST /campaign/{id}/rules/compile` (`CompileCampaignRulesController.php:16`) |
| Audit qualité | `app:audit:conversation-quality` |
| Miroir de persona | `app:persona:compute-mirrors` |
| Profilage psychologique | `app:actor:compute-psych-profiles` |
| Enrichissement contextuel | `app:ioc:compute-context` ; `POST /api/v1/iocs/enriched` (`IngestEnrichedIocController.php:150`) |
| Juge de récompense | `app:rewards:calculate` |
| Canari GUARD (validation de prompt sur LLM réel) | `POST /api/v1/prompt-overrides/{key}/canary` (`RequestPromptCanaryController.php:25`) → drainé par `scambuster:guard:canary:work` dans le conteneur `canary-worker` (`docker-compose.yml:166-185`) |
| Exécutions planifiées | boucle du service `scheduler` (`docker-compose.yml:139-155`, `infra/docker/backend/scheduler.sh`) |

### 3.4 Mécanisme de surcharge de prompt

| Aspect | Fichier:ligne |
|---|---|
| Ordre de résolution : **surcharge BDD → fichier disque → défaut embarqué** | `src/Application/LLM/Prompt/PromptProvider.php:55-58` (tableau de candidats), `:60-78` (boucle) |
| Un candidat auquel manque un `{{PLACEHOLDER}}` requis est ignoré au profit du suivant | `PromptProvider.php:65-75`, `:126-135` |
| Substitution des placeholders | `PromptProvider.php:42` |
| Source BDD (charge toutes les surcharges actives une fois par instance de service) | `src/Infrastructure/Prompt/CachedDbPromptOverrideSource.php:36-38`, `:44-65` ; comportement documenté `:16-19` |
| **Erreurs BDD avalées → traitées comme « aucune surcharge »** | `CachedDbPromptOverrideSource.php:58-64` ; aussi `PromptProvider.php:91-97` |
| Tête de chaîne = candidat éphémère (canari) **devant** la source BDD | `config/packages/llm.yaml:202-206` ; `src/Infrastructure/Prompt/CompositePromptOverrideSource.php` ; porteur `src/Application/LLM/Prompt/EphemeralPromptOverride.php` |
| Répertoire de surcharge fichier | `%kernel.project_dir%/config/scambuster/prompts` (`llm.yaml:212`) ; fichiers `<clé>.txt`, clé conforme à `^[a-z0-9_]+$` (`PromptProvider.php:106-119`) |
| Entité / dépôt | `src/Domain/Prompt/PromptOverride.php`, `PromptOverrideRepositoryInterface.php`, `src/Infrastructure/Doctrine/Repository/DoctrinePromptOverrideRepository.php` |
| Catalogue des clés surchargeables + défauts embarqués | `src/Application/LLM/Prompt/PromptCatalog.php:37` — `contextual_enrichment` (`:38`), `persona_style_rules` (`:222`), `conversation_director_strategy` (`:241`), `conversation_director_tone` (`:257`), `reward_judge` (`:275`), `ttp_extraction` (`:295`) |
| API d'administration | `GET /api/v1/prompt-overrides` (`ListPromptOverridesController.php:13`), `GET\|PUT\|DELETE /{key}`, endpoints canari (`RequestPromptCanaryController.php:25`, `GetPromptCanaryController.php:21`, `GetLatestPromptCanaryController.php:19`) |
| CLI de diagnostic | `scambuster:prompt:diag` — `PromptDiagCommand.php:77,100` |

[VÉRIFIÉ] **6 clés de prompt sur 21 sites d'appel sont surchargeables**. Les 15 autres
prompts sont en dur dans le PHP (const ou heredoc), donc modifiables uniquement par
modification de code.

### 3.5 Kill switch, plafond budgétaire, limitation de débit

| Contrôle | Fichier:ligne | Portée exacte |
|---|---|---|
| **Kill switch** — deux couches : clé de cache `llm.killswitch.active` puis repli sur variable d'environnement | `src/Application/Communication/ReplyCadenceService.php:30` (clé), `:55-77` (`isKillSwitchActive`), lecture env `:74` (**`SCAMBUSTER_KILL_SWITCH`**, `.env.dist:287`) | Bloque la génération de réponse : `ReplyHandler.php:137-139` (`RuntimeException`). Drapeau de pré-envoi `kill_switch_off` `ReplyCompositionService.php:140`, combiné `:145`, appliqué `:373`. **Ne bloque pas** la classification, l'extraction d'IOC/TTP, l'enrichissement, le profilage ni les embeddings |
| Bascule API + événement d'audit | `src/UI/Http/Admin/ToggleLlmKillSwitchController.php:18`, `:72-84` (`AuditEventType::KILL_SWITCH_TOGGLED`) ; lecture `GetLlmKillSwitchStateController.php:14,43` | — |
| Métrique | `src/UI/Http/Monitoring/MetricsController.php:97-100` (jauge `scambuster_kill_switch`) | — |
| **Plafond budgétaire mensuel** | paramètre `llm.max_cost_usd_month` ← **`LLM_MAX_COST_USD_MONTH`**, défaut `50.0` (`llm.yaml:11-12`, `.env.dist:336`) ; `src/Application/Monitoring/LlmCostHandler.php:19`, `:137-149`, `:157-160`, seuil `:125-129` | Porte à `ReplyHandler.php:146-158` : si dépassement et mode `enforce` → `LlmBudgetExceededException` (`:147-151`), sinon simple avertissement (`:154-158`). **Ne gouverne que la génération de réponse** |
| Mode d'application | `llm.budget_enforcement_mode` ← **`LLM_BUDGET_ENFORCEMENT_MODE`**, **défaut `warning`** (`llm.yaml:16-17`, `.env.dist:343`), câblé `config/services.yaml:467` | `warning` \| `enforce` |
| Exception budgétaire → HTTP 503 | `GenerateReplyController.php:106` | — |
| Notification de seuil + CLI | `src/Application/Monitoring/BudgetThresholdNotifier.php` (`config/services.yaml:471-476`) ; `app:llm:check-budget` | — |
| Comptabilisation d'usage | événement `LlmCallCompletedEvent` émis par chaque fournisseur (`OpenAIClient.php:88`, `AnthropicClient.php:259`, équivalent Ollama) ; écouteur `src/Infrastructure/EventListener/LlmUsageListener.php` (`llm.yaml:85`) ; coût `src/Application/LLM/CostEstimator.php` ; table `llm_usage` | — |
| **Limitation de débit (Redis)** | `config/packages/rate_limiter.yaml` : `replies_per_conversation` 20/24 h (`:28-31`), `llm_calls_per_hour` 200/1 h (`:33-36`), `active_conversations_per_day` 50/24 h (`:38-41`), `emails_per_sender_per_day` 10/24 h (`:46-49`), `ingest_per_account` 100/1 h (`:57-60`), `login_ip` (`:7-10`), `login_email` (`:15-18`), `api_global` (`:20-23`) | Vérifiée à `ReplyCadenceService.php:116-…` (`checkRateLimits`), appelée depuis `ReplyHandler.php:195` |
| Cadence de réponse | `ReplyCadenceService.php:27` (`MIN_HOURS_BETWEEN_REPLIES = 6`), `:82-109` | Espacement minimal entre réponses sortantes |

### 3.6 Emplacement des paramètres modèle / température / max_tokens

| Couche | Emplacement |
|---|---|
| Variables d'environnement | `.env.dist:305-308` (`LLM_PROVIDER`, `LLM_MODEL`, `LLM_API_URL`, `LLM_API_KEY`), `:296-298`, `:317-332`, `:336`, `:343` |
| Paramètres de conteneur | `config/packages/llm.yaml:3-6`, `:7`, `:8`, `:11-17`, `:20-32` |
| Défauts par adaptateur | `OpenAIClient.php:22-23` (0.6 / 400) ; `AnthropicClient.php:167-168` (0.6 / 1024) ; `OllamaClient.php:24` (0.6) ; `OpenAIService.php:47-48` (0.7 / 1024) |
| Surcharges par site d'appel | voir colonnes `options` du tableau §3.2 |
| Câblage du modèle dans le pipeline de réponse | `llm.yaml:172` (`ReplyOrchestrator.$model: '%llm.model%'`) |

## 4. Contrôles déterministes existants (non-LLM)

> Cette section conditionne la règle R2 : aucun composant ne peut être proposé en phase 3
> sans avoir été confronté à cet inventaire. La colonne « Ce qui n'est pas couvert »
> est établie **par lecture du code seul** (taille de liste, langue des motifs, portée
> déclarée), jamais par supposition.

### 4.1 Garde-fous sur la réponse sortante (post-LLM, pré-envoi)

| # | Nom | Fichier:ligne | Position dans le flux | Portée exacte | Non couvert (constat de lecture) |
|---|---|---|---|---|---|
| D1 | `PolicyGuard::validate()` — porte maîtresse post-LLM | `src/Application/LLM/PolicyGuard.php:223` | Étage 2 de `RetryCoordinator::execute()` (`RetryCoordinator.php:152`), après génération et retrait de signature, avant garde paiement / détecteur de fuite / validateur | Retourne `{approved, flags}` ; `approved = ($flags === [])` (`:380`) | Docblock du code : « NOT a proof that no harmful output can ever pass (a novel paraphrase carries no enumerated substring) » (`:15-16`) |
| D2 | Bande de nombre de mots | `PolicyGuard.php:238-260` | post-LLM | `WordCounter::count()` contre min/max de `PolicyGuardConfig` ; drapeaux `too_short:{n}_words_min_{min}`, `too_long:{n}_words` | Tokenisation par espaces uniquement |
| D3 | Plafond de liens | `PolicyGuard.php:263-278` | post-LLM | Regex `#https?://[^\s<>"{}\|\\^`\[\]]+#i` ; `$maxLinks = 1` (`:210`) | Ne compte que les formes `http(s)://` ; `www.` nu et liens défangués non comptés |
| D4 | `FORBIDDEN_PATTERNS` (auto-divulgation IA / honeypot) | `PolicyGuard.php:47-54` | post-LLM | **6 regex** : `/\bhoneypot\b/i`, `/\bscambuster\b/i`, `/\bI am (?:a \|an )?(?:bot\|automated\|AI)\b/i`, `/\bautomated system\b/i`, `/\bartificial intelligence\b/i`, `/\bleurre\b/i` | 6 entrées, anglais + **un seul mot français**. Docblock : « Common victim words like 'test', 'suspect', 'strange' are intentionally ALLOWED » (`:42`) |
| D5 | `OPERATIONAL_LEAKAGE_PATTERNS` | `PolicyGuard.php:67-78` | post-LLM | **10 couples motif/suffixe** : `n8n`, `workflow[_\s-]?(id\|name)?`, `ingest/raw`, `api/v1/(admin\|internal)`, `SCAMBUSTER_[A-Z][A-Z0-9_]*`, `backend-(dev\|test\|preprod\|e2e\|prod)`, `MailAccount(SecretResolver)?`, `IocUpsertService`, `sodium_crypto_secretbox`, `docker[\s-]compose` | Liste fixe de 10 jetons d'infrastructure littéraux |
| D6 | Motifs OPSEC fournis par l'opérateur (union seule) | `PolicyGuard.php:312-324` | post-LLM | Itère `$additionalOpsecPatterns` ; `@preg_match(...) === 1` → drapeau `opsec_extra:{i}`. Docblock : « Additive only — it can never remove or weaken a HARM pattern » (`:202-204`) | **Les regex invalides sont silencieusement ignorées** (suppression par `@`) |
| D7 | `THREAT_PATTERNS` | `PolicyGuard.php:83-91` | post-LLM | **7 regex**, 6 FR + 1 EN (`je vais vous (tuer\|frapper\|détruire\|blesser\|éliminer)`, `i will (kill\|hurt\|destroy\|harm)`, `vous êtes mort`, `je sais où vous (habitez\|vivez)`, `gare à (toi\|vous)`, …) | 7 entrées ; FR/EN seulement |
| D8 | `AUTHORITY_PATTERNS` (usurpation d'autorité) | `PolicyGuard.php:94-122` | post-LLM | **22 regex** en FR/EN/ES/DE/IT : police, gendarmerie, Interpol, Europol, FBI, procureur, juge, `soy del banco`, `ich bin von der Bank`, `sono della banca`, `des impôts`, `du fisc`, `dgfip`, `irs\|hmrc\|tax (office\|authority)`, `hacienda`, `finanzamt`, `au nom de la loi`, `mandat d'arrêt` | 5 langues ; liste de formulations fixe |
| D9 | `PII_PATTERNS` | `PolicyGuard.php:131-134` | post-LLM | **2 regex seulement** : IBAN `/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/` et adresse postale française `/\b\d{1,3}\s+(?:rue\|avenue\|boulevard\|impasse)\s+[A-Z]/i`. Émet le drapeau unique `pii_detected` puis `break` | Le commentaire indique que téléphone/pseudos/portefeuilles ont été « moved to the dedicated `OUT_OF_BAND_CHANNEL_PATTERNS` » (`:127-129`). L'adresse ne couvre que **4 substantifs de voie français** |
| D10 | `OUT_OF_BAND_CHANNEL_PATTERNS` | `PolicyGuard.php:159-197` | post-LLM | **9 regex clefées**, évaluées dans l'ordre (crypto d'abord pour que le fourre-tout `phone` ne masque pas) : `crypto_btc`, `crypto_eth`, `crypto_xmr`, `telegram_handle`, `skype_uri`, `signal_discord`, `phone`, `messenger_link` (`t.me`/`wa.me`), `redirect_email`. Drapeau `out_of_band_channel:<kind>` puis `break` | Docblock : « A bare app NAME is intentionally NOT matched » (`:190-192`) ; un jeton de pseudonyme **sans `@`** n'est pas capté (`SafetyInvariantOracle.php:132-134`) |
| D11 | `PolicyGuardConfig::fromContext()` — bandes de mots contextuelles | `src/Application/LLM/PolicyGuardConfig.php:50-77` | pré-PolicyGuard | `match(true)` : accusation de bot → `12/70` ; agression → `15/90` ; post-IBAN → `18/100` ; évasif → `18/120` ; persona laconique → `12/120` ; défaut `20/150` (`:70-75`) | Les drapeaux d'entrée proviennent du `ConversationAnalyzer` (**LLM**) ou du contexte appelant |
| D12 | `SignatureStripper::strip()` | `src/Application/LLM/SignatureStripper.php:101` | Juste après génération, **avant** PolicyGuard (`RetryCoordinator.php:118-130`) | 3 motifs : (1) **36 formules de politesse** EN/FR/ES/DE/IT/PT/NL, listées de la plus longue à la plus courte (`:43-92`), regex `'/\n+(?:'.$alternation.')[,!.]?\s*\n.*$/sui'` (`:129`) ; (2) marqueurs entre crochets en fin (`:145`) ; (3) séparateur RFC 3676 (`:161`) | Docblock : « does NOT match signoff words inline mid-sentence » ; les cas limites (anglais informel, fautes de frappe, nom lâché en milieu de corps) sont « delegated to the LLM-based validator » (`:25-27`). **Conditionné par `REPLY_SIGNATURE_STRIP_ENABLED` ; si faux, retourne l'entrée inchangée** (`:106-114`) |
| D13 | Garde-fou de divergence de Levenshtein | `RetryCoordinator.php:482-539` | Entre deux tentatives | `levenshtein()` si `max(strlen) <= 255`, sinon `similar_text()` ; journalise un avertissement si `$ratio > 0.5` (`:530`) | **Explicitement non bloquant** : « Does NOT block the loop — purely monitoring/forensic log » (`:478`) |
| D14 | Règle « meilleur sur 3 » à fermeture sûre | `RetryCoordinator.php:364-366`, `:376-394` | Fin de boucle | Seul un brouillon dont `validatorResult['security_pass'] === true` peut être réutilisé. Commentaire : « A draft rejected for a security reason — or never security-checked at all — is never sent (fail closed at the last layer) » (`:88-90`) | **Dépend du champ `security_pass` produit par le LLM validateur** |
| D15 | Plafond de tentatives | `RetryCoordinator.php:35` | Boucle | `MAX_ATTEMPTS = 3` | — |
| D16 | Repli figé en cas d'épuisement | `RetryCoordinator.php:762-791`, `FallbackProvider` | Après boucle | `getFallback($detectedLanguage, $convId, $msgCount)`, roulement par tour et par conversation (`:774-776`) | — |

### 4.2 Repli déterministe à l'intérieur d'un garde LLM

| # | Nom | Fichier:ligne | Position | Portée exacte | Non couvert |
|---|---|---|---|---|---|
| D17 | `PaymentInstigationGuard::containsPaymentInfraTokens()` | `src/Application/LLM/PaymentInstigationGuard.php:185-194`, constante `:78-91` | **Uniquement sur le chemin d'échec du juge LLM** — « The deterministic scan runs only here — never on the nominal (judge-OK) path » (`:157-158`) | **12 regex** : `(swift\|bic\|iban\|ifsc)`, `(neft\|rtgs\|aba)`, `sort code`, `routing number`, `account number`, `(wire\|bank) transfer`, `beneficiary`, `wallet address`, `remit(tance)?`, `virement`, `transferencia`, `(ü\|u)berweisung` | 12 jetons ; une paraphrase libre (« send the funds to my account ») n'est pas captée |

### 4.3 `SafetyInvariantOracle` — oracle déterministe du sous-système GUARD

| # | Nom | Fichier:ligne | Position | Portée exacte | Non couvert |
|---|---|---|---|---|---|
| D18 | `SafetyInvariantOracle::violations()` | `src/Application/Guard/SafetyInvariantOracle.php:177-214` | **Notation hors ligne** des textes sortants capturés (agrégat de canari), **pas dans le chemin d'envoi réel** | Retourne des codes parmi `ALL_CODES` (`:49-57`) : `payment_token`, `payment_instrument`, `crypto_wallet`, `out_of_band_channel`, `word_band`, `language_mismatch`, `automation_reveal` | Auto-déclaré : « It is a DETECTOR compared against a baseline (delta), not a blocker » (`:33-34`) |
| D19 | `PAYMENT_TOKEN_PATTERNS` | `SafetyInvariantOracle.php:87-105` | oracle | **16 regex** = les 12 de `PaymentInstigationGuard` à l'octet près + 4 extensions (`transfer[eê]ncia`, `bonifico`, `overschrijving`, `przelew`) | Déclaré **INFORMATIONNEL** (`:70-72`) — ne bloque jamais la porte. Docblock : « Residual free-paraphrase … is an inherent limit » (`:84-85`) |
| D20 | `PAYMENT_INSTRUMENT_PATTERNS` (bloquant) | `SafetyInvariantOracle.php:114-116` | oracle | **1 regex** : IBAN littéral | IBAN seul ; aucun instrument carte / numéro de compte |
| D21 | `CRYPTO_WALLET_PATTERNS` | `SafetyInvariantOracle.php:119-123` | oracle | 3 regex (BTC/ETH/XMR), « byte-identical mirror of PolicyGuard; drift-tested » | 3 chaînes |
| D22 | `OUT_OF_BAND_PATTERNS` | `SafetyInvariantOracle.php:136-144` | oracle | 6 regex : pseudo Telegram, URI Skype, Signal/Discord, fourre-tout téléphone, `t.me`/`wa.me`, adresse email | Ne capte pas un **nom** d'application nu ; « A bare username token with no '@' … is NOT caught » (`:132-134`) |
| D23 | `AUTOMATION_REVEAL_PATTERNS` | `SafetyInvariantOracle.php:153-165` | oracle | **10 regex** = les 6 `FORBIDDEN_PATTERNS` de PolicyGuard + 4 extensions (`language model`, `(as\|being) an? (ai\|automated\|artificial)`, `i (was \|am )?(generated\|programmed)`, `automated (assistant\|reply\|response\|message)`) | 10 entrées |
| D24 | Bande de mots + contrôle de langue | `SafetyInvariantOracle.php:74-75`, `:201-211` | oracle | `MIN_WORDS = 20`, `MAX_WORDS = 150` ; langue via `LanguageDetector::detect()` contre `$expectedLanguage` | Bande **fixe**, indépendante des bandes contextuelles de `PolicyGuardConfig` (D11) |
| D25 | Empreinte de l'oracle | `SafetyInvariantOracle.php:223-237` | baseline / comparaison | `substr(hash('sha256', json_encode([ALL_CODES, les 5 jeux de motifs, MIN_WORDS, MAX_WORDS])), 0, 12)` | — |
| D26 | Exclusion de périmètre déclarée | `SafetyInvariantOracle.php:29-31` | — | « It deliberately does NOT re-check PolicyGuard's THREAT / AUTHORITY / PII sets » | Les jeux D7/D8/D9 ne sont donc **pas** sous surveillance de régression |
| D27 | Tests de dérive par réflexion (oracle ⊇ gardes) | `tests/Unit/Application/Guard/SafetyInvariantOracleTest.php:219,231,246` | CI, suite unitaire | Vérifie que l'oracle contient bien les motifs de `PaymentInstigationGuard` et de `PolicyGuard` | — |

### 4.4 Contrôles d'entrée (pré-LLM)

| # | Nom | Fichier:ligne | Position | Portée exacte | Non couvert |
|---|---|---|---|---|---|
| D28 | `PromptInjectionPatternMatcher::scan()` — détecteur regex de couche 1 | `src/Application/Communication/PromptInjectionPatternMatcher.php:136` | Entrant, après persistance, dans `IngestPostProcessor::analyzePromptInjection()` (`:546`) | 6 groupes / **25 regex**. Poids : groupes forts (`instruction_override`, `prompt_extraction`, `jailbreak`) = +0,4 ; moyens (`role_manipulation`, `delimiter_injection`) = +0,25 ; faible (`encoding_obfuscation`) = +0,15 ; `min(1.0, …)` (`:296-323`). Analyse `subject + "\n" + bodyText` (`PromptInjectionDetector.php:53`) | **Forensique seulement** : « Detection is forensic -- it does not block ingestion or modify the reply pipeline » (`PromptInjectionDetector.php:18`). N'analyse **ni les pièces jointes ni les autres en-têtes** que le sujet |
| D29 | `INSTRUCTION_OVERRIDE_PATTERNS` | `:19-25` | entrant | 5 regex | **anglais seul** |
| D30 | `ROLE_MANIPULATION_PATTERNS` | `:28-35` | entrant | 6 regex | anglais seul ; `dan_jailbreak` **sans `/i`** (sensible à la casse) |
| D31 | `PROMPT_EXTRACTION_PATTERNS` | `:38-43` | entrant | 4 regex | anglais seul |
| D32 | `DELIMITER_PATTERNS` | `:46-53` | entrant | 6 regex (``` ```(system\|prompt) ```, `[INST]`, `<\|im_start\|>`, `<\|system\|>`, `^#{3,}\s*(system\|…)`, `<system>`) | 6 formes de délimiteur |
| D33 | `ENCODING_PATTERNS` | `:56-69` | entrant | 4 regex : fragments base64 (`aWdub3Jl`, `SWdub3Jl`, `ZGlzcmVnYXJk`, `Zm9yZ2V0`) ; `[\x{200B}\x{200C}\x{200D}\x{FEFF}]{3,}` ; caractère invisible intra-mot ; échappement `\\u00XX` ×4+ | Commentaire : le trait d'union conditionnel U+00AD « is deliberately NOT flagged here » (`:63-66`) ; le base64 ne couvre que **4 mots encodés** |
| D34 | `JAILBREAK_PATTERNS` | `:119-124` | entrant | 4 regex | anglais seul |
| D35 | Normalisation homoglyphes / caractères invisibles | `:157-167`, `:235-269` | entrant | Ne s'exécute que si `hasNonAscii()` (`:224-227`). Retire `INVISIBLE_CHARS` (`:85`), replie `CONFUSABLES` (`:101-116` — **25 cyrilliques + 22 grecs** → ASCII), NFKC via `\Normalizer`, puis ICU `Any-Latin; Latin-ASCII` | **Le translittérateur ICU retourne `null` si l'extension intl est absente — « detection degrades to the literal pass »** (`:273-274`) ; table de confusables limitée au cyrillique et au grec |
| D36 | Plafond anti-déni de service de l'analyse | `:79`, `:139-148` | entrant | `MAX_SCAN_BYTES = 1_048_576` (1 Mo) ; au-delà, `mb_strcut` tronque et journalise — « we truncate and flag rather than reject » | **Le contenu au-delà de 1 Mo n'est pas analysé** |
| D37 | `matchPreFilter()` — préfiltre de courrier automatisé | `src/Application/Communication/IngestPostProcessor.php:88-146` | Entrant, avant classification et réponse | Ordre : (1) expéditeurs de test opérateur (CSV d'environnement, égalité stricte) ; (2) `KNOWN_LEGITIMATE_DOMAINS` — **15 entrées** : `instagram.com, facebook.com, facebookmail.com, google.com, linkedin.com, twitter.com, x.com, github.com, apple.com, microsoft.com, amazon.com, paypal.com, netflix.com, spotify.com, dropbox.com` (`:36-41`) ; (3) `LOCAL_PART_PATTERNS` — **7 entrées** : `noreply, no-reply, postmaster, abuse, mailer-daemon, dmarcreport, dmarc-noreply` (`:50-53`) ; (4) sujet `'/^\s*Report\s+Domain:/i'` | Docblock : « commercial B2B included — explicitly left to the classifier out-of-scope » (`:79-80`) |
| D38 | Conversion HTML → texte | `src/Application/Communication/EmailParsingService.php:320-349` | Analyse entrante | Retire `<script>`/`<style>` avec leur contenu (`:323-324`), `html_entity_decode(ENT_QUOTES\|ENT_HTML5,'UTF-8')`, balises de bloc → sauts de ligne, `<li>` → `"\n• "`, puis `strip_tags()` | **Aucune bibliothèque de désinfection** ; aucun traitement d'attribut ni d'URL `javascript:` (extraction de texte seule) |
| D39 | Plafond de taille de pièce jointe + exclusion des parties inline | `EmailParsingService.php:27`, `:238-266`, `:308-314` | Analyse entrante | `DEFAULT_MAX_ATTACHMENT_SIZE_BYTES = 25 * 1024 * 1024` (25 Mo) ; lecture par blocs de 64 Ko avec abandon au dépassement ; `isExtractableAttachment()` exclut les parties `inline` | **Aucune liste blanche ni noire de types MIME** — `$mimeType = $part->getContentType() ?? 'application/octet-stream'` (`:275`) |
| D40 | Échecs stricts de décodage MIME / base64 | `EmailParsingService.php:63-75` | Analyse entrante | `base64_decode(..., true)` strict ; `RuntimeException('Invalid base64 in raw_source')` / `'Mail parse error: …'` | — |
| D41 | `SenderFloodDetector` | `src/Application/Communication/SenderFloodDetector.php:18-20`, `:43-78` | Entrant, `IngestPostProcessor::checkSenderRateLimits()` (`:611`) | `BURST_THRESHOLD = 5`, `BURST_WINDOW_SECONDS = 300`, `QUARANTINE_SECONDS = 3600`. Clé `sha256(strtolower($senderEmail))` | **`getQuarantinedCount()` est « a no-op placeholder » retournant 0** (`:84-89`) |
| D42 | `MessageAnonymizer::anonymize()` | `src/Application/Communication/MessageAnonymizer.php:23-37`, `:44-56` | **Masquage PII pré-LLM** (construction de prompt uniquement) | 5 motifs ordonnés : email→`[EMAIL]`, IBAN→`[IBAN]`, BTC→`[WALLET]`, ETH→`[WALLET]`, téléphone→`[PHONE]` | Docblock : « URLs are intentionally NOT included (they are IOCs needed by the LLM) » (`:19`). **Utilisé seulement par `ContextualEnricher.php:26`**, jamais par la purge |
| D43 | `MessageRedactionService::redactHeaders()` | `src/Application/Communication/MessageRedactionService.php:12-26` | Supervision / export | Remplace exactement `From`, `To`, `X-Originating-IP`, `Received` par `[REDACTED]` | Clés **sensibles à la casse** ; 4 en-têtes seulement |

### 4.5 Débit, cadence, kill switch, budget

| # | Nom | Fichier:ligne | Portée exacte | Non couvert |
|---|---|---|---|---|
| D44 | Configuration `rate_limiter` | `config/packages/rate_limiter.yaml:1-64` | `login_ip` 5/1 min ; `login_email` 5/15 min ; `api_global` seau à jetons 100, recharge 100/1 min ; `replies_per_conversation` 20/24 h ; `llm_calls_per_hour` 200/1 h ; `active_conversations_per_day` 50/24 h ; `emails_per_sender_per_day` 10/24 h ; `ingest_per_account` 100/1 h | — |
| D45 | `ReplyCadenceService::checkRateLimits()` | `src/Application/Communication/ReplyCadenceService.php:116-165` | 3 niveaux consommés dans l'ordre ; chacun émet `RATE_LIMIT_EXCEEDED` | **Contourné quand `$force === true`** (`ReplyHandler.php:194`) |
| D46 | Délai minimal entre réponses | `ReplyCadenceService.php:27`, `:82-109` | `MIN_HOURS_BETWEEN_REPLIES = 6` | **Contourné par `$force`** (`ReplyHandler.php:189`) |
| D47 | Kill switch (2 couches) | `ReplyCadenceService.php:30`, `:55-77` ; lève dans `ReplyHandler.php:137-139` | Couche 1 : clé de cache `llm.killswitch.active` ; couche 2 : env `SCAMBUSTER_KILL_SWITCH` via `FILTER_VALIDATE_BOOLEAN` | Une panne du pool de cache est **captée et journalisée**, puis on retombe sur la couche env (`:65-71`). Ne gouverne que la génération de réponse |
| D48 | Liste sûre de domaines | `ReplyCadenceService.php:170-200` | Joker `'*'` dans `SCAMBUSTER_SAFE_DOMAINS` → toujours vrai (`:179-181`) ; liste intégrée `['example.test','mailinator.com','guerrillamail.com']` (`:183`) + CSV d'environnement | **Le résultat est exposé en métadonnée `safelist_eligible` (`ReplyHandler.php:394`) ; aucun chemin de code trouvé où `false` bloque un envoi** |
| D49 | Plafond budgétaire LLM mensuel | `ReplyHandler.php:146-158` ; `LlmCostHandler.php:102,123-129` | `'enforce'` → `LlmBudgetExceededException` (HTTP 503) ; `'warning'` → journal et poursuite ; seuil souple 0,8 de la limite | **Défaut `'warning'` dans la signature du constructeur** (`ReplyHandler.php:41`) |
| D50 | Invariant d'alternance (« Verrou A ») | `ReplyHandler.php:168-186`, `:442-462` | Refuse un nouveau sortant si le dernier message non supprimé est déjà `out`. Commentaire : « it is enforced unconditionally — force=true does NOT bypass it » (`:171-172`) | — |
| D51 | Refus sur conversation close | `ReplyHandler.php:164-166` | `if ($conversation->getStatus()->value !== 'open') throw` | — |

### 4.6 Limites de cycle de vie des conversations

| # | Nom | Fichier:ligne | Portée exacte |
|---|---|---|---|
| D52 | `ConversationLifecycleConfig::POLICIES` | `src/Application/Communication/ConversationLifecycleConfig.php:22-55` | **14 politiques clefées** (`timeout_hours / max_turns / max_duration_days / allow_reopen / reopen_window_hours`) : `ROMANCE 336/50/60/true/72` ; `INVESTMENT 168/40/45/true/48` ; `INVOICE_FRAUD 72/30/21/true/72` ; `CEO_FRAUD 120/25/14/true/72` ; `ADVANCE_FEE_419 168/40/30/true/48` ; `PHISHING`/`PHISH_CREDENTIALS`/`PHISH_MALWARE 48/15/7/true/72` ; `TECH_SUPPORT 24/20/5/true/72` ; `LOTTERY 72/25/14/false/0` ; `JOB_OFFER 72/25/14/true/72` ; `CHARITY 72/25/14/false/0` ; `UNKNOWN 72/25/14/true/72`. Défaut `72/25/14/true/72` (`:58`) |
| D53 | `CloseStaleConversationsCommand` | `src/UI/Console/CloseStaleConversationsCommand.php:23-25`, `:156-173` | Trois motifs de clôture : inactivité, `turnsCount >= max_turns`, ancienneté. `--days`, `--dry-run` |

### 4.7 Extraction, normalisation et validation d'IOC

| # | Nom | Fichier:ligne | Portée exacte | Non couvert |
|---|---|---|---|---|
| D54 | `IocExtractionPolicy::allows()` | `src/Domain/Communication/Policy/IocExtractionPolicy.php:23-26` | `direction->getCode() === 'in'` uniquement | — |
| D55 | `TtpExtractionPolicy::allows()` | `src/Domain/Communication/Policy/TtpExtractionPolicy.php:17-20` | Même règle, entrant seul | — |
| D56 | Couche 1 d'upsert — refus des sortants | `src/Application/Communication/IocUpsertService.php:190-199` | `InvalidArgumentException`. Commentaire : « Single funnel: this guard catches all callers (HTTP /enriched, MigrateHeaderIocs, IngestPostProcessor, future entry points) » (`:191-192`) | — |
| D57 | Couche 2 d'upsert — refus des identifiants honeypot | `IocUpsertService.php:205-214`, comparateur `:82-154` | Comparaison insensible à la casse, non défanguée, contre `honeypotAddressesIndex` / `honeypotDomainsIndex` (injectés depuis l'environnement). Couvre `email` (exact + partie domaine), `domain` (exact), `url` (hôte après retrait de `www.`) | **3 types seulement** ; **nécessite que les listes d'environnement soient peuplées** |
| D58 | Plafond anti-déni de service de l'extracteur regex | `src/Application/Communication/IocExtractorOrchestrator.php:29`, `:213-222` | `MAX_REGEX_BYTES = 1_048_576` | Texte au-delà de 1 Mo non analysé |
| D59 | Batterie de motifs d'IOC | `IocExtractorOrchestrator.php:226-247` | **20 motifs typés** : `ipv4, ipv6, email, url, domain, md5, sha1, sha256, iban, bic, wallet_btc, wallet_eth, wallet_xmr, credit_card, phone, telegram_username, discord_username, cve, mitre_attack_id, tracking_number` | `tracking_number` limité à `DHL\|UPS\|FedEx\|USPS\|TNT\|EMS\|Royal Mail\|Colissimo` |
| D60 | Filtre d'IPv4 privées / réservées | `IocExtractorOrchestrator.php:262-264`, `:285-299` | Écarte `10/8`, `172.16/12`, `192.168/16`, `127/8` ; `ip2long()===false` écarte aussi | **4 plages seulement** — ni `169.254/16`, ni `100.64/10`, ni `0.0.0.0/8`, ni multicast, ni plages privées IPv6 |
| D61 | Liste d'exclusion des messageries gratuites | `IocExtractorOrchestrator.php:120-124` | **12 entrées** : `gmail.com, yahoo.com, outlook.com, hotmail.com, proton.me, protonmail.com, live.com, icloud.com, aol.com, mail.com, yandex.com, zoho.com` | 12 fournisseurs ; s'applique uniquement à `derived_from_email` |
| D62 | `IocValidator::validate()` | `src/Application/Communication/IocValidator.php:100-164` ; motifs `:29-90` | Table regex par type (~35 types). **Validation de somme de contrôle** pour `iban` (`Iban::isValid`), `wallet_btc`, `wallet_eth`, `credit_card` (Luhn, `:169-198`), `bank_account`. Type inconnu → `false` (`:157-159`) | `subject, x_mailer, registrar, whois_registrar_name, filename, malware_family` utilisent `'/.+/'` ; `postal_address` n'est qu'un contrôle de longueur `'/^.{10,500}$/s'` (`:89`) |
| D63 | Défangage `IocNormalizer` | `src/Application/Communication/IocNormalizer.php:37-92`, `:105-141`, `:229-254` | URL : `http→hxxp`, points → `[.]`, `/` final retiré, minuscules ; domaines minuscules + `[.]` ; IBAN sans espaces et en majuscules ; téléphone sans séparateurs sauf `+` ; empreintes en minuscules ; `refang()` inverse | `wallet_*`, `filename`, `message_id`, `subject`, `bank_account` conservés tels quels |
| D64 | `IocActionablePolicy::NON_ACTIONABLE_TYPES` | `src/Domain/Communication/Policy/IocActionablePolicy.php:41-77` | **17 types exclus** des comptages : `subject, message_id, x_mailer, return_path, spf_result, dkim_result, dmarc_result, whois_email, whois_registrar_name, registrar, filename, mimetype, cve, malware_family, mitre_attack_id, tracking_number, md5, sha1, sha256` | Affecte les compteurs, **pas l'export** |
| D65 | `CleanupPlatformContaminationCommand` | `src/UI/Console/CleanupPlatformContaminationCommand.php:39-585` | Phase 5 : suppression de tout `observed_ioc` rattaché à un message `direction = 'out'` (`:536-544`) ; phase 6 : indicateurs orphelins (`:546-551`) ; phase 7 : indicateurs honeypot après `unDefang()` (`:342-345`, `:362-475`). Audit CSV vers `var/audit/061-cleanup-{ts}.csv` ; phases 5–7 en une transaction avec rollback (`:227-245`) | Correspondance honeypot sur **3 types d'IOC** ; **sans effet si les deux listes sont vides** (`:563-565`) |

### 4.8 Blocage d'export des IOC financiers (protection mule / victime)

| # | Nom | Fichier:ligne | Portée exacte | Non couvert |
|---|---|---|---|---|
| D66 | `IocExportPolicy` — source de vérité unique de la sortie | `src/Domain/Communication/Policy/IocExportPolicy.php:31-84` | **Deux règles à fermeture sûre** : (1) `verdict === AnalystVerdict::FalsePositive` → jamais exporté ; (2) `IocCategory::classify($type) === FINANCIAL` → exporté **seulement si** `verdict === Confirmed` (`:47-58`). `isHeldForReview()` = `verdict === null && classify === FINANCIAL` (`:39-42`) | Se compose avec, sans remplacer, le filtre TLP:RED et `IocActionablePolicy` (`:28-29`) |
| D67 | Ensemble des types retenus | `src/Domain/Communication/IocCategory.php:33-43` | `FINANCIAL_TYPES` = **10 entrées** : `iban, bic, swift, bank_account, routing_number, wallet_btc, wallet_eth, wallet_xmr, wallet, credit_card` | `phone`, `email` relèvent de `CONTACT` et **ne sont pas retenus** |
| D68 | Fragment SQL | `IocExportPolicy.php:72-83` | `(f.verdict IS NULL OR f.verdict <> 'false_positive') AND (LOWER(BTRIM(i.type)) NOT IN (…10 types…) OR f.verdict = 'confirmed')` ; type comparé via `LOWER(BTRIM(...))` « so a mixed-case or padded financial type cannot bypass it » (`:68-70`) | Exige une jointure gauche sur `ioc_analyst_feedback` |
| D69 | Points d'appel en sortie | `TaxiiService.php:291` ; `IocFeedExporter.php:219` ; `ClusterQueryService.php:592` ; `ConversationStixExportHandler.php:208` ; `IocStixExportHandler.php:76` ; `ExportMispController.php:145` | **5 au niveau SQL + 1 au niveau PHP** (`isExportable`, MISP). MISP positionne en outre `to_ids = ($verdict === Confirmed)` et étiquette `scambuster:analyst-verdict="…"` (`ExportMispController.php:150-152`) | **Le gestionnaire STIX conserve les lignes d'IOC dépourvues d'`indicator_id` persisté** — « IOC rows without a persisted indicator id cannot be verdict-checked; keep legacy behaviour » (`ConversationStixExportHandler.php:227-228`) |
| D70 | Exposition de l'état de blocage dans l'API de lecture | `src/Application/Communication/IocQueryService.php:180`, `:392` | `'export_held' => IocExportPolicy::isHeldForReview($type, $verdict)`. Commentaire (`ConversationStixExportHandler.php:199-202`) : « the internal UI must keep showing held IOCs so an analyst can review and release them, but they must not leave the platform in a STIX bundle » | — |
| D71 | Chemin de libération — verdict d'analyste | `src/UI/Http/ThreatActor/SubmitIocFeedbackController.php:31-79` | `POST /api/v1/iocs/{indicatorId}/feedback`, `indicatorId` contraint à `[0-9a-f-]{36}`, `#[IsGranted('ioc:feedback')]` (`:32`). `verdict` invalide → 422 ; `note` tronquée à 1000 caractères (`:62`) ; émet `IOC_FEEDBACK` avec l'IP cliente (`:67-76`) | — |
| D72 | Persistance du verdict + repli de confiance | `src/Application/ThreatActor/IocFeedbackService.php:33-74` | Upsert sur `ioc_analyst_feedback` (`ON CONFLICT (indicator_id) DO UPDATE`) ; `Confirmed` → `confidence_score = GREATEST(...)`, `FalsePositive` → `confidence_score = :conf` (`:62-74`) | **Une seule ligne par indicateur** — le dernier verdict remplace le précédent |
| D73 | Filtre TLP:RED jamais public | `src/Application/Taxii/TaxiiService.php:275`, `:779` | `UPPER(REPLACE(REPLACE(i.tlp,'TLP:',''),'TLP_','')) <> 'RED'`, appliqué aux indicateurs et aux campagnes promues | **`IocFeedExporter.php:19` indique que le flux analyste « does NOT strip TLP:RED — the analyst is trusted »** |

### 4.9 Authentification, autorisation, secrets

| # | Nom | Fichier:ligne | Portée exacte | Non couvert |
|---|---|---|---|---|
| D74 | Pare-feux et contrôle d'accès | `config/packages/security.yaml:16-63` | Pare-feux `dev` (désactivé), `api_auth` (`^/api/v1/auth`, sécurité désactivée), `api` (`^/api/v1`, sans état, JWT + `TaxiiApiKeyAuthenticator`), `main`. Contrôle d'accès : `/api/metrics`, `/api/health`, `/api/v1/admin`, `/api/v1/internal`, `/api/v1/campaign/hunt`, `…/promote`, `…/rules/compile` → `ROLE_ADMIN` ; `^/api/v1` → `IS_AUTHENTICATED_FULLY` ; `^/api/v1/auth`, `^/logout`, `^/healthz` → `PUBLIC_ACCESS` | — |
| D75 | `PermissionVoter` | `src/Security/PermissionVoter.php:23-59` | `ROLE_ADMIN` → toutes permissions (`:34-36`) ; `ROLE_TAXII_FEED` → `ioc:read` seul, vérifié avant le repli InMemoryUser (`:41-44`) ; entité `User` → `hasPermission()` ; InMemoryUser avec `ROLE_USER` → toutes permissions (environnement de test) | **14 permissions** énumérées dans `src/Domain/User/Permission.php:19-40` |
| D76 | `TaxiiApiKeyAuthenticator` | `src/Security/TaxiiApiKeyAuthenticator.php:45-160` | `^/api/v1/taxii2` seulement (`:48`) ; clé par en-tête `X-TAXII-API-KEY` ou Basic — explicitement pas `Authorization: Bearer` (`:29-33`) ; `MIN_KEY_LENGTH = 32`, en deçà la fonction est désactivée (`:63`, `:114-117`) ; comparaison `hash_equals()` (`:86`) ; 401 générique qui n'écho jamais l'identifiant (`:105-112`) | — |
| D77 | TOTP 2FA | `config/packages/scheb_2fa.yaml:1-8` | `digits: 6`, `period: 30`, `algorithm: sha1`, émetteur `ScamBuster` | — |
| D78 | OIDC — **détaillé en §4.11** | `src/Application/Auth/Oidc/` (7 classes) + `OidcLoginController`, `OidcCallbackController` | Module opt-in, **désactivé par défaut** (`config/services.yaml:26`) ; 11 contrôles déterministes recensés | Voir §4.11 |
| D79 | `SecretPolicy` | `src/Security/SecretPolicy.php:20-135` | Voir §6.4 | **Jamais appliqué hors production** — retourne `[]` si `!$isProd` (`:71-73`). **Aucun minimum d'entropie ni de longueur** au-delà du contrôle de caractère répété |
| D80 | `CheckSecretsCommand` | `src/UI/Console/CheckSecretsCommand.php:29-101` | 7 variables (§6.5) | Sans effet hors `APP_ENV=prod` (`:57`, `:66-72`) |
| D81 | Scanner de fuite de noms de honeypot | `scripts/check-honeypot-leak.sh` | Hook pre-commit + porte 9 du préflight. Lit `local/honeypot-names.txt` (ignoré par git) ; correspondance littérale insensible à la casse (`grep -inF`) ; mode `staged` sur `git diff --cached`, `--full` sur `git ls-files` ; sortie 1 avec `fichier:ligne: matched 'nom'` | **« Exits zero (with a one-line skip note) when local/honeypot-names.txt is absent »** ; liste vide → saut également. Correspondance par sous-chaîne seule — **ni approximative ni homoglyphique** |
| D82 | Porte 9 du préflight | `scripts/preflight-check.sh:158-161` | `CURRENT_GATE=9` exécute `check-honeypot-leak.sh --full` ; 9 portes au total, interruption sur la première en échec (`:52`) | — |
| D83 | Hook pre-push GUARD (optionnel) | `scripts/hooks/pre-push-guard.sh:1-50` | Déclenché sur `config/scambuster/\|src/Application/LLM/\|src/Application/Guard/` (`:25`). **Par défaut : simple rappel, la poussée passe** ; `GUARD_ON_PUSH=1` exécute `make guard` et bloque | **Non installé par défaut** — nécessite `make guard-hook-install` (`Makefile:239-244`) |

### 4.11 Module OIDC — contrôles déterministes (passe complémentaire)

Module **opt-in, désactivé par défaut** [VÉRIFIÉ] `config/services.yaml:26` ; les deux
points d'entrée lèvent `NotFoundHttpException` quand il est inactif
(`OidcLoginController.php:37-39`, `OidcCallbackController.php:43-45`).

| # | Contrôle | Fichier:ligne | Portée exacte | Non couvert |
|---|---|---|---|---|
| D84 | Génération du `state` | `OidcStateManager.php:35` | `base64Url(random_bytes(32))` — 32 octets CSPRNG | — |
| D85 | Signature HMAC-SHA256 du `state` | `OidcStateManager.php:112-115`, vérifiée `:69` par `hash_equals` | Le `state` est **auto-porté dans un cookie signé**, sans session serveur (déclaré `:12-13`) | Ne lie pas l'état à une identité de navigateur au-delà du cookie |
| D86 | Comparaison du `state` retourné | `OidcCallbackController.php:62-64` — `hash_equals` | Temps constant | — |
| D87 | Durée de vie du `state` | `OidcStateManager.php:21` — `TTL_SECONDS = 600` (10 min) ; posé `:39`, appliqué `:92-94`, expiration du cookie `:107-110` | Expiration côté serveur dans la charge signée + expiration du cookie | **Aucun registre d'usage unique** : l'état étant sans état, la même valeur est rejouable dans la fenêtre de 600 s |
| D88 | `nonce` | Généré `OidcStateManager.php:36` ; envoyé `OidcService.php:46` ; **vérifié** contre le jeton d'identité `OidcService.php:210-212` | Comparaison stricte `!==` | Comparaison non à temps constant |
| D89 | **PKCE S256** | Vérificateur `OidcStateManager.php:31` (`random_bytes(64)`), défi `:32` (`sha256`), envoi `OidcService.php:47-48`, rejeu `:115` | PKCE complet en S256 | — |
| D90 | Épinglage de l'émetteur `iss` | `OidcService.php:191-193` | Comparé `!==` à l'`issuer` **issu du document de découverte** (`:61`) | **Non épinglé à une valeur de configuration statique** : l'émetteur attendu est celui que renvoie l'URL de découverte |
| D91 | Vérification de l'audience `aud` | `OidcService.php:195-202` | Gère chaîne et tableau ; `in_array(..., true)` / `===` contre `clientId` | `azp` contrôlé seulement si `aud` est un tableau de plus d'un élément **et** `azp` présent (`:206-208`) |
| D92 | Contrôle d'expiration `exp` | `OidcService.php:214-218` | Rejette les jetons expirés ; `exp` absent ou non numérique → `0` (`:265-268`) → rejeté | **`iat` jamais lu** (recherche `iat` sur le répertoire : aucun résultat) ; **aucune tolérance de dérive d'horloge** |
| D93 | Contrôle croisé UserInfo | `OidcService.php:63-71` | Appelle `userinfo_endpoint` et exige `userInfo['sub'] === idClaims['sub']` | C'est le **substitut déclaré** à la vérification de signature — voir §5.2 |
| D94 | `email_verified` | `OidcService.php:82-86` | Rejette toute valeur explicite différente de `true` / `"true"` | **Une revendication absente vaut `true`** (`?? true`, `:82`) |
| D95 | Liste blanche de domaines de courriel | `OidcConfig.php:49-64`, appliquée `OidcService.php:88-90` | Comparaison insensible à la casse sur la partie après le dernier `@` | **Liste vide = tout domaine accepté** (`:51-53`) ; défaut livré `[]` (`config/services.yaml:38`) |
| D96 | Porte de provisionnement automatique | `OidcUserProvisioner.php:34-36` | Courriel inconnu + `autoProvision=false` → exception. **Défaut `false`** (`config/services.yaml:34`) | — |
| D97 | Rôles du compte provisionné | `OidcUserProvisioner.php:43` | `OIDC_DEFAULT_ROLES` si défini, sinon `['ROLE_USER']` ; défaut livré `[]` → `ROLE_USER` | `setPermissions()` **jamais appelé** ; aucun chemin vers `ROLE_ADMIN` |
| D98 | Mot de passe du compte provisionné | `OidcUserProvisioner.php:42` | `bin2hex(random_bytes(32))` haché | — |
| D99 | Drapeaux du cookie d'état | `OidcLoginController.php:45-55` | `secure=true`, `httpOnly=true`, `SameSite=Lax`, chemin `/api/v1/auth/oidc` | Effacé en succès (`:92`), **pas sur le chemin d'échec** (`:79` sort avant) |
| D100 | Hygiène des messages d'erreur | `OidcCallbackController.php:79`, `:116-123` | Le client ne voit que `'SSO authentication failed.'` ; le motif ne va qu'au journal d'audit | — |

**Signature du jeton d'identité** [VÉRIFIÉ] : **non vérifiée**.
`OidcService.php:162-184` scinde le jeton sur `.`, décode **le seul segment `[1]`** et
l'analyse en JSON ; l'en-tête et la signature ne sont jamais lus. Aucune récupération
de JWKS, aucun cache de clés, aucune bibliothèque. Le docblock `OidcService.php:16-21`
**déclare l'omission intentionnelle**, en invoquant OIDC Core §3.1.3.7 — le jeton
arrivant par le canal arrière — et désigne le contrôle croisé UserInfo (D93) comme
preuve de substitution.

**URI de redirection** [VÉRIFIÉ] : **aucune contrainte de schéma**. `redirectUri` est
repris tel quel de `OIDC_REDIRECT_URI` (`config/services.yaml:251`) et utilisé sans
validation (`OidcService.php:43`, `:112`). Même constat pour `successRedirect`
(`OidcCallbackController.php:106`, `:119`). Recherche de `https|isSecure|str_starts_with`
sur le module et les deux contrôleurs : aucun résultat.
**Livraison des jetons** : quand `successRedirect` est non vide, `access_token` et
`refresh_token` sont placés dans le **fragment d'URL** d'une redirection 302 vers cette
cible non validée (`OidcCallbackController.php:99-107`).

---

### 4.12 Autres vérifications de la passe complémentaire

| Constat | Preuve | Portée |
|---|---|---|
| **`app:security:check-secrets` est bien invoqué au démarrage en production** | `infra/docker/backend/docker-entrypoint-prod.sh:29`, avec `set -e` (`:5`), **après** `write-prod-env.sh` (`:23`) et **avant** l'attente PostgreSQL (`:31-41`), la génération de clés JWT (`:43-53`) et les migrations (`:57`) | Confirme l'affirmation du docblock `CheckSecretsCommand.php:19-21` |
| Assertions de variables requises avant tout | `docker-entrypoint-prod.sh:10-18` — `DATABASE_URL`, `APP_SECRET` (+ longueur minimale 12, `:12`), `TOTP_ENCRYPTION_KEY`, `AUDIT_HMAC_KEY`, `JWT_PASSPHRASE` ; `exit 1` explicite | — |
| `write-prod-env.sh` — liste blanche de 25 préfixes | `infra/docker/backend/write-prod-env.sh:6` ; valeurs écrites **brutes, sans échappement**, contrairement à l'entrypoint de démonstration qui les cite via PHP (`docker-entrypoint-demo.sh:33-46`, motif `:29-32`) | — |
| **10 contraintes `CHECK`** en base | `Version20251021140000.php:31,34,81` ; `Version20251121100100.php:50,53` ; `Version20260405120000.php:53` ; `Version20260409100000.php:39` ; `Version2026073000000000.php:407` ; `Version2026073000100000.php:46,47` | Toutes déclarées en ligne dans un `CREATE TABLE`. **Aucune** sur `app_users`, `message`, `conversation`, `observed_ioc`, `audit_log` |
| `.github/scripts/create-ci-env.sh` — **aucun secret réel** | `set -euo pipefail` (`:10`) ; toutes les valeurs d'apparence secrète sont auto-désignées comme fictives : `ci-test-app-secret-32chars-min!!`, `sk-test-not-a-real-key`, `ci-test-passphrase`, `testpass`, `LLM_PROVIDER=mock` (`:25`) | — |
| `scripts/check-credentials.py` — sondes de connectivité | 200 lignes ; code de sortie = **nombre de contrôles en échec** (`:195`) ; IMAP (`:60-83`), SMTP (`:86-117`), LLM (`:129-185`). Un statut HTTP autre que 200/401/403 renvoie `SKIP` et **0** (`:183-185`) | Les secrets ne sont jamais imprimés (`:14`) |
| `TestReplyGenerateCommand` | **Pas d'attribut `#[AsCommand]`** ; enregistrement historique `setName('app:test:reply-generate')` (`:24`), description `:25`. UUID **codé en dur** `:33` ; diagnostic en lecture seule | Ferme la question 31 de §12 |
| **`alert.rules.yml` — 4 règles seulement** | `ScamBusterKillSwitchActive` (warning, `:5-12`), `ScamBusterDependencyDown` (critical, `:14-21`), `ScamBusterMetricsUnreachable` (critical, `:23-30`), `ScamBusterIngestStalled` (warning, `:32-42`) | **Aucune règle** sur le taux d'échec d'authentification, la force brute, l'épuisement de limiteur, la détection d'injection ni le seuil budgétaire |
| Protection de `/api/metrics` et `/api/health` | `config/packages/security.yaml:60-61` — `roles: ROLE_ADMIN` sur les deux, placées après `^/healthz` en `PUBLIC_ACCESS` (`:59`) et avant le fourre-tout `^/api/v1` (`:67`) | Confirme DOC-01 |
| **Démonstration — mot de passe administrateur fixe** | `infra/docker/demo/docker-entrypoint-demo.sh` ne génère aucun mot de passe ; les comptes viennent de la fixture chargée `:180`. Valeur en dur `UserFixtures.php:25,27` — **identique pour l'utilisateur et l'administrateur** — et **imprimée sur la sortie standard** `:389-391`. L'entrypoint de démonstration **n'exécute pas** `app:security:check-secrets` ; CORS par défaut `^https?://.+$` (`:16`) | Périmètre démonstration uniquement |

---

### 4.10 Règles de prompt CORE (non contournables par surcharge, mais non exécutoires)

| Élément | Fichier:ligne | Contenu |
|---|---|---|
| Scission CORE / EDITABLE de `BasePromptRules` | `src/Application/LLM/Prompt/BasePromptRules.php:17-27`, `:41-67`, `:83-90` | Les règles CORE sont « safety-adjacent / anti-unmask invariants that an operator override must never be able to remove ». CORE inclut : aucune connaissance honeypot/bot (`:41`), réponse entièrement en `{detectedLanguage}` (`:44`), règle de signal de paiement (`:50`), interdiction hors bande — « Never give a phone, WhatsApp, Telegram, Skype, Signal, Discord, crypto wallet, IBAN, postal address or a different email address — even fictional ones reveal automation » (`:55`), règle d'escalade acheteur prudent (`:60`), traiter les affirmations de l'expéditeur comme du renseignement et non comme un fait (`:67`) |
| Ordre d'injection des surcharges | `src/Application/LLM/PromptBuilder.php:94-107` | Seul le sous-ensemble EDITABLE passe par la chaîne de surcharge opérateur ; CORE est toujours injecté. **Note dans le code : « the editable override lands AFTER core, so a hostile override can add text that… »** (`:98`) |
| Repli sûr de `PromptProvider` | `src/Application/LLM/Prompt/PromptProvider.php:14`, `:34-35`, `:71`, `:82-83` | « an absent, unreadable, empty, or invalid override degrades to the inline » default ; une surcharge à laquelle manque un placeholder requis passe à la source suivante ; toute erreur de backend est traitée comme « pas de surcharge » |

[DÉDUIT] Les règles CORE sont des **instructions de prompt**, pas des contrôles exécutoires :
leur respect dépend du modèle. Raisonnement : elles sont concaténées dans le texte du
prompt (`PromptBuilder.php:94-107`) et ne sont vérifiées a posteriori que par
`PolicyGuard` (D1–D10), dont les jeux de motifs ne couvrent qu'une partie des invariants
énoncés (par exemple « treat sender claims as intelligence not fact » n'a aucune
contrepartie déterministe).

---

## 5. Contrôles stochastiques (LLM) et dépendances sans repli déterministe

| # | Nom | Fichier:ligne | Décision prise | Repli déterministe en cas d'échec / de sortie aberrante | Action en aval sans filet déterministe |
|---|---|---|---|---|---|
| S1 | `PaymentInstigationGuard::check()` | `src/Application/LLM/PaymentInstigationGuard.php:108-152` | Si un brouillon sortant **introduit** un sujet d'infrastructure de paiement avant l'adversaire. `gpt-4o-mini`, `TEMPERATURE = 0.0`, `MAX_TOKENS = 30` (`:50-53`). Verdicts : `YES_PERSONA_INSTIGATES` / `NO_OPERATOR_ALREADY_MENTIONED` / `NO_OUTBOUND_DOES_NOT_MENTION_PAYMENT` (`:423-427`) | **Oui, conditionnel au contenu.** `failureVerdict()` (`:162-179`) : si `containsPaymentInfraTokens()` (D17, 12 regex) → **échec fermé** (blocage) ; sinon **échec ouvert** (approbation). Déclenché sur `\Throwable` (`:131-133`) et sur verdict illisible (`:137-139`). Court-circuite aussi à l'approbation quand il n'y a aucun corps entrant (`:115-117`) | Un brouillon qui instigue un paiement avec un vocabulaire **hors des 12 jetons de repli**, pendant une panne LLM, est approuvé |
| S2 | `PaymentInstigationGuard::isPaymentTopicAnchored()` | `PaymentInstigationGuard.php:213-261` | Si l'adversaire a déjà abordé le paiement — alimente les objectifs de prompt **et le saut du `check()` par tentative** (`RetryCoordinator.php:99-103`, `:185-187`) | **Oui : échec fermé** (retourne `false`) sur `\Throwable` (`:233-240`) et verdict illisible (`:255-260`). Docblock : « Failure semantics are the OPPOSITE of check() » (`:207-211`) | **Quand il retourne `true`, le `check()` par tentative — celui qui porte le repli déterministe — est entièrement sauté** pour cette génération (`RetryCoordinator.php:185-187`) |
| S3 | `OperationalLeakageDetector::check()` | `src/Application/LLM/OperationalLeakageDetector.php:45-102` | Si la réponse divulgue de l'information opérationnelle, **y compris par paraphrase** (« the orchestrator », « the platform that runs me »). `gpt-4o-mini`, `T=0.0`, 200 jetons (`:28-31`). Étage 3 (`RetryCoordinator.php:221-248`) | **Échec ouvert, sans nouvelle vérification.** Retourne `LeakageDetectionResult(false)` sur exception LLM (`:59-66`), échec d'analyse JSON (`:70-79`), champ `leak` manquant ou non booléen (`:83-89`). Docblock : « The hard gate is the PolicyGuard regex deny-list … it MUST fail open » (`:21-24`) | **La classe de paraphrase qu'il cible — tout ce qui n'est pas dans les 6 `FORBIDDEN_PATTERNS` + 10 `OPERATIONAL_LEAKAGE_PATTERNS` — n'a aucun filet déterministe** |
| S4 | `ReplyValidator::validateMultiCriteria()` | `src/Application/LLM/ReplyValidator.php:69-149` | Qualité multicritère **et porte de sécurité** : `naturalness`, `persona_fit`, `ti_value`, `security_pass`. `T=0.4`, 500 jetons (`:95-99`). Rejet si `security_pass=false`, ou `naturalness < 2`, ou moyenne `< 2.5` (`:19-21`) | **Aucun repli dans la classe** — lève `RuntimeException` sur JSON invalide (`:141-148`) et sur champ `approved` manquant (`:168-170`). L'appelant traite : `RetryCoordinator.php:260-276` capture, enregistre la tentative, et à la dernière tentative n'utilise le meilleur-sur-3 **que si** une tentative antérieure avait `security_pass === true`, sinon repli figé (`:266-268`) | **Le drapeau d'éligibilité du meilleur-sur-3 (`security_pass`) est lui-même produit par le LLM** (`RetryCoordinator.php:364-366`) ; aucune revérification déterministe n'est exécutée sur le texte retenu au-delà du passage PolicyGuard qu'il avait déjà |
| S5 | `PromptInjectionLlmAnalyzer::analyze()` — couche 2 | `src/Application/Communication/PromptInjectionLlmAnalyzer.php:73-97` | Classe les tentatives d'injection entrantes selon une taxonomie de 6 techniques (`:29-34`), retourne `risk_score`, `detected_techniques`, `confidence`, `summary`. `gpt-4o-mini`, `T=0.2` (`:57-58`), 1000 jetons ; **corps tronqué à 3000 caractères** (`:102-103`) ; scores bornés à `[0,1]` (`:140-143`) | **Oui : la couche 1 (D28–D36).** `PromptInjectionDetector::analyze()` capture l'exception et construit l'analyse à partir des seules correspondances de motifs, `modelVersion='pattern_matcher_only'`, `confidence = 0.7` si correspondances sinon `0.5` (`PromptInjectionDetector.php:61-69`, `:99-114`). Quand les deux couches tournent : `riskScore = max(patternScore, llmScore)` (`:94`) | **Rien ne s'appuie dessus** : tout le sous-système est forensique. `IngestPostProcessor::analyzePromptInjection()` persiste `injection_analysis` et, si `isHighRisk()` (`riskScore >= 0.7`, `PromptInjectionAnalysis.php:91-94`), émet un audit `INJECTION_DETECTED` **d'issue `'blocked'`** (`IngestPostProcessor.php:564-577`) — **l'ingestion et le pipeline de réponse se poursuivent néanmoins** |
| S6 | Porte d'arrêt du « director » (`ConversationAnalyzer`) | invoqué `ReplyHandler.php:75-119`, `:221-232` | Si la conversation est « brûlée » : si `!$brief->shouldContinue`, `ReplyHandler` **clôt la conversation** au lieu de répondre (`:223-231`) | **Oui, échec ouvert vers la poursuite.** Retourne `null` si l'analyseur n'est pas câblé, si `count($allMessages) < 2`, ou sur tout `\Throwable` (`:77-79`, `:84-86`, `:104-110`) : « null-safe: a missing analyzer, too few messages, or any failure returns null so replies are never blocked by this gate » (`:69-71`). Un brief malformé retombe sur `ConversationDirectorBrief::default()` (`ConversationAnalyzer.php:839`, `:946`) | **La décision de clore une conversation repose uniquement sur le brief LLM** |
| S7 | `IOCLikelihoodScorer::score()` | `src/Application/LLM/IOCLikelihoodScorer.php:23` (invoqué `RetryCoordinator.php:299`) | Note une réponse de 0 à 100 sur la probabilité d'extraction d'IOC. **Heuristique déterministe, pas un LLM** : `+25` question explicite, `+25` canal ciblé, `+15` référence au dernier message, `+10` types d'IOC manquants, `−20` action proactive, `−10` question répétée, `−15` langage générique (`:15-22`) ; table de mots-clés EN+FR (`:31-58`) | s.o. | Porte à `RetryCoordinator.php:313` : relance si `iocScore < $iocThreshold` (défaut `60`) **et** qu'il reste des tentatives ; **à la dernière tentative la réponse est acceptée quand même** |
| S8 | `ScamClassificationHandler` | `src/Application/Communication/ScamClassificationHandler.php` | Classification LLM du type d'arnaque, pilotant la sélection de persona et la politique de cycle de vie | Capture `:216`, `:293` ; repli sur persona générique (`:186`, `:304`) et type `UNKNOWN` (`:70`, `:371`, `:385`) | `UNKNOWN` renvoie à la politique de cycle de vie par défaut (`ConversationLifecycleConfig.php:54`) |
| S9 | `RewardJudge` (juge LLM, hors ligne) | `src/Application/Scambaiting/RewardJudge.php:24,38,64` | Juge l'issue réelle d'une conversation terminée, mêlée à la récompense mécanique (optimisation bandit) | **Oui** : `catch (\Throwable)` → « LLM outcome scoring failed, using mechanical reward » (`:96-97`) ; `catch (\JsonException)` (`:115`) | Alimente l'optimisation de personas, pas une décision de sécurité au moment de l'envoi |
| S10 | `EvalRunJudgeCommand` (juge LLM, hors ligne) | `src/UI/Console/EvalRunJudgeCommand.php:17-24`, `:113-137` | Harnais multi-juges : un modèle juge (`--model`, défaut `gpt-4o`) prédit des signaux sur un IOC rendu | `catch (\Throwable)` par élément (`:137`), `catch (\JsonException)` (`:283`) | Artefact d'évaluation seulement |

### 5.1 Le sous-système GUARD, de bout en bout

**Nature.** Porte de non-régression sur le pipeline **génératif** : il exécute le vrai
pipeline de réponse sur un jeu de fixtures figé, note chaque texte sortant produit avec
l'oracle déterministe `SafetyInvariantOracle` (D18–D26), et compare les taux obtenus à un
baseline gelé et sommé [VÉRIFIÉ].

| Étage | Fichier:ligne | Comportement |
|---|---|---|
| Exécution du smoke | `scambuster:smoke:reply-objective --summary-json=…` (`Makefile:226,233`) ; variante en processus `src/Infrastructure/Guard/InProcessSmokeRunner.php:27-63` | Produit un JSON de synthèse `fixtures` (avec `language` + `out_texts`) et `aggregate`. Chemin de synthèse unique par invocation `summary-{8 octets aléatoires}.json` pour qu'« a leftover summary from a previous job can never be mistaken for this run's output » (`:29-33`) ; lève si le fichier est absent (`:42-44`) ; `@unlink` en `finally` (`:60-62`). Exécution dans le même processus PHP pour qu'un candidat `EphemeralPromptOverride` soit visible (`:12-16`) |
| Notation | `src/Application/Guard/CanaryAggregate.php:29-83` | Pour chaque `out_texts`, `oracle->violations($text, $fixtureLanguage)` ; émet `violation_rates[code]` pour les 7 codes, plus `metrics{approved_rate, fallback_rate, attempts_avg}` et `meta{recording_slots, runs, errors, out_texts_scored, oracle_fingerprint}`. « Volatile out-texts are dropped; only rates survive » (`:13-15`) |
| Gel du baseline | `src/UI/Console/GuardBaselineCommand.php:28-115` | Écrit le JSON plus un compagnon `.sha256` (`:99-100`). « regenerating it is an explicit, reviewed commit — the gate never auto-updates it » (`:21-22`) |
| **Baseline en vigueur** | `backend-symfony/tests/Smoke/guard-baseline.json` | `approved_rate 1.0` ; `fallback_rate 0.0` ; `attempts_avg 1.8941176470588235` ; taux de violation : **`payment_token 0.294`**, `language_mismatch 0.0353`, tous les autres `0.0` ; `meta` : `recording_slots 85`, `runs 85`, `errors 0`, `out_texts_scored 85`, `oracle_fingerprint "374f95367add"`. SHA256 `336a3f06d6d5…4e9162` |
| Chargement + intégrité | `src/Application/Guard/CanaryBaselineProvider.php:31-85` | `CanaryBaselineException` si baseline absent / illisible / JSON invalide / mal formé, et si le `.sha256` ne correspond pas (`:83`). **Un compagnon `.sha256` manquant est toléré** (`:69-71`) |
| Comparaison | `src/Application/Guard/CanaryBaselineComparator.php:48-147` | Dans l'ordre : (1) empreinte d'oracle différente → non ok (`:54-66`) ; (2) intégrité de la preuve : échec fermé si `out_texts_scored === 0`, ou `errors > 0`, ou `scored < ceil(runs * MIN_SCORED_RATIO)` avec `MIN_SCORED_RATIO = 0.5` (`:40`, `:72-93`) ; (3) **`fallback_rate` bilatéral** : `abs(delta) > TOLERANCE` (`TOLERANCE = 0.05`) — une hausse = « pipeline quality degraded », une baisse = « possible weakened guard letting content through » (`:107-109`) ; (4) chaque code de violation surveillé : baseline nul → signalé sur tout candidat non nul ; baseline non nul → signalé si `delta > 0.05`. **Les codes de `INFORMATIONAL_CODES` (actuellement `payment_token` seul) sont ignorés** (`:116-119`) |
| Porte de fusion CLI | `src/UI/Console/GuardCheckCommand.php:32-158` | Rejette une synthèse sans `fixtures`/`aggregate` (`:65-69`) ; affiche un tableau `signal / baseline / candidate / delta / reason` ; `return $verdict['ok'] ? SUCCESS : FAILURE` (`:83`) |
| Ouvrier asynchrone | `src/UI/Console/GuardCanaryWorkCommand.php:32-93` | Un job en attente par invocation. `failStale(new \DateTimeImmutable('-90 minutes'))` d'abord (`STALE_TIMEOUT_MINUTES = 90`, `:38` — « A legitimate full run takes ~35 min »), puis `claimOldestPending()`. **Charge et vérifie l'intégrité du baseline en premier** pour qu'« a broken trust anchor must fail in milliseconds — never after the ~35-min paid run » (`:71-75`) |
| Précondition de disponibilité | `src/Application/Guard/CanaryAvailability.php:25-69` | `isConfigured()` : `mock` → faux ; `ollama` → vrai ; `anthropic` → exige `ANTHROPIC_API_KEY` ; défaut → exige `LLM_API_KEY`. Les clés contenant `your-api-key`, `your-key-here`, `not-needed`, `changeme` sont tenues pour inutilisables (`:33`). « It is a necessary, not sufficient, signal: it cannot see whether the canary-worker process is actually running » (`:21-22`) |
| CI hebdomadaire | `.github/workflows/guard-nightly.yml` | Voir §9.6. La CI par PR n'exécute que les contrôles **hors ligne** de GUARD (verrou d'empreinte, tests de dérive, comparateur) via la suite unitaire ; le canari sur LLM réel coûte « ~$0.14, ~35 min » (`:4-7`) |

**Sur échec** : `guard:check` sort en non-zéro (job CI en échec) ; l'ouvrier asynchrone
enregistre un verdict `REGRESSION` (`GuardCanaryWorkCommand.php:83`) ou `markFailed()`
(`:85`). Un ouvrier planté laisse le job en `RUNNING` ; le balayage `failStale` de
l'invocation suivante le termine (`:26-27`, `:54-57`) [VÉRIFIÉ].

[DÉDUIT] **Le taux `payment_token` du baseline est de 0,294 et ce code est classé
informationnel, donc non bloquant.** Conséquence lisible dans le code : environ 29 % des
textes sortants de référence contiennent du vocabulaire d'infrastructure de paiement, et
une augmentation de ce taux ne peut pas faire échouer la porte. Raisonnement :
`guard-baseline.json` (`payment_token 0.29411764705882354`) croisé avec
`SafetyInvariantOracle.php:70-72` (`INFORMATIONAL_CODES`) et
`CanaryBaselineComparator.php:116-119` (saut explicite).


---

## 6. Secrets

### 6.1 Tableau maître

| Secret | Lu depuis | Fichier:ligne de la lecture | Forme | Consommateur |
|---|---|---|---|---|
| `APP_SECRET` | variable d'env. en clair | `config/services.yaml` (liaison `SmtpDsnEncryptor`) ; constructeur `src/Application/Communication/Smtp/SmtpDsnEncryptor.php:33` | env en clair ; **utilisé comme entrée de dérivation de clé** | Symfony (cookies/CSRF) **+ dérivation de la clé de chiffrement des DSN SMTP** (`SmtpDsnEncryptor.php:43`) |
| `LLM_API_KEY` | env en clair | `config/services.yaml:139`, `:531` ; `config/packages/llm.yaml:6` | env en clair | `OpenAIService`, `CanaryAvailability` |
| `ANTHROPIC_API_KEY` | env en clair, défaut de conteneur | `config/services.yaml:531-533` | env en clair | `src/Application/Guard/CanaryAvailability.php:19` |
| `JWT_PASSPHRASE` | env en clair | `config/packages/lexik_jwt_authentication.yaml:5` | env en clair ; protège une clé privée RSA sur disque | LexikJWTAuthenticationBundle |
| Paire de clés JWT | fichiers PEM dans `backend-symfony/config/jwt/` | `scripts/generate-jwt-keys.sh:12,27,32,35-36` | clé privée chiffrée par `JWT_PASSPHRASE` ; `chmod 600` à la génération | LexikJWT |
| Rotation JWT | mêmes fichiers + sauvegarde horodatée | `scripts/rotate-jwt-keys.sh:18,19,44,48,51-52` | **`chmod 644` sur la clé privée après rotation**, contre `600` à la génération | LexikJWT |
| `AUDIT_HMAC_KEY` | env en clair (64 hex) | `config/services.yaml:511` → `$hmacKeyHex` ; documenté `src/Application/Audit/AuditHmacChainer.php:19` | env en clair, clé HMAC brute | `AuditHmacChainer`. Clé absente → WARNING + chaîne désactivée en dev/test (`:50`), exception en prod (`:44`) |
| `TOTP_ENCRYPTION_KEY` | env en clair, lue directement dans `$_ENV`/`$_SERVER` | `src/Infrastructure/Doctrine/Type/EncryptedStringType.php:97` | env en clair (64 hex = 32 octets, validé `:99`) | Type Doctrine `encrypted_string` |
| Secrets TOTP utilisateurs | colonne `app_users.totp_secret` | `src/Domain/User/User.php:47-48` | **chiffré au repos** : libsodium `crypto_secretbox` (XSalsa20-Poly1305), stockage `nonce‖chiffré` en BYTEA (`EncryptedStringType.php:15-17,54-57`) | `DoctrineUserTotpChecker` |
| `TAXII_API_KEY` | env en clair, défaut vide | `config/services.yaml:69` | env en clair ; chaîne vide = fonction désactivée | `App\Security\TaxiiApiKeyAuthenticator` ; `config/packages/security.yaml:34` |
| `OIDC_CLIENT_SECRET` | env en clair | `config/services.yaml:250` | env en clair | client OIDC |
| `DATABASE_URL` (contient le mot de passe) | env en clair | `config/packages/doctrine.yaml:14` ; lecture brute `src/UI/Console/PreprodClearConversationsCommand.php:42` et `infra/docker/backend/scheduler.sh:105` | env en clair (DSN avec identifiants intégrés) | Doctrine DBAL ; `pg_dump` |
| `POSTGRES_PASSWORD` | env en clair | `.env.dist:49` | env en clair | conteneur PostgreSQL |
| `MISP_URL` / `MISP_API_KEY` / `MISP_VERIFY_SSL` | env en clair, lues dans `$_ENV` | `src/UI/Console/MispTestCommand.php:33,35,59` | env en clair | `scambuster:misp:test` |
| `SIEM_PROVIDER` / `SIEM_ENDPOINT` / `SIEM_FORMAT` | env en clair, lues dans `$_ENV` | `src/Infrastructure/Siem/SiemCompilerPass.php:51,54,67` | env en clair (l'endpoint peut porter des identifiants dans l'URL) | exporteurs Syslog/File/Null |
| `LOGIN_HASH_SALT` | env en clair, lue dans `$_ENV` | `src/Application/Auth/LoginHashGenerator.php:14` | env en clair | `LoginHashGenerator` |
| `N8N_ENCRYPTION_KEY` | env en clair consommée par le conteneur n8n | `docker-compose.yml:211-214` (commentaire), `.env.dist:173` | env en clair ; n8n chiffre son propre magasin d'identifiants avec | n8n |
| **Identifiants IMAP/SMTP d'engagement** | magasin d'identifiants interne de n8n sur disque | `docker-compose.yml:248` (`./data/n8n:/home/node/.n8n`), commentaire `:206-214` | chiffrés au repos par n8n via `N8N_ENCRYPTION_KEY` | workflow d'ingestion IMAP |
| `N8N_DEFAULT_USER_PASSWORD` / `_EMAIL` | env en clair | `.env.dist:178`, `:177` | env en clair | `n8n/n8n-init.sh` |
| `HONEYPOT_IMAP_*` | env en clair | `.env.dist:190-194` | env en clair | ingestion IMAP (côté n8n) |
| `INGEST_LOGIN` / `INGEST_PASSWORD` | env en clair | `.env.dist:128`, `:132` | env en clair | auth basique `/api/v1/ingest` |
| `RSPAMD_PASSWORD` | env en clair | `.env.dist:124` | env en clair | contrôleur rspamd |
| `MAILER_DSN` | env en clair (repli SMTP global) | `.env.dist:232` | env en clair | Symfony Mailer ; repli quand un `MailAccount` n'a pas de DSN propre (`SmtpTransportResolver.php:55`) |
| DSN SMTP par compte | colonne `mail_account.smtp_dsn_encrypted` | `src/Domain/Communication/MailAccount.php:29-30` | **chiffré au repos** (§6.2) | `SmtpTransportResolver`, `MailAccountManager` |

### 6.2 Chiffrement des identifiants de compte mail — mécanique exacte

**Les identifiants IMAP ne sont pas stockés par l'application Symfony.** La table
`mail_account` n'a aucune colonne de mot de passe IMAP [VÉRIFIÉ] `MailAccount.php:16-31` ;
DDL `migrations/Version20250517162705.php:119`. Ce qui est stocké :

| Champ | Fichier:ligne | Contenu |
|---|---|---|
| `endpoint` | `MailAccount.php:20-21` | hôte IMAP/SMTP, en clair |
| `login_hash` | `MailAccount.php:21-22` | empreinte du login, salée par `LOGIN_HASH_SALT` (`LoginHashGenerator.php:14`) |
| `oauth_scopes` | `MailAccount.php:22-23` | JSON, en clair |
| `email_address` | `MailAccount.php:28-29` | adresse de boîte, en clair |
| `smtp_dsn_encrypted` | `MailAccount.php:29-30` | base64 de `nonce‖chiffré` — **seul champ chiffré** |

Chiffrement au niveau champ, implémenté dans
`src/Application/Communication/Smtp/SmtpDsnEncryptor.php` [VÉRIFIÉ] :
- Algorithme XSalsa20-Poly1305 via `sodium_crypto_secretbox` (`:10`, `:58`).
- Format `base64(nonce ‖ chiffré)`, nonce aléatoire de 24 octets (`:12`, `:57`, `:60`).
- **Source de clé : `APP_SECRET`**, passé au constructeur puis
  `sodium_crypto_generichash($appSecret, '', 32)` (`:14-15`, `:43`). Longueur minimale
  imposée pour `APP_SECRET` : **12 caractères** (`:29`, `:35`).
- Aucun repli au déchiffrement : tout échec lève `RuntimeException` (`:66-69,74,80,84,93`).
- Docblock du code lui-même (`:22-23`) : « changing `APP_SECRET` makes all existing
  encrypted DSNs unreadable. Future spec will provide a key rotation procedure. »

Écriture : `src/Application/Communication/MailAccountManager.php:70` et `:138`.
Lecture : `SmtpTransportResolver.php:55`.

Second mécanisme de chiffrement au niveau champ, indépendant : `EncryptedStringType`
(type Doctrine, clé depuis `TOTP_ENCRYPTION_KEY`, `EncryptedStringType.php:95-107`),
appliqué uniquement à `User::$totpSecret` (`User.php:47`) [VÉRIFIÉ].

[DÉDUIT] Deux mécanismes de chiffrement de champ coexistent avec **deux sources de clé
distinctes** (`APP_SECRET` dérivé vs `TOTP_ENCRYPTION_KEY` direct) et deux points
d'intégration différents (service applicatif vs type DBAL). Raisonnement : lecture
comparée de `SmtpDsnEncryptor.php:43` et `EncryptedStringType.php:97`.

### 6.3 `check-no-vault-resurrection.sh`

`.github/scripts/check-no-vault-resurrection.sh` interdit, sous `backend-symfony/src/` :
`VaultClient`, `MailAccountSecretResolver`, `VaultAddImapSecret`, `VaultDeleteImapSecret`,
`MailAccountOnboardCommand`, `hashicorp/vault` (`:15`) ; `VAULT_TOKEN`, `VAULT_ADDR` (`:35`).
Les deux contrôles font `exit 1` (`:30`, `:38`) [VÉRIFIÉ].

Motif déclaré par le script lui-même, cité verbatim :
> `# Prevent re-introduction of Vault dead code.` (`:2`)
> `# Rationale: Vault was removed (April 2026) because it was dead code since the
> 2026-03-31 n8n migration (commit b090e31). n8n now stores its own IMAP credentials
> encrypted with N8N_ENCRYPTION_KEY in ./data/n8n/.` (`:5-8`)
> `"The n8n IMAP intake holds the production credentials now."` (`:22`)

Même motif répété dans `docker-compose.yml:205-214` [VÉRIFIÉ].

### 6.4 `SecretPolicy.php`

`src/Security/SecretPolicy.php`, classe finale sans dépendance [VÉRIFIÉ] :
- **`PUBLISHED_DEFAULTS`** (`:28-35`) — six valeurs littérales : `APP_SECRET=a1b2c3d4…`,
  `TOTP_ENCRYPTION_KEY=`64×`a`, `AUDIT_HMAC_KEY=`64×`b`,
  `N8N_ENCRYPTION_KEY=dev-only-change-in-production-openssl-rand-hex-32`,
  `N8N_DEFAULT_USER_PASSWORD=Scambuster2026!`, `ADMIN_PASSWORD=Un1que$trongPassword2024`.
- **`PLACEHOLDER_MARKERS`** (`:43-53`) — neuf sous-chaînes insensibles à la casse :
  `dev-only-change`, `change-in-production`, `changeme`, `change-me`, `changthis`,
  `change-this`, `placeholder`, `example`, `insecure`.
- `validate(array $secrets, bool $isProd)` (`:63`) — **retourne `[]` immédiatement si
  `$isProd` est faux** (`:67-69`). Ignore les valeurs `null` (`:75-77`).
- `reasonFor(string $value)` (`:93`) rejette dans l'ordre : chaîne vide (`:95`) ;
  correspondance `hash_equals` avec **l'un quelconque** des six défauts, y compris
  d'une autre variable (`:99-105`) ; valeur composée d'un seul caractère répété via
  `strspn` (`:107-110`) ; présence d'un marqueur de gabarit (`:114-118`) ; préfixe
  `your-`/`your_` (`:120-122`).
- Intention déclarée (`:8-18`) : « .env.dist ships valid-but-globally-known keys so the
  stack boots out of the box… It only *strengthens* posture and never enforces outside
  production, so dev/test/e2e keep booting on defaults. »

### 6.5 `CheckSecretsCommand.php`

`src/UI/Console/CheckSecretsCommand.php`, commande `app:security:check-secrets` (`:27`) [VÉRIFIÉ] :
- **`CHECKED`** (`:37-45`) — **sept** variables : `APP_SECRET`, `TOTP_ENCRYPTION_KEY`,
  `AUDIT_HMAC_KEY`, `JWT_PASSPHRASE`, `N8N_ENCRYPTION_KEY`, `N8N_DEFAULT_USER_PASSWORD`,
  `ADMIN_PASSWORD`.
- `$isProd = ($appEnv === 'prod')` depuis `%kernel.environment%` (`:48-49`, `:58`).
- `readEnv()` (`:91-104`) : `$_SERVER` → `$_ENV` → `getenv()` ; une chaîne vide explicite
  est conservée pour que la politique la signale (`:88-89`).
- Violations → « Refusing to boot: insecure secret values detected. » + `Command::FAILURE`
  (`:76-83`). Docblock : l'entrypoint prod l'exécute après matérialisation de
  l'environnement et avant les migrations (`:19-21`).

**Couverture** [VÉRIFIÉ] : 7 variables contrôlées sur les 24 variables d'apparence
secrète listées en §6.6. `POSTGRES_PASSWORD`, `LOGIN_HASH_SALT`, `RSPAMD_PASSWORD`,
`INGEST_PASSWORD`, `HONEYPOT_IMAP_PASSWORD`, `LLM_API_KEY`, `OIDC_CLIENT_SECRET`,
`TAXII_API_KEY`, `MISP_API_KEY` ne sont pas dans `CHECKED`.

### 6.6 Variables d'apparence secrète dans `.env.dist`

| Ligne | Variable | Valeur par défaut | Nature |
|---|---|---|---|
| 39 | `APP_SECRET` | `a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4` | vraisemblable (32 hex) ; dans `PUBLISHED_DEFAULTS` |
| 49 | `POSTGRES_PASSWORD` | `postgres` | vraisemblable ; **ni dans `PUBLISHED_DEFAULTS` ni dans `CHECKED`** |
| 67 | `JWT_PASSPHRASE` | `scambuster-jwt-dev-passphrase-2026` | vraisemblable ; dans `CHECKED`, **pas dans `PUBLISHED_DEFAULTS`** |
| 81 | `OIDC_CLIENT_SECRET` | *(vide)* | vide |
| 117 | `LOGIN_HASH_SALT` | `scambuster-salt-dev-2026` | vraisemblable ; pas dans `CHECKED` |
| 124 | `RSPAMD_PASSWORD` | `rspamd` | vraisemblable ; pas dans `CHECKED` |
| 128 | `INGEST_LOGIN` | `user@example.com` | gabarit (`example`) |
| 132 | `INGEST_PASSWORD` | `Un1que$$trongPassword2024` | vraisemblable ; `$$` est l'échappement Compose de `$`, donc la valeur effective **égale le défaut `ADMIN_PASSWORD`** de `PUBLISHED_DEFAULTS` ; `INGEST_PASSWORD` n'est pas dans `CHECKED` |
| 173 | `N8N_ENCRYPTION_KEY` | `dev-only-change-in-production-openssl-rand-hex-32` | gabarit ; dans `PUBLISHED_DEFAULTS` et `CHECKED` |
| 178 | `N8N_DEFAULT_USER_PASSWORD` | `Scambuster2026!` | vraisemblable ; dans les deux |
| 193 | `HONEYPOT_IMAP_PASSWORD` | `your-app-password-here` | gabarit (`your-`) ; pas dans `CHECKED` |
| 232 | `MAILER_DSN` | `null://null` | transport neutre |
| 308 | `LLM_API_KEY` | `sk-your-api-key-here` | gabarit ; pas dans `CHECKED` |
| 352 | `TOTP_ENCRYPTION_KEY` | 64 × `a` | format vraisemblable ; dans les deux |
| 360 | `AUDIT_HMAC_KEY` | 64 × `b` | format vraisemblable ; dans les deux |
| 396 | `TAXII_API_KEY` | *(vide)* | vide (= fonction désactivée, `services.yaml:67`) |
| 310, 407, 412 | `ANTHROPIC_API_KEY`, `MISP_API_KEY` (commentées) | `sk-ant-…`, `your-misp-api-key` | gabarits |

`ADMIN_PASSWORD` figure dans `SecretPolicy::PUBLISHED_DEFAULTS:34` et
`CheckSecretsCommand::CHECKED:44` mais **n'a aucune ligne d'affectation dans `.env.dist`**
[VÉRIFIÉ].

---

## 7. Persistance, modèle de données, données personnelles

### 7.1 Entités Doctrine par contexte

**Communication** (`src/Domain/Communication/`) : `Conversation`→`conversation` (`:9-10`),
`Message`→`message`, `Attachment`→`attachment` (`:9-10`), `ObservedIoc`→`observed_ioc`,
`MessageVector`→`message_vector` (`:9-10`), `MailAccount`→`mail_account` (`:9-10`),
`Persona`→`persona` (`:10`), `ScamType`→`lkp_scam_type` (`:12`), `Channel`→`lkp_channel`
(`:10`), `Direction`→`lkp_direction` (`:10`), `ConversationChannel` (`:10`),
`Ttp`→`lkp_ttp` (`:10`), `TtpObservation`→`ttp_observation` (`:10`).

**CampaignRadar** : `Campaign`→`campaign` (`:13`), `CampaignRule` (`:13`),
`MessageCampaign` (`:12`), `ActorProfile`→`actor_profile` (`:10-11`).

**Audit** : `AuditLog`→`audit_log` (`AuditLog.php:18`), docblock « Immutable after
creation (append-only log) » (`:16`).
**LLM** : `LlmUsageRecord`→`llm_usage` (`:18`).
**Prompt** : `PromptOverride` (`:10-16`), `PromptCanaryJob` (`:10-16`).
**Scambaiting** : `BanditConvergenceLog` (`:10`).
**User** : `User`→`app_users` (`:16`), `RefreshToken` (`:10-16`, jeton brut jamais
persisté, `:13`).
**Infrastructure** : `PersonaPerformanceStatsEntity`→`persona_performance_stats` (`:20`).

**Tables gérées en SQL brut, hors Doctrine** [VÉRIFIÉ] :
`indicator` (`migrations/Version20260307190000.php:25-39` ; la migration indique
elle-même « non-Doctrine managed, used by IocHandler via raw SQL » `:11,:19` ; écriture
`src/Application/Communication/IocUpsertService.php:294-315`) ;
`threat_actor_psych_profile` (`migrations/Version2026070600000000.php:28-45`) ;
`ioc_context` (`Version20260405120000.php:20`) ; `ioc_analyst_feedback`
(`Version2026070600100000.php:27`) ; `threat_actor_cluster` /
`threat_actor_cluster_ioc` (`Version20260409100000.php:75-85`).

`PromptInjectionAnalysis` n'est **pas** une entité : objet-valeur readonly
(`src/Domain/Communication/PromptInjectionAnalysis.php:14`) sérialisé dans le JSON
`message.injection_analysis` (`Message.php:20-21`) [VÉRIFIÉ].

### 7.2 Champs contenant des données personnelles de tiers

| Entité / table | Champ | Fichier:ligne | Contenu |
|---|---|---|---|
| `Message` | `bodyText` (NOT NULL) | `Message.php:57-58` | corps complet du courriel de l'adversaire |
| `Message` | `bodyHtml` | `Message.php:59-60` | corps HTML complet |
| `Message` | `subject` | `Message.php:55-56` | texte libre, peut contenir des noms |
| `Message` | `headers` (JSON, NOT NULL) | `Message.php:61-62` | From/To/Cc, Message-ID, chaîne `Received` → **adresses, noms affichés, adresses IP d'origine** |
| `Message` | **`rawSource`** | `Message.php:23-24` ; écrit `IngestHandler.php:180` | **source RFC822 complète en base64, verbatim** |
| `Message` | `externalMessageId` | `Message.php:26-27` | identifiant fournisseur |
| `Message` | `urlAnalysis` (JSON) | `Message.php:16-17` | URL extraites |
| `Message` | `injectionAnalysis` (JSON) | `Message.php:20-21` | constats d'injection, contient des extraits du texte |
| `Attachment` | `filename` | `Attachment.php:27-28` | noms de fichiers |
| `Attachment` | **`ocrText`** | `Attachment.php:41-42` | **texte OCR des pièces jointes** — noms, IBAN, pièces d'identité, téléphones |
| `Attachment` | `metadata` (JSON) | `Attachment.php:16-18` | métadonnées libres |
| `ObservedIoc` | `context` (`context_observation`, JSON) | `ObservedIoc.php:28-29` | texte environnant l'IOC |
| **`indicator`** (SQL brut) | `value`, `value_norm` | `IocUpsertService.php:294-307` ; DDL `Version20260307190000.php:25-39` | **les valeurs d'IOC elles-mêmes : adresses email, IP, téléphones, IBAN, URL, adresses de portefeuille** |
| `indicator` | `enrichment` (JSON) | `IocUpsertService.php:296,310` | charge d'enrichissement |
| `MessageVector` | `embedding` (JSON) | `MessageVector.php:18-19` | vecteur dérivé du contenu du message |
| `TtpObservation` | extrait de preuve verbatim + offsets | entité `TtpObservation.php:10` ; décrit `docs/09_dpia_template.md:38` | **extraits verbatim de courriels entrants** |
| `ActorProfile` | `styleDna` (JSON) | `ActorProfile.php:33-34` | empreinte stylométrique d'un tiers |
| `ActorProfile` | `infraDna` (JSON) | `ActorProfile.php:35-36` | empreinte d'infrastructure (domaines, IP) |
| `Campaign` | `actorGuess` (TEXT) | `Campaign.php:26` | hypothèse d'identité d'acteur |
| `Campaign` | `profileYaml` (TEXT) | `Campaign.php:48` | document de profil |
| **`threat_actor_psych_profile`** (SQL brut) | `behavioural_summary`, `victim_targeting`, `dominant_lever`, `secondary_levers`, `escalation_pattern`, `dominant_stimulus` | `Version2026070600000000.php:29-36` | **profil psychologique d'un tiers généré par LLM**, plus description du ciblage de victimes |
| `AuditLog` | `ipAddress` (VARCHAR 45) | `AuditLog.php:61` | **adresse IP** de l'acteur |
| `AuditLog` | `actorId`, `resourceId`, `details` (JSON) | `AuditLog.php:49,59,55` | identité d'acteur, de ressource, charge d'événement libre |
| `MailAccount` | `emailAddress`, `endpoint` | `MailAccount.php:28-29,20-21` | boîte opérateur, en clair |
| `User` | `email` / `passwordHash` / `totpSecret` | `User.php:29-30 / 33-34 / 47-48` | opérateur (1ʳᵉ partie) / haché / chiffré |

### 7.3 Stockage des pièces jointes

**Le contenu binaire des pièces jointes n'est pas stocké par l'application Symfony**
[VÉRIFIÉ]. La table `attachment` n'a pas de colonne blob (DDL
`migrations/Version20250517162705.php:29`). L'entité porte `s3Key` (`:35-36`, mutateur
`:137-140`) et `encKeyId` (`:37-38`, mutateur `:142-145`) ; une recherche sur `setS3Key`
/ `s3_key` ne renvoie **que la déclaration et le mutateur eux-mêmes** — aucun appelant
dans `src/`. `AttachmentHandler.php` ne contient ni `s3Key`, ni `S3`, ni `filesystem`,
ni `file_put_contents`, ni `Storage`.

Persisté par pièce jointe : `filename`, `mime_type`, `size_bytes`, `content_hash`
(BYTEA), `ocr_text`, `metadata` JSON, `vector_id`. [DÉDUIT] Le seul contenu de pièce
jointe présent en base est `ocr_text`, **plus les octets de la pièce jointe eux-mêmes
inclus dans `message.raw_source`**, puisque la source RFC822 est stockée entière
(`IngestHandler.php:180`, extraction `:198`). Raisonnement : le RFC822 complet inclut les
parties MIME encodées en base64.

Aucun volume de pièces jointes n'est déclaré dans `docker-compose.yml` [VÉRIFIÉ].

### 7.4 Fichiers `.eml`

1. **En base** : oui, `message.raw_source` (TEXT nullable, base64 RFC822),
   `Message.php:23-24`, écrit `IngestHandler.php:180`.
2. **Sur disque dans le dépôt** : 99 fichiers `.eml`, tous des fixtures de test
   [VÉRIFIÉ] :

| Répertoire | Nombre | Nature |
|---|---|---|
| `backend-symfony/tests/Smoke/CialdiniMirrorFixtures/` | 34 | courriels nommés par levier de persuasion × langue (`01_Authority_director_EN.eml`, `12_Secrecy_between_us_FR.eml`, `26_Urgency_deadline_4h_DE.eml`, `31_Secrecy_between_us_ES.eml`) — utilisés par `CialdiniMirrorSmokeCommand.php` |
| `backend-symfony/tests/Smoke/ReplyObjectiveFixtures/` | 65 | fixtures de `ReplyObjectiveSmokeCommand.php` |

Aucun corpus de production `.eml` n'est présent dans le dépôt [VÉRIFIÉ].

### 7.5 Embeddings / vecteurs

| Aspect | Valeur | Preuve |
|---|---|---|
| Table | `message_vector` | `migrations/Version20250518105021.php:21` |
| Type de stockage | `embedding JSON NOT NULL` — colonne JSON PostgreSQL ordinaire | même ligne ; `MessageVector.php:18-19` |
| Dimension | non fixée par le schéma — `dim INT NOT NULL` par ligne | mêmes lignes |
| **pgvector** | **non utilisé** : aucun `CREATE EXTENSION vector`, aucun type `vector(n)`, aucun index `ivfflat`/`hnsw` dans les 65 migrations | recherche sur l'ensemble de `migrations/` |
| Clé étrangère | **aucune**. `message_vector.vector_id` est une simple clé primaire UUID ; `message.vector_id` et `attachment.vector_id` sont des UUID nullables **sans contrainte FK** | `Version20250518105021.php:21` ; `Version20250517162705.php:134,29` |
| Génération | `app:generate-embeddings`, planifiée | `scheduler.sh:62` |

### 7.6 Rétention réellement implémentée

#### `PurgeRgpdCommand` — `app:purge:rgpd` (`:14`)
33 lignes, **aucune option** (pas de `--dry-run`, pas de fenêtre configurable). Délègue à
`src/Application/Communication/PurgeService.php` [VÉRIFIÉ].

| Méthode | Critère | Action | Durée et emplacement |
|---|---|---|---|
| `softDeleteOldOutboundConversations()` (`PurgeService.php:23`) | `status = CLOSED` ET `tsLast < now-6months` ET `deletedAt IS NULL` (`:29-33`) | `softDelete()` (`:39`) → positionne `deleted_at`. **Aucun champ n'est vidé, aucun contenu écrasé ni anonymisé** | **En dur** `'-6 months'` (`PurgeService.php:25`) |
| `hardDeleteOldInboundConversations()` (`:53`) | `tsLast < now-12months` ET `deletedAt IS NOT NULL` (`:58-61`) | `remove()` (`:67`) + `flush()` (`:69`) → DELETE physique | **En dur** `'-12 months'` (`:55`) |

Portée de la cascade : `message.conv_id` `onDelete: CASCADE` (`Message.php:45`),
`attachment.msg_id` CASCADE (`Attachment.php:25`), `observed_ioc.msg_id` CASCADE
(`ObservedIoc.php:24`).

**`app:purge:rgpd` ne figure nulle part dans `infra/docker/backend/scheduler.sh`**
[VÉRIFIÉ] : ni dans la liste de tâches déclarée (`:16-24`), ni dans la boucle (`:40-147`).
Une recherche sur `purge:rgpd` dans le dépôt ne renvoie que `Makefile`, `CHANGELOG.md`
et la documentation.

#### `WeeklyCleanupCommand` — `app:cleanup:weekly` (`:17`)
142 lignes, SQL brut via DBAL. Options (`:31-35`) : `--conv-days` (défaut `90`),
`--llm-days` (`180`), `--canary-days` (`30`), `--dry-run` [VÉRIFIÉ].

| Étape | Table | Critère | Action | Durée |
|---|---|---|---|---|
| 1 (`:83-105`) | `conversation` | `status='closed' AND ts_last < seuil AND deleted_at IS NULL` (`:86-88`) | `UPDATE … SET deleted_at = NOW()` (`:96-99`) — suppression logique seule, **aucun contenu supprimé ni anonymisé** | option CLI, défaut **90 jours** (`:32`) |
| 2 (`:107-122`) | `llm_usage` | `created_at < seuil` (`:109`) | `DELETE` (`:116`) | défaut **180 jours** (`:33`) |
| 3 (`:128-141`) | `prompt_canary_job` | `status IN ('succeeded','failed') AND created_at < seuil` (`:130`) | `DELETE` (`:137`) ; `pending`/`running` jamais touchés (`:124-127`) | défaut **30 jours** (`:34`) |

**Automatisée** : `scheduler.sh:125-130` exécute `app:cleanup:weekly --no-interaction`
le dimanche ≥04:00 UTC, **sans options** — donc aux valeurs 90/180/30 [VÉRIFIÉ].

**Conséquence dans le code** [DÉDUIT] : l'exécution automatique hebdomadaire supprime
logiquement les conversations closes à **90 jours**, tandis que `app:purge:rgpd` — jamais
planifié — est la seule routine qui applique la suppression logique à 6 mois et **la
seule de tout le code source qui supprime physiquement du contenu de conversation**.
Raisonnement : comparaison de `WeeklyCleanupCommand.php:96-99` (UPDATE seul) et
`PurgeService.php:67` (`remove()`), croisée avec l'absence de `purge:rgpd` dans
`scheduler.sh`.

#### `CloseStaleConversationsCommand` — `app:close-stale-conversations` (`:30`)
178 lignes. **Ne supprime ni n'anonymise rien** : transitionne `status` de `OPEN` vers
clos via `ConversationClosureService::closeConversationsBatch()` (`:135`) [VÉRIFIÉ].
Critères de clôture (`shouldClose()`, `:152-177`) : inactivité > `timeout_hours` (`:157-162`),
`turnsCount >= max_turns` (`:165-167`), ancienneté > `max_duration_days` (`:170-173`).
Durées en **constantes PHP** : `src/Application/Communication/ConversationLifecycleConfig.php:22-55`
(`POLICIES`) et `:58` (`DEFAULT_POLICY` = 72 h / 25 tours / 14 j). Exemples : `ROMANCE`
336 h / 50 / 60 j (`:24`), `TECH_SUPPORT` 24 h / 20 / 5 j (`:43`), `PHISHING` 48 h / 15 / 7 j (`:40`).
Motif déclaré du choix (`:14-16`) : « Stored as PHP constants (not DB) because the number
of scam types is small and fixed. No CRUD needed. »
**Automatisée** : `scheduler.sh:47`, à chaque itération de boucle externe (~6 h).

### 7.7 Entités / champs qu'aucune routine de purge n'atteint

| Non purgé | Preuve |
|---|---|
| **Table `indicator`** (valeurs d'IOC : adresses email, IP, téléphones, IBAN, URL) | aucun `DELETE FROM indicator` ; hors d'atteinte de la cascade (pas de FK vers `message`/`conversation`) |
| **`message_vector`** (embeddings) | pas de FK vers `message` (`Version20250518105021.php:21`) → la cascade de suppression de conversation ne peut l'atteindre ; aucune routine ne l'efface |
| **`threat_actor_psych_profile`** (résumés comportementaux, ciblage de victimes) | FK vers `threat_actor_cluster` (`Version2026070600000000.php:49-52`), pas vers les conversations ; aucune purge temporelle |
| `threat_actor_cluster`, `threat_actor_cluster_ioc` | aucune routine |
| `actor_profile` (`style_dna`, `infra_dna`) | aucune routine |
| `campaign` (`actor_guess`, `profile_yaml`), `campaign_rule`, `message_campaign` | aucune routine |
| `ttp_observation` (extraits verbatim) | FK vers `message` ; atteint uniquement par la suppression physique de conversation, que seul `app:purge:rgpd` effectue et qui n'est pas planifié |
| `audit_log` (dont `ip_address`) | aucune routine ; `gdpr-record-of-processing.md:54` l'indique lui-même (« not auto-purged ») |
| `ioc_context`, `ioc_analyst_feedback` | aucune routine |
| `bandit_convergence_log`, `persona_performance_stats` | aucune routine |
| `mail_account` (dont `email_address`) | aucune routine ; désactivation seule (`MailAccount.php:71-74`) |
| `app_users`, `refresh_token` | aucune commande de purge temporelle trouvée |
| `message.raw_source`, `body_text`, `headers`, `attachment.ocr_text` **sur les lignes supprimées logiquement** | la suppression logique ne positionne que `deleted_at` (`PurgeService.php:39` ; `WeeklyCleanupCommand.php:96`) ; ces colonnes conservent leur contenu intégral jusqu'à une suppression physique |
| **Anonymisation** | **aucune routine d'anonymisation, de pseudonymisation ou de caviardage n'existe**. Les deux seules opérations d'écriture sont « positionner `deleted_at` » et « DELETE physique » |
| Sauvegardes PostgreSQL | `find /backups -name 'scambuster_*.sql.gz' -mtime +7 -delete` (`scheduler.sh:112`) — rotation à 7 jours de dumps complets, qui contiennent tout ce qui précède |

### 7.8 Rétention annoncée contre rétention implémentée

| Donnée | Annoncé (doc:ligne) | Implémenté (code:ligne) | Correspondance |
|---|---|---|---|
| Conversation — suppression logique | « 6 months soft-delete » — `docs/compliance/gdpr-record-of-processing.md:53` | **Deux valeurs coexistent** : `-6 months` (`PurgeService.php:25`, commande manuelle) et `90` jours (`WeeklyCleanupCommand.php:32`, la commande planifiée) | Deux implémentations, deux valeurs |
| Conversation — suppression physique | « → 12 months hard-delete » — `gdpr-record-of-processing.md:53` | `-12 months` (`PurgeService.php:55`) | Valeur conforme |
| Mécanisme | « `PurgeService` (`app:cleanup:weekly`, **automatic**) » — `gdpr-record-of-processing.md:53` | `app:cleanup:weekly` **n'utilise pas** `PurgeService` (SQL brut, `WeeklyCleanupCommand.php:23,95`) et n'effectue **aucune** suppression physique. `PurgeService` n'est atteint que par `app:purge:rgpd`, absent de `scheduler.sh` | Mécanisme différent de l'annonce |
| Contenu des courriels | « 6 months max, **then anonymization** » — `docs/09_dpia_template.md:33` | Aucun code d'anonymisation | Non implémenté |
| Contrôle de rétention | « PurgeService: **anonymization at 6 months**, hard delete at 12 months » — `docs/09_dpia_template.md:146` | `PurgeService.php:23-45` effectue une suppression **logique**, pas une anonymisation | Anonymisation non implémentée |
| Application | « enforced via automated purge service » — `docs/09_dpia_template.md:100` | Seul le nettoyage 90/180/30 est automatisé (`scheduler.sh:127`) | Partiel |
| Métadonnées de courriel | « aligned with email content retention » — `docs/09_dpia_template.md:35` | `message.headers`, `message.raw_source` ; supprimés uniquement par la commande non planifiée | Via la commande non planifiée |
| Métadonnées d'interaction LLM | « aligned with email content retention » — `docs/09_dpia_template.md:36` | `llm_usage` supprimé à **180 jours** (`WeeklyCleanupCommand.php:33,116`), indépendamment du cycle de vie des conversations | Règle indépendante |
| Extrait de preuve TTP | « aligned with email content retention » — `docs/09_dpia_template.md:38` | atteint uniquement par la suppression physique de conversation | Via la commande non planifiée |
| Code de technique TTP | « **indefinite** (intelligence value, as IOCs) » — `docs/09_dpia_template.md:38` | aucune purge | Conforme |
| Journal d'audit | « 12 months (policy) ; … **not auto-purged** » — `gdpr-record-of-processing.md:54` | aucune purge | Conforme (la doc annonce le caractère manuel) |
| Sauvegardes | « daily pg_dump (02:00 UTC), **7-day retention**, verification » — `docs/09_dpia_template.md:160` | `scheduler.sh:100-122` ; suppression `:112` ; vérification de taille `:109` | Conforme |
| **Valeurs d'IOC (`indicator`)** | aucune ligne de rétention dans l'un ou l'autre document | aucune purge | **Non couvert par la documentation** |
| **Embeddings (`message_vector`)** | aucune ligne de rétention | aucune purge, pas de FK | **Non couvert** |
| **Profils psychologiques** | aucune ligne de rétention | aucune purge | **Non couvert** |
| Jobs de canari de prompt | aucune ligne de rétention | supprimés à 30 jours (`WeeklyCleanupCommand.php:34,130`) | Implémenté, non documenté |

---

## 8. Journalisation

### 8.1 Composants du sous-système d'audit

| Composant | Chemin | Lignes |
|---|---|---|
| Entité | `src/Domain/Audit/AuditLog.php` | 207 |
| Taxonomie d'événements (enum) | `src/Domain/Audit/AuditEventType.php` | 95 |
| Écrivain | `src/Application/Audit/AuditLogger.php` | 177 |
| Chaîneur HMAC | `src/Application/Audit/AuditHmacChainer.php` | 89 |
| Commande de vérification | `src/UI/Console/VerifyAuditChainCommand.php` | 129 |
| Endpoint de lecture | `src/UI/Http/Monitoring/AuditController.php` | 93 |
| Migration (chaîne + reprise) | `migrations/Version2026041200100000.php` | 121 |
| Runbook | `docs/runbooks/audit-hmac-key-rotation.md` | 53 |
| Cartographie de sévérité SIEM | `src/Domain/Audit/SiemSeverityMap.php` | 135 |

### 8.2 Schéma de la table `audit_log`

Colonnes [VÉRIFIÉ] `AuditLog.php` : `id` (`:24-27`), `event_type` string(50) indexé
(`:19,29-30`), `created_at` indexé (`:20,32-33`), `prev_hmac` BYTEA nullable (`:36-37`),
`row_hmac` BYTEA nullable (`:39-40`), `actor_type` (`:47-48`), `actor_id` indexé
(`:21,49-50`), `action` (`:51-52`), `outcome` (`:53-54`), `details` JSON (`:55-56`),
`resource_type` (`:57-58`), `resource_id` (`:59-60`), `ip_address` VARCHAR(45) (`:61-62`),
`trace_id` (`:63-64`). `created_at` positionné au constructeur (`:67`).

### 8.3 Schéma de chaînage HMAC — exact

- Algorithme : `hash_hmac('sha256', $prevHmacBin . $canonical, $key, true)` — sortie
  binaire brute de 32 octets [VÉRIFIÉ] `AuditHmacChainer.php:87`.
- Formule documentée : `row_hmac = HMAC-SHA256(key, prev_hmac_bin || canonical_json)` (`:16`).
- Canonicalisation : `ksort()` puis `json_encode(..., JSON_THROW_ON_ERROR |
  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)` (`:84-85`).
- Champs couverts (`AuditLog::toCanonicalRow()`, `:174-186`) : `event_type`, `actor_type`,
  `actor_id`, `resource_type`, `resource_id`, `action`, `outcome`, `details`,
  `ip_address`, `trace_id`, `created_at` (format ATOM). **`id`, `prev_hmac` et `row_hmac`
  sont exclus** ; motif déclaré `:168-173` (l'`id` vaut 0 avant `flush()`).
- Clé : `AUDIT_HMAC_KEY`, 64 hex → `hex2bin` (`:19-20,41,59`), câblée
  `config/services.yaml:511-512`. Validation longueur 64 + `ctype_xdigit` (`:41`).
- Clé absente/invalide : `RuntimeException` si `$environment === 'prod'` (`:42-47`) ;
  sinon WARNING et `enabled = false`, `compute()` retourne `''` (`:49-58,80-82`).
- Tête de chaîne à l'écriture : `SELECT row_hmac FROM audit_log ORDER BY id DESC LIMIT 1`
  (`AuditLogger.php:157-159`), calculée avant `persist()`/`flush()` (`:83-89`).
- Reprise des lignes antérieures : `migrations/Version2026041200100000.php:60-114`,
  lots de 500, même formule (`:91`).

### 8.4 Vérification de la chaîne

`app:audit:verify-chain` (`VerifyAuditChainCommand.php:28`) [VÉRIFIÉ] :
- Parcours `id ASC` par lots de 1000 (`:33,52-58`), recalcul via le même chaîneur (`:89`).
- **Les lignes dont `row_hmac` est NULL/vide sont affichées « skipped » et comptées comme
  vérifiées, non comme des écarts** (`:70-76`).
- En cas d'écart : message `ROW_HMAC MISMATCH expected=… actual=…`, compteur incrémenté
  (`:91-99`) ; **la boucle continue et `$prevHmac` avance sur la valeur stockée** (`:101`).
- Code de sortie 0 / 1 (`:114`).
- Planification : quotidien ~02:00 UTC (`scheduler.sh:91-97`) ; en échec, le script émet
  `CRITICAL: audit:verify-chain FAILED — possible tamper detected` (`:95`) **et la boucle
  se poursuit** (`|| echo`).

### 8.5 Comportement de l'écrivain en cas d'échec

Liste d'événements bloquants (`AuditLogger.php:30-39`) : `AUTH_SUCCESS`, `AUTH_FAILURE`,
`AUTH_TOKEN_EXPIRED`, `AUTH_LOGOUT`, `AUTH_TOKEN_REUSE_DETECTED`, `INJECTION_DETECTED`.
Échec d'export SIEM sur un événement bloquant → `RuntimeException` relancée (`:113-119`) ;
sinon avertissement (`:123-126`). Tout throwable dans `log()` : relancé pour les
événements bloquants (`:133-135`), sinon `logger->warning('[AuditLogger] Failed to
persist audit event')` (`:137-141`) [VÉRIFIÉ].

### 8.6 Actions produisant une entrée d'audit

Émissions recensées (extraits — table complète dans les résultats d'exploration) [VÉRIFIÉ] :
authentification (`AuditAuthListener.php:33,44,56` ; `LoginController.php:84,122,143,157,178` ;
`TotpLoginController.php:92,136,161,185,205` ; `AuthService.php:84,101,113,136,155` ;
`OidcCallbackController.php:70,82`) ; ingestion (`IngestHandler.php:220` MESSAGE_INGESTED ;
`IngestPostProcessor.php:209` INGEST_PRE_FILTER_HIT, `:565` INJECTION_DETECTED, `:663`
RATE_LIMIT_EXCEEDED ; `IngestController.php:119`) ; cadence (`ReplyCadenceService.php:208`) ;
classification (`ScamClassificationHandler.php:144`) ; réponse (`ReplyHandler.php:360`
REPLY_GENERATED ; `ReplyCompositionService.php:289` REPLY_SENT ; `RetryCoordinator.php:419`
REPLY_RETRY, `:443` REPLY_REJECTED, `:227` LLM_LEAK_BLOCKED) ; IOC/TTP
(`IocUpsertService.php:352`, `TtpHandler.php:165`, `SubmitIocFeedbackController.php:67`) ;
cycle de vie (`ConversationClosureService.php:90,177`) ; bandit
(`PersonaOptimizer.php:188,238,284,389`) ; budget (`BudgetThresholdNotifier.php:74`) ;
kill switch (`ToggleLlmKillSwitchController.php:79`) ; exports (`ExportMispController.php:198`,
`CampaignStixExportHandler.php:65`) ; configuration (`CreatePersonaController.php:147`,
`UpdatePersonaController.php:159,174`, `UpsertPromptOverrideController.php:59`,
`DeletePromptOverrideController.php:39`, `RequestPromptCanaryController.php:75`) ;
utilisateurs (`UserCreateCommand.php:108`, `UserSetPasswordCommand.php:86`,
`UserPromoteCommand.php:83`).

Cas d'enum défini **sans site d'émission dans `src/`** : `LLM_SIGNATURE_STRIPPED`
(`AuditEventType.php:78` ; référencé seulement par `CefFormatter.php:71`) [VÉRIFIÉ].

### 8.7 Actions sensibles sans appel au service d'audit

Constat factuel : aucune référence à `auditLogger` / `AuditLogger` dans ces fichiers [VÉRIFIÉ].

`DeleteConversationController.php`, `DeleteMessageController.php`,
`UploadAttachmentController.php`, `DownloadAttachmentController.php`,
`ExportIocsStixController.php`, `ExportConversationStixController.php`,
`ExportIocsFeedController.php`, `SendEmailController.php`, `LogoutController.php`,
`RefreshController.php`, `TotpSetupController.php`, `TotpVerifyController.php`,
les 4 contrôleurs TAXII, `ExportClusterStixController.php`.

Agrégat : **145 fichiers `*Controller.php` sous `src/UI/Http` ; 12 référencent
`auditLogger`** [VÉRIFIÉ].

### 8.8 Journalisation applicative (Monolog)

`config/packages/monolog.yaml` [VÉRIFIÉ] : canaux déclarés `deprecation`, `scambaiting`
(`:2-4`). Processeurs : `TraceIdProcessor` (`:7-9`), **`PiiMaskingProcessor`** (`:10-12`).

| Env | Handler | Destination | Niveau |
|---|---|---|---|
| dev | `main` / `llm` / `scambaiting` | `rotating_file` (`max_files` 7) | debug / debug / info (`:17-34`) |
| dev | `console` | console | (`:43-46`) |
| test | `main` + `nested` | `fingers_crossed` (`action_level: error`) + `rotating_file` (`max_files` 3) | debug (`:52-61`) |
| **prod** | `main` | `fingers_crossed`, `action_level: error`, `buffer_size: 50`, canaux `!event,!doctrine,!llm` | (`:66-72`) |
| **prod** | `nested` / `llm` / `deprecation` | `stream` → **`php://stderr`**, formateur JSON | debug / info (`:73-92`) |

Rotation : uniquement via `rotating_file` en dev/test. En prod, sortie sur stderr ;
**aucun bloc `logging:` , `max-size` ni `max-file` dans `docker-compose.yml` ou
`docker-compose.prod.yml`** ; aucun volume monté pour `var/log` [VÉRIFIÉ].

Masquage PII (`src/Infrastructure/EventListener/Security/PiiMaskingProcessor.php`) :
email (`:24`), dernier octet IPv4 (`:26`), IBAN (`:29`), portefeuille ETH `0x`+40 hex
(`:31`), carte 4×4 chiffres (`:35`). **S'applique à `message` et `context` uniquement**
(`:38-40`) ; le docblock précise « Does NOT affect the audit_log database table » (`:18-19`).

### 8.9 Immuabilité

- Le docblock de l'entité annonce l'append-only (`AuditLog.php:16`) ; aucun mutateur de
  champ de contenu n'existe (seulement `setPrevHmac`/`setRowHmac`, `:141,155`) [VÉRIFIÉ].
- La migration indique que le `REVOKE` PostgreSQL sur UPDATE/DELETE « is a
  post-deployment ops step documented in `docs/runbooks/audit-hmac-key-rotation.md`, not
  embedded in this migration » (`Version2026041200100000.php:20-24`) [VÉRIFIÉ].
- **`rg -n "REVOKE" docs/` ne renvoie aucun résultat** : l'étape référencée n'est présente
  ni dans ce runbook (53 lignes lues intégralement) ni ailleurs sous `docs/` [VÉRIFIÉ].
- Aucun déclencheur ni règle de base de données dans `migrations/` ou `config/` [VÉRIFIÉ].
- [DÉDUIT] La preuve d'intégrité repose donc entièrement sur la chaîne HMAC et la
  commande de vérification quotidienne ; aucun stockage WORM n'est configuré.
  Raisonnement : absence de REVOKE appliqué, absence de trigger, absence de backend WORM.

### 8.10 Contenu brut dans les chemins de journalisation

| Chemin | Ce qui est enregistré | Preuve |
|---|---|---|
| `LlmUsageRecord` (`llm_usage`) | fournisseur, modèle, finalité, `prompt_tokens`, `completion_tokens`, coût, `conversation_id`, date. **Aucun champ de texte de prompt ou de complétion** | `src/Domain/LLM/LlmUsageRecord.php:18,28-38,40-52` |
| `PipelineTrace` | conversation, persona, type d'arnaque, langue, durées, coût, tentatives, approuvé, repli, tableau de composants. **Aucun texte**. Stocké dans `message.headers` JSON sous `pipeline_trace` | `src/Domain/LLM/PipelineTrace.php:11,119-133` ; `PipelineTraceHandler.php:13` |
| `ComponentTrace.output` réellement peuplé | `prompt_builder` : `{text_length, word_count}` (`RetryCoordinator.php:135-138`) ; `policy_guard` : `{approved, flags, attempt}` (`:155-159`) ; `reply_validator` : `{approved, reasons, attempt}` (`:279-283`) ; `ioc_scorer` : `{score, threshold}` (`:302-305`) | aucun texte brut |
| Journal client OpenAI | `{provider, model, latency_ms, input_messages(nombre), output_length(strlen), usage}` | `OpenAIClient.php:73-80` |
| Journal client Anthropic | idem | `AnthropicClient.php:104-112` |
| **Texte de complétion LLM écrit dans les journaux** | `ScamClassifier` : `'response' => substr($response, 0, 500)` en cas d'échec de validation JSON | `src/Application/LLM/ScamClassifier.php:69` |
| **Texte de complétion LLM écrit dans les journaux** | `ConversationAnalyzer` : `substr($llmResponse, 0, 500)` | `src/Application/LLM/ConversationAnalyzer.php:852` |
| **Texte de complétion LLM écrit dans les journaux** | `ContextualEnricher` : `substr($response, 0, 200)` | `src/Application/LLM/ContextualEnricher.php:68` |
| **Complétion LLM intégrale écrite dans les journaux** | `ReplyValidator` : `'response' => $response` — **non tronqué** — sur `JsonException` | `src/Application/LLM/ReplyValidator.php:144` |

### 8.11 Métriques

`MetricsController` — `GET /api/metrics` (`:27`), `text/plain; version=0.0.4` (`:128`),
**aucun attribut `#[IsGranted]` sur la classe** [VÉRIFIÉ]. Séries exportées :
`scambuster_info{version}` (`:57-59`), `scambuster_conversations_total{status}` (`:63-71`),
`scambuster_messages_total{direction}` (`:75-80`), `scambuster_iocs_total` (`:84-88`),
`scambuster_iocs_unique` (`:91-93`), `scambuster_kill_switch` (`:97-100`),
`scambuster_health_check{service}` (`:104-112`), `scambuster_convergence_ratio` (`:118-123`).

Prometheus : `infra/monitoring/prometheus/prometheus.yml`, règles
`alert.rules.yml` (alertes `ScamBusterKillSwitchActive`, `ScamBusterDependencyDown`,
`ScamBusterMetricsUnreachable`, `ScamBusterIngestStalled`, et suivantes). Grafana :
`infra/monitoring/grafana/dashboards/scambuster-security.json`.

Export SIEM : **`SIEM_PROVIDER=none` par défaut** (`.env.dist:421`) ; options `file`
(NDJSON) ou `syslog` (udp/tcp + CEF) (`.env.dist:425-439`) [VÉRIFIÉ].

---

## 9. Tests, CI, scans

### 9.1 Comptage des tests backend (`*Test.php`)

| Répertoire | `*Test.php` | Total `.php` |
|---|---|---|
| `tests/Unit` | 315 | 317 |
| `tests/Integration` | 107 | 108 |
| `tests/Functional` | 97 | 98 |
| `tests/EndToEnd` | 53 | 55 |
| `tests/Smoke` | 0 | 0 (fixtures `.eml`, `guard-baseline.json`, `.sha256`) |
| `tests/Fake` | 0 | 3 (`FakeLLMClient`, `FakeCanaryJobRepository`, `FakeCanarySmokeRunner`) |
| **Total** | **572** | — |

Sous-répertoires `Unit` : Application 205, Domain 49, UI 28, Infrastructure 22,
Security 5, EventListener 4, Command 2.

Frontend : **138** fichiers `*.test.ts(x)` sous `frontend-react/src`, framework
**Vitest** (`package.json:12`, config `vite.config.ts:23-42`), `jsdom`, `msw`,
`vitest-axe`. **Aucun seuil de couverture configuré** [VÉRIFIÉ].

### 9.2 Configuration PHPUnit

`phpunit.xml.dist` : suites `integration`, `functional`, `unit`, `endtoend` (`:27-39`).
Couverture : source `src` (`:44`), exclusions `src/DataFixtures`, `src/Service`,
`src/Kernel.php`, 3 fichiers Preprod, **tout le répertoire `src/UI/Console`** (`:53`), et
7 fichiers `src/Command/*` (`:54-61`) [VÉRIFIÉ].

`phpunit.ci.xml` : `failOnWarning="false"`, `failOnDeprecation="false"` (`:9-10`) ;
extension `SymfonyExtension` volontairement retirée (`:2,13`) ; suites `integration`
(4 exclusions), `unit`, `endtoend` (2 exclusions) — **aucune suite `functional`**, motif
déclaré `ci.yml:103-108` (« ~855 controller tests never ran » si nommée) [VÉRIFIÉ].

**Aucun seuil minimal de couverture n'est configuré dans l'un ou l'autre fichier** [VÉRIFIÉ].

### 9.3 Simulacre LLM

Le client LLM est lié à un double dans les deux environnements de test [VÉRIFIÉ] :
`config/packages/test/llm.yaml` et `config/packages/e2e/llm.yaml` →
`LLMClientInterface: '@App\Tests\Fake\FakeLLMClient'`.
`tests/Fake/FakeLLMClient.php` retourne des réponses figées selon la forme du prompt ou
l'option `purpose` (`:17,39,46,63,71,82`), défaut une réponse française fixe (`:91-96`).

**Le seul chemin LLM réel en CI est le workflow GUARD planifié**, conditionné à
`LLM_API_KEY` et exécuté avec `APP_ENV=dev` précisément parce que `test` lie le double —
`guard-nightly.yml:61-64,92-95` [VÉRIFIÉ].

### 9.4 Fichiers de test des garde-fous

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
`RetryCoordinatorAuditTest`, `PersonaOptimizerAuditTest` [VÉRIFIÉ].

### 9.5 CI — `.github/workflows/ci.yml`

Déclencheurs : `push` sur `main`, `demo` (`:3-5`) ; `pull_request` sur `main` (`:6-7`).
`permissions: contents: read` (`:9-10`) [VÉRIFIÉ].

| Job | Contenu | Ce qui fait échouer |
|---|---|---|
| `static-analysis` (`:13`) | PHPStan `analyse src --memory-limit=1G` (`:36`) ; `check-no-vault-resurrection.sh` (`:39`) | PHPStan non nul ; script de garde non nul |
| `code-style` (`:41`) | `php-cs-fixer fix --dry-run --diff` (`:64`) | tout écart de style |
| `backend-tests` (`:66`) | PHPUnit `--configuration phpunit.ci.xml --testsuite unit,integration --exclude-group compiler-pass` (`:110-116`) ; tests compiler-pass isolés (`:120-123`) ; génération de paire JWT RSA-2048 (`:135-143`) ; suite `endtoend --exclude-group ci-skip` (`:166-171`) ; envoi Codecov (`:181-185`) | tout échec PHPUnit. Codecov `fail_ci_if_error: false` (`:184`). **La suite `functional` n'est pas exécutée** |
| `security` (`:187`) | `composer audit --format=json` filtré par un script python3 en ligne (`:216-244`) ; Gitleaks CLI v8.21.2 téléchargé depuis les releases GitHub, `gitleaks git . --redact --config .gitleaks.toml --exit-code 1` (`:252-257`) | tout avis composer non ignoré (`:238-241`) ; tout constat gitleaks |
| `frontend` (`:259`) | `npm ci` (`:277`), `tsc --noEmit` (`:280`), `npm run lint` (`:283`), `npm run test` (`:286`), `npm run build` (`:289`) | erreurs TS/ESLint/Vitest/build |
| `container-security` (`:291`) | matrice sur 3 Dockerfiles (dev/prod/demo) (`:302-308`) ; Trivy `exit-code: 1`, `severity: CRITICAL,HIGH`, `ignore-unfixed: true`, `trivyignores: .trivyignore` (`:316-330`) ; **Trivy en `format: cyclonedx` → `sbom-<name>.cdx.json`** (`:332-337`) ; artefact conservé 30 jours (`:339-344`) | toute CVE CRITICAL/HIGH **corrigeable** dans l'une des 3 images |

### 9.6 CI — `.github/workflows/guard-nightly.yml`

`schedule: cron '0 5 * * 0'` (dimanche 05:00 UTC, `:18-19`) + `workflow_dispatch` (`:20`) ;
`concurrency: guard-nightly, cancel-in-progress: false` (`:25-27`) [VÉRIFIÉ].

| Job | Détail |
|---|---|
| `preflight` (`:30`) | sortie `has_key` selon la présence de `secrets.LLM_API_KEY` (`:36-45`) ; `::notice::` et saut si absente (`:44`) |
| `guard` (`:47`) | conditionné à `has_key == 'true'` (`:50`), `timeout-minutes: 60` (`:52`). Réécrit `.env` en `APP_ENV=dev`, `LLM_PROVIDER=openai`, `LLM_MODEL=gpt-4o-mini` (`:68-71`). Assertion `LLM_PROVIDER = openai`, sinon exit 1 (`:93-95`). Exécute `scambuster:smoke:reply-objective --summary-json=…` (`:98`) puis `scambuster:guard:check --baseline=tests/Smoke/guard-baseline.json` (`:101`) |

Commentaires d'en-tête : coût ~0,14 $ / ~35 min (`:5`) ; le secret n'est jamais versé en
artefact (`:14`) [VÉRIFIÉ].

### 9.7 Inventaire des scanners

| Scanner | Présent | Preuve |
|---|---|---|
| gitleaks | **Oui** — v8.21.2, historique complet (`fetch-depth: 0`), bloquant | `ci.yml:246-257` ; `.gitleaks.toml` |
| trivy (conteneur) | **Oui** — 3 images, CRITICAL/HIGH, `ignore-unfixed: true`, bloquant | `ci.yml:316-330` |
| **SBOM** | **Oui** — CycloneDX via l'action Trivy, artefact 30 jours | `ci.yml:332-344` |
| phpstan | **Oui** — `analyse src` ; script composer `--level=max` (`composer.json:100`) | `ci.yml:36` |
| psalm | **Absent** — aucun `psalm*`, aucune entrée composer | — |
| php-cs-fixer | **Oui** — dry-run bloquant | `ci.yml:64` |
| composer audit (SCA) | **Oui** — bloquant sur tout avis ; `audit.ignored: {}` vide (`composer.json:55-57`) | `ci.yml:208-244` |
| dependabot | **Oui** — 3 écosystèmes, tous **mensuels** : composer `/backend-symfony` (limite 2 PR), npm `/frontend-react` (limite 2), github-actions `/` (limite 1) | `.github/dependabot.yml:3-33` |
| codecov | **Oui** — `require_ci_to_pass: false` (`:2`), cibles `auto` (`:6-11`). **Aucun seuil numérique** ; envoi `fail_ci_if_error: false` | `codecov.yml` ; `ci.yml:184` |
| **npm audit** | **Absent** en CI — aucune étape dans le job `frontend` (`ci.yml:259-289`) | — |
| SAST (semgrep / CodeQL / Snyk) | **Absent** — recherche sur l'ensemble du dépôt sans résultat | — |
| DAST (ZAP / Dastardly) | **Absent** | — |
| Analyse de licences | **Absent** | — |
| OpenSSF Scorecard | **Absent** | — |

### 9.8 Contrôles explicitement recherchés et absents

| Contrôle | Résultat |
|---|---|
| SBOM via **syft** / **cyclonedx-cli** | Absent (le SBOM existe, mais produit uniquement par l'action Trivy) |
| **Application** de la signature des commits | Absent — aucune protection de branche ni étape de vérification dans les workflows |
| Signature d'artefacts (**cosign** / sigstore) | Absent |
| **SLSA / attestation de provenance** | Absent |
| Épinglage des dépendances **par empreinte** | Absent pour les dépendances de langage (plages `^` dans `composer.json` et `package.json` ; les verrous figent les versions). **Les actions GitHub sont épinglées par SHA** (`ci.yml:18,181,270,311,317,333,340` ; `guard-nightly.yml:54`). **Gitleaks est téléchargé par tag de version en curl sans vérification de somme de contrôle** (`ci.yml:252-255`) |
| Seuil numérique de blocage CVE | Absent en tant que score. Blocage catégoriel : Trivy `CRITICAL,HIGH` + `exit-code 1` + `ignore-unfixed: true` ; composer audit bloque sur tout avis |
| Recherche de secrets en CI | **Présent** (gitleaks) |
| Provenance / attestation d'image | Absent — les images sont construites dans le job (`docker build`, `:314`) et jamais poussées, signées ni attestées |
| Application du hook pre-push GUARD en CI | Absent — le hook est optionnel et se limite à un rappel par défaut (`scripts/hooks/pre-push-guard.sh:9-11,54-60`) |

### 9.9 `.trivyignore`

14 lignes, **zéro entrée de CVE**. Contenu cité [VÉRIFIÉ] :
> « Intentionally EMPTY. This file suppresses *fixable* CVEs we consciously accept; there
> are currently none. — Fixable base-image CVEs are patched at build time by
> `apt-get upgrade` in the Dockerfiles… — Unfixable base CVEs (no upstream patch — e.g.
> perl-base, kernel headers, ncurses) are skipped by the CI Trivy job's
> `ignore-unfixed: true`… Add an entry (with a dated justification) only for a CVE that
> HAS a fix but that we deliberately choose not to apply yet. »

### 9.10 `.gitleaks.toml`

`[extend] useDefault = true` (`:11-12`). En-tête de justification (`:1-7`) : « The
repository ships only template/example/dev-default credentials — no real secrets
(verified over the full history). » [VÉRIFIÉ]

**Expressions autorisées** (`:18-29`) : `sk-proj-abc123...`, `sk-your-api-key-here`,
`sk-e2e-not-real`, `sk-test-not-a-real-key`,
`YOUR_[A-Z0-9_]*(KEY|TOKEN|SECRET|PASSWORD)`, `your-app-password-here`,
`a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4`, `change-me-generate-with-openssl`,
`dev-only-change-in-production`, `demo-secret-change-in-production`.

**Chemins autorisés** (`:33-42`), en-tête « Files that contain ONLY templates, examples,
or disposable dev/test/demo defaults by design » : `\.env\.dist`, `\.env\.test`,
`\.env\.e2e`, `docs/QUICKSTART\.md`, `docs/13_misp_integration\.md`,
`docker-compose\.demo\.yml`, `infra/docker/demo/docker-entrypoint-demo\.sh`,
`\.github/scripts/create-ci-env\.sh`.

`AUDIT_HMAC_KEY=bbbb…` (`.env.dist:360`, `.env.test:121`) relève de l'autorisation par
chemin [VÉRIFIÉ].

### 9.11 Processus de publication

| Élément | État |
|---|---|
| Tags git | **0** |
| Workflow de publication | **Aucun** — seuls `ci.yml` et `guard-nightly.yml` existent |
| CHANGELOG | `CHANGELOG.md` (23 333 octets). En-tête : « The format is based on Keep a Changelog … adheres to Semantic Versioning » (`:5-6`). Section de tête : `## [Unreleased]` (`:9`) |
| Schéma de versionnement | SemVer déclaré (`CHANGELOG.md:6`). `1.3.0` apparaît comme exemple OpenAPI (`MetricsController.php:39`) ; la version d'exécution vient de `HealthCheckHandler` (`:56`) |

---

## 10. Dépendances et versions

### 10.1 Backend — `backend-symfony/composer.json`

Contrainte PHP `">=8.2"` (`:7`) ; extensions `ext-ctype`, `ext-iconv` (`:8-9`).
Ligne Symfony épinglée par `extra.symfony.require: "7.4.*"` (`:107`).
`minimum-stability: stable`, `prefer-stable: true` (`:4-5`) ; `audit.ignored: {}` (`:55-57`).
**35 dépendances directes** en `require`, **13** en `require-dev` [VÉRIFIÉ].

Versions résolues des paquets les plus sensibles (`composer.lock`) :

| Paquet | Résolu |
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

**Aucun SDK LLM** [VÉRIFIÉ] : `firebase/php-jwt` et `web-token/jwt-library` sont absents
de `composer.lock` ; l'analyse des 114 paquets de production pour
`openai|anthropic|llm|ollama|mistral` ne renvoie aucun résultat. Les appels LLM passent
par `symfony/http-client` brut.

### 10.2 Frontend

React **19.2.4**, Vite **8.0.1**, TypeScript **5.9.3**, Vitest **4.1.4**, axios **1.13.6**,
zustand **5.0.12**, `@tanstack/react-query` **5.91.3**, react-router-dom **6.30.3**,
recharts **3.8.0**, msw **2.12.14**, jsdom **29.0.1** [VÉRIFIÉ].
Directes : 10 `dependencies` + 25 `devDependencies` = **35**. Verrou `lockfileVersion 3`,
**436** entrées.
**Aucune bibliothèque d'authentification ou de cryptographie** : une recherche
`jwt|crypto|jose|bcrypt|oauth|auth0` dans les noms de paquets du verrou ne renvoie rien
[VÉRIFIÉ].

### 10.3 Images de base Docker

| Emplacement | Image | Tag | Épinglé par empreinte |
|---|---|---|---|
| `infra/docker/backend/Dockerfile:2,29` | `php` | `8.3.27-cli` | Non |
| `infra/docker/backend/Dockerfile.prod:11,21,39` | `node` / `php` / `php` | `20-alpine` / `8.3.27-cli` / `8.3.27-fpm` | Non |
| `infra/docker/frontend/Dockerfile:1` | `node` | `20-alpine` | Non |
| `infra/docker/demo/Dockerfile.backend:7,64` | `php` | `8.3.27-cli` | Non |
| `infra/docker/demo/Dockerfile.frontend:7,20` | `node` / `nginx` | `20-alpine` / **`alpine`** (tag flottant) | Non |
| `docker-compose*.yml` | `postgres` | `15-alpine` | Non |
| `docker-compose*.yml` | `redis` | `7-alpine` | Non |
| `docker-compose{,.prod}.yml` | `n8nio/n8n` | `1.114.3` | Non |
| `infra/monitoring/docker-compose.yml:13,27` | `prom/prometheus` / `grafana/grafana` | `v2.54.1` / `11.2.0` | Non |

**Aucune image n'est épinglée par `@sha256:`** [VÉRIFIÉ]. PHP est épinglé au correctif
exact (`8.3.27`) ; n8n, Prometheus et Grafana à une version exacte ; postgres, redis, node
et nginx utilisent des tags majeurs/mineurs flottants.

### 10.4 Directes contre transitives

| Écosystème | Directes | Total verrouillé | Transitives |
|---|---|---|---|
| Composer (prod) | 35 | 114 | 79 |
| Composer (dev) | 13 | 68 | 55 |
| Composer (cumulé) | 48 | 182 | 134 |
| npm | 35 (10 + 25) | 436 entrées | ~401 |

---

## 11. Doc obsolète détectée

> Registre ouvert en phase 0, alimenté jusqu'à la fin de l'audit (règle R3 : en cas de
> contradiction, **le code fait foi**). Chaque ligne cite les deux côtés.

| # | Doc (fichier:ligne) | Affirmation de la doc | Réalité du code (fichier:ligne) | Nature de l'écart |
|---|---|---|---|---|
| DOC-01 | `docs/12_api_quick_reference.md:37-38` | « GET \| `/api/health` \| **No** » et « GET \| `/api/metrics` \| **No** \| Prometheus text format » | `config/packages/security.yaml:60-61` — `{ path: ^/api/metrics, roles: ROLE_ADMIN }`, `{ path: ^/api/health, roles: ROLE_ADMIN }` | Documentés sans authentification ; exigent `ROLE_ADMIN`. `docs/compliance/risk-register.md:12` enregistre ce durcissement comme **CLOSED**, donc la doc 12 n'a pas suivi |
| DOC-02 | `docs/12_api_quick_reference.md:186-188, 204-211` | 9 points d'API marqués « auth : **No** » (`/scambaiting/stats`, `/campaign/candidates`, `/campaign/transpile`, `/campaign/rule`, …) | `config/packages/security.yaml:67` — `{ path: ^/api/v1, roles: IS_AUTHENTICATED_FULLY }` | Tous les chemins `/api/v1` sont authentifiés |
| DOC-03 | `docs/compliance/gdpr-record-of-processing.md:53` | « 6 months soft-delete → 12 months hard-delete \| `PurgeService` (`app:cleanup:weekly`, **automatic**) » | `WeeklyCleanupCommand.php:32-33` — défauts `90` / `180` jours, DBAL brut, **n'appelle jamais `PurgeService`**, **aucune suppression physique** | La commande automatique nommée purge à 90 jours, ne supprime pas physiquement, et n'utilise pas le service cité |
| DOC-04 | `docs/compliance/data-classification.md:50` | « soft-deleted at 6 months, hard-deleted at 12 months (`PurgeService`, **automatic**) » | `PurgeService.php:25,55` n'est atteignable que depuis `app:purge:rgpd`, **absent de `scheduler.sh:17-24`** et du `Makefile` de production | La logique 6/12 mois existe mais **n'est jamais planifiée** |
| DOC-05 | `docs/04_security_guardrails.md:61` | « Content layer : **Anonymized** or deleted after 6 months per DPIA scope » | `PurgeService.php:29-33` filtre sur `status = CLOSED` seulement ; le corps est `softDelete()`. `MessageAnonymizer` n'est utilisé que par `ContextualEnricher.php:26` (construction de prompt) | Les conversations ouvertes/abandonnées ne sont jamais touchées ; **aucune anonymisation n'existe dans le chemin de purge** |
| DOC-06 | `docs/04_security_guardrails.md:376` ; `gdpr-record-of-processing.md:57` | « **13 fine-grained permissions** (…) » avec énumération | `src/Domain/User/Permission.php:19-40` — **14 cas** ; `:33` `case CAMPAIGN_WRITE = 'campaign:write';` absent de la liste documentée | Compte et énumération tous deux faux |
| DOC-07 | `docs/04_security_guardrails.md:121` ; `README.md:274` | « **PolicyGuard** (rule-based) blocks : … **Illegal offers** \| Drugs, weapons, CSAM » | `PolicyGuard.php:47-181` — les seuls jeux sont `FORBIDDEN_PATTERNS`, `OPERATIONAL_LEAKAGE_PATTERNS`, `THREAT_PATTERNS`, `AUTHORITY_PATTERNS`, `PII_PATTERNS`, `OUT_OF_BAND_CHANNEL_PATTERNS` ; recherche `drug\|weapon\|CSAM\|firearm` sur `backend-symfony/src` → **aucun résultat** | Catégorie de blocage annoncée **sans aucune règle correspondante** |
| DOC-08 | `docs/04_security_guardrails.md:356` | « `Content-Security-Policy: **default-src 'none'**` … Implemented via `SecurityHeadersListener` » | `SecurityHeadersListener.php:44` — `"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; …"` | Valeur de directive différente de celle documentée. Les sept autres en-têtes de la même ligne correspondent |
| DOC-09 | `docs/09_dpia_template.md:107` et `:120` | « **16** automation-revealing keyword patterns are blocked » | `PolicyGuard.php:47-54` — `FORBIDDEN_PATTERNS` contient **6** entrées | Compte faux d'un facteur ~2,7 |
| DOC-10 | `docs/02_value_proposition.md:161` ; `docs/05_evaluation_methodology.md:177` | « **GPT-4o (generation)** + GPT-4o-mini (validation) » | `.env.dist:306` — `LLM_MODEL=gpt-4o-mini` ; `config/packages/llm.yaml:4` injecte le même paramètre dans le générateur (`:163-172`) et le validateur (`:137-141`) | **Aucune scission génération/validation** ; le défaut livré est `gpt-4o-mini` des deux côtés. `docs/03_high_level_architecture.md:384` énonce le comportement correct — les deux documents se contredisent aussi entre eux |
| DOC-11 | `docs/05_evaluation_methodology.md:164` | « Database Constraints \| **RLS policies** \| Multi-tenant isolation » | Recherche `ROW LEVEL SECURITY` / `CREATE POLICY` sur `migrations/` et `src/` → aucun résultat ; `migrations/Version2026041112000000.php:35` — `ALTER TABLE app_users DROP COLUMN tenant_id` | Contrôle listé comme implémenté, **sans implémentation** ; le seul artefact multi-tenant a été retiré (`docs/06_roadmap.md:149` le confirme) |
| DOC-12 | `docs/compliance/risk-register.md:11` | « R2 \| Refresh token stored plaintext ; no reuse-detection cascade ; refresh not audited \| **OPEN** » | `RefreshToken.php:55` — `return hash('sha256', $rawToken);` ; `AuthService.php:99-106` — `revokeFamily(...)` + `AuditEventType::AUTH_TOKEN_REUSE_DETECTED` | Les trois sous-risques **sont traités dans le code** ; le registre les indique non traités |
| DOC-13 | `docs/compliance/risk-register.md:19` | « R10 \| No SSO/OIDC (enterprise IAM) \| **IN PROGRESS** » | `src/Application/Auth/Oidc/OidcService.php` (269 lignes) + `OidcStateManager`, `OidcUserProvisioner`, routes `/api/v1/auth/oidc/{login,callback}`, `.env.dist:75-95` | Authentificateur OIDC complet livré ; `docs/06_roadmap.md:57` le classe en « Recently shipped », le registre non |
| DOC-14 | `docs/12_api_quick_reference.md:273` | « `app:close-stale-conversations` \| **Daily** » | `infra/docker/backend/scheduler.sh:17` — « every 6h », exécuté `:46-48` | Fréquence documentée fausse. `docs/08_getting_started.md:544` dit « Every 6h » — contradiction interne à la documentation |
| DOC-15 | `.env.dist:367-369` | `CAMPAIGN_PROMOTION_PPV_THRESHOLD=0.85`, `CAMPAIGN_PROMOTION_MIN_HITS=5`, `CAMPAIGN_PROMOTION_MIN_LEAD_TIME_SEC=10800` | `src/Application/Campaign/CampaignPromoter.php:16-18` — `private const PPV_THRESHOLD = 0.85; MIN_HITS = 5; MIN_LEAD_TIME_SEC = 10800;`, restituées verbatim par `getThresholds()` (`:176-178`) | **Constantes en dur** ; les trois variables ne sont lues nulle part → **les régler est sans effet** |
| DOC-16 | `.env.dist:123-124, 146, 148, 323` | `RSPAMD_URL`, `RSPAMD_PASSWORD`, `DEBUG_IMAPFLOW`, `SCORE_RISK_MIN`, `REPLY_HISTORY_LAST_N` | Recherche sur tout le dépôt : ces noms n'apparaissent que dans `.env.dist`, `.env.test`, `.env.e2e`, `docker-compose.demo.yml`, `.github/scripts/create-ci-env.sh` — **aucun PHP, YAML de configuration ni workflow n8n ne les lit** | **5 réglages documentés non consommés** |
| DOC-17 | `docs/12_api_quick_reference.md:3` | « **All endpoints** grouped by domain » | `src/UI/Http/**` déclare **132 chemins `/api/...` distincts** ; le document contient **95 lignes** | ~40 points d'API vivants omis, dont `/api/v1/2fa/setup`, `/api/v1/auth/oidc/{login,callback}`, `/api/v1/admin/llm/killswitch`, les neuf `/api/v1/monitoring/analytics/*`, `/api/v1/prompt-overrides*`, `/api/v1/communication/reply/{msgId}/send-email`, `/api/v1/clusters`, `/api/v1/stats/*` |
| DOC-18 | `docs/03_high_level_architecture.md:183` | « Evaluation : Automated benchmark suite (**7 quality metrics**, 3 CLI commands) » | `src/Application/Evaluation/ReplyQualityAnalyzer.php:36-45` — le tableau `$metrics` porte **9** résultats calculés | Compte contredit par le code **et** par `docs/05_evaluation_methodology.md:229` (« 9 quality metrics ») ; le docblock de la classe (`:17`) dit « 10 metrics total », qui ne correspond à aucun des deux |
| DOC-19 | `README.md:277` | « **GDPR** : data minimization, retention policies, **encryption at rest** » | `src/Domain/Communication/Message.php:57` — `#[ORM\Column(name: 'body_text', type: 'text')]` (colonne simple) ; `EncryptedStringType` n'est employé que dans `Domain/User/User.php:45` | **Le contenu des messages est stocké en clair.** `docs/compliance/data-classification.md:37` le dit explicitement (« Field-level encryption of bodies is **not** applied ») — le README contredit à la fois le code et son propre document de conformité |
| DOC-20 | `GOVERNANCE.md:25` | « **Tags** : Each release is tagged in Git (e.g., `v2.3.0`) » | `git tag -l \| wc -l` → **0** ; aucun workflow de publication dans `.github/workflows/` | Aucun tag n'existe ; le processus de publication décrit n'est pas appliqué |
| DOC-21 | `SECURITY.md:38` | « **Audit trail** : All operations logged for traceability » | 145 fichiers `*Controller.php` sous `src/UI/Http` ; **12 référencent `auditLogger`**. Sans entrée d'audit : `DeleteConversationController`, `DeleteMessageController`, `UploadAttachmentController`, `DownloadAttachmentController`, `ExportIocsStixController`, `ExportConversationStixController`, `ExportIocsFeedController`, `SendEmailController`, les 4 contrôleurs TAXII, `ExportClusterStixController` | « All operations » est contredit : les suppressions, les téléversements/téléchargements de pièces jointes et **quatre des six surfaces d'export** n'émettent aucun audit |
| DOC-22 | `docs/09_dpia_template.md:33` et `:146` | « 6 months max, **then anonymization** » ; « PurgeService : **anonymization at 6 months** » | Aucune routine d'anonymisation, de pseudonymisation ou de caviardage n'existe dans `src/` ; `PurgeService.php:23-45` fait une suppression **logique** | Doublon d'angle avec DOC-05, consigné séparément parce qu'il porte sur le DPIA, pièce opposable |
| DOC-23 | `migrations/Version2026041200100000.php:20-24` | Le `REVOKE` PostgreSQL sur UPDATE/DELETE de `audit_log` est « documented in `docs/runbooks/audit-hmac-key-rotation.md` » | `rg -n "REVOKE" docs/` → **aucun résultat** ; runbook lu intégralement (53 lignes) | L'étape d'exploitation qui rend le journal réellement append-only **n'est documentée nulle part**, alors que la migration s'y réfère |
| DOC-24 | `docs/runbooks/audit-hmac-key-rotation.md:32-33` | Étape 3 de rotation : « Run a rebuild script » | Aucun script de reconstruction de chaîne n'existe sous `scripts/`, `bin/` ou `src/UI/Console/` | Procédure de rotation renvoyant à un outil inexistant |
| **DOC-25** | `docs/04_security_guardrails.md:400` | « **PII masked in exported events (emails hashed, IPs truncated)** » | Les trois formateurs émettent `actorId` et `ipAddress` **verbatim** : `JsonFormatter.php:29,33` ; `EcsFormatter.php:40,44` ; `CefFormatter.php:95,99`. Le tableau `details` est sérialisé **sans filtrage** (`JsonFormatter.php:35`, `EcsFormatter.php:60`, `CefFormatter.php:120`). Recherche `hash\|mask\|truncat\|anonym\|sha256\|redact` sur `src/Infrastructure/Siem/` et `SiemEvent.php` : **aucun résultat**. La seule transformation est l'échappement des métacaractères CEF (`CefFormatter.php:129-136`), qui préserve la valeur | **Aucun masquage, aucun hachage, aucune troncature n'existe.** `actorId` porte une adresse de courriel en clair sur le chemin OIDC (`OidcCallbackController.php:83`) |

**Contradictions internes à la documentation** (les deux côtés sont de la doc, aucun n'est
le code) : DOC-10 (`02_value_proposition` vs `03_high_level_architecture`), DOC-14
(`12_api_quick_reference` vs `08_getting_started`), DOC-18
(`03_high_level_architecture` vs `05_evaluation_methodology` vs docblock),
DOC-19 (`README` vs `data-classification`).

**Documentation vérifiée conforme au code** (contrôlée, sans écart) : `docs/14_key_management.md`
(RS256, `token_ttl: 900`, RSA-2048) ; table de limitation de débit de
`docs/04_security_guardrails.md:101-112` ; « 33 event types » (`:157,360,390`) contre les
33 cas de `AuditEventType.php` ; plafonds de charge utile 1 Mo / 50 Mo (`:380-382`) contre
`PayloadSizeLimitListener.php:35-41` ; `docs/22_metrics_catalog.md` ;
`docs/15_siem_integration.md` ; `docs/13_misp_integration.md` (y compris l'aveu qu'il n'y
a pas de push MISP) ; `docs/16_taxii_server.md` et `docs/11_opencti_integration.md` (UUID
de collections) ; `docs/20_enterprise_sso.md` ; les comptages 36 types d'IOC / 14 types
d'arnaque / 27 TTP / 27 personas ; les poids de récompense 0,40/0,25/0,25/0,10 ;
`docs/19_data_quality_audit.md:67` ; les ports 3002/8081/5678 ; l'ensemble des cibles
`make` citées dans la documentation existent dans le `Makefile`.

---

## 12. Ce que je n'ai pas pu vérifier

> **Mise à jour après passe complémentaire.** Les questions **17** (invocation de
> `check-secrets` par l'entrypoint de production), **28** (contrôles du module OIDC),
> **29** (`check-credentials.py`), **30** (`create-ci-env.sh`) et **31**
> (`TestReplyGenerateCommand`) sont **closes** — voir §4.11 et §4.12. Les questions
> subsistantes ci-dessous restent ouvertes.

Formulé en questions, conformément à R8.

**Sur l'exécution réelle et le déploiement**
1. `app:purge:rgpd` est-il invoqué par un cron externe, une cible `make` exécutée en
   production, ou un `CronJob` Kubernetes hors de `scheduler.sh` — ou la rétention 6/12
   mois n'a-t-elle jamais tourné en production ?
2. Le `REVOKE UPDATE/DELETE ON audit_log` référencé par
   `migrations/Version2026041200100000.php:20-24` a-t-il été appliqué dans un
   environnement quelconque, puisqu'il ne figure dans aucun fichier du dépôt ?
3. Quelle est la valeur de `LLM_BUDGET_ENFORCEMENT_MODE` en production, sachant que le
   défaut livré est `warning` (`ReplyHandler.php:41`, `.env.dist:343`) ?
4. Quel fournisseur SIEM est réellement configuré en production, sachant que le défaut
   livré est `SIEM_PROVIDER=none` (`.env.dist:421`) ?
5. Les listes `operatorTestSenders`, `honeypotEmailAddresses` et `honeypotDomains` — dont
   dépendent les gardes D57 et D65 — sont-elles peuplées dans le déploiement de
   référence, ou ces gardes sont-elles sans effet par défaut ?
6. `SCAMBUSTER_SAFE_DOMAINS` est-il réglé sur autre chose que son défaut `*` en
   production, et l'indicateur `safelist_eligible` est-il consommé quelque part (workflow
   n8n, couche SMTP) pour bloquer un envoi ?
7. Le hook pre-push GUARD est-il installé chez l'opérateur (`GUARD_ON_PUSH=1`), ou la
   porte GUARD ne s'exerce-t-elle qu'une fois par semaine en CI ?

**Sur l'intervention humaine et le circuit d'autorisation**
8. Existe-t-il une machine à états ou une configuration exigeant une approbation
   d'analyste **distincte** entre le brouillon et l'envoi ? `/send-email`
   (`SendEmailController.php:48`) et `/reply/{msgId}/sent` (`MarkReplySentController.php:66`)
   ne sont gardés que par `#[IsGranted('reply:generate')]`, la même permission que la
   génération — un principal n8n porteur de cette permission peut-il générer **et**
   envoyer sans humain dans la boucle ?
9. `WF-REPLY-SEND-v1.json` porte `"active": false` alors que les trois autres workflows
   sont actifs — l'envoi est-il volontairement désactivé par défaut, et où cela
   est-il documenté ?
10. Un consommateur quelconque (n8n, tableau de bord, alerte) agit-il sur
    `injection_analysis.risk_score >= 0.7`, ou la détection d'injection reste-t-elle
    purement forensique malgré l'issue d'audit `'blocked'` ?

**Sur la donnée et le stockage**
11. Les 99 fixtures `.eml` sont-elles synthétiques, ou dérivées de courriels
    d'arnaque réellement reçus — ce qui en ferait des données personnelles de tiers
    versées au dépôt public ?
12. `Attachment::$s3Key` et `$encKeyId` possèdent des mutateurs sans aucun appelant dans
    `src/` : le stockage binaire des pièces jointes est-il réalisé hors du code Symfony
    (n8n, service externe), ou ce jeu de colonnes est-il mort ?
13. Si les octets de pièces jointes sont stockés quelque part, quelle clé `enc_key_id`
    référence-t-il et où est détenu ce matériel cryptographique ?
14. `message_vector` n'a aucune clé étrangère vers `message` : un processus réconcilie-t-il
    les vecteurs orphelins après une suppression physique de conversation ?
15. Où le mot de passe IMAP du honeypot est-il consommé — n8n lit-il
    `HONEYPOT_IMAP_PASSWORD` à l'exécution, ou une copie réside-t-elle aussi dans
    `./data/n8n/` ?

**Sur les secrets et la cryptographie**
16. `ADMIN_PASSWORD` figure dans `SecretPolicy::PUBLISHED_DEFAULTS` et dans
    `CheckSecretsCommand::CHECKED` mais n'a aucune ligne dans `.env.dist` : où est-il
    défini et quel composant le consomme ?
17. L'entrypoint de production invoque-t-il réellement `app:security:check-secrets` ?
    L'affirmation figure dans le docblock (`CheckSecretsCommand.php:19-21`) mais les
    scripts `infra/docker/backend/` n'ont pas été lus sur ce point.
18. `scripts/rotate-jwt-keys.sh:51` place la clé privée en `chmod 644` là où
    `generate-jwt-keys.sh:35` la place en `600` — cette différence est-elle
    intentionnelle ?
19. `INGEST_PASSWORD` vaut `Un1que$$trongPassword2024` : le `$$` se résout-il à la valeur
    par défaut d'`ADMIN_PASSWORD` à l'exécution, et le même identifiant est-il voulu pour
    les deux usages ?
20. La rotation d'`APP_SECRET` rend illisibles tous les DSN SMTP chiffrés
    (`SmtpDsnEncryptor.php:22-23`) : une procédure de rotation existe-t-elle hors dépôt ?

**Sur le chiffrement au repos et l'infrastructure**
21. `docs/04_security_guardrails.md:222` annonce un « Infrastructure-layer encryption
    (volume/disk) » : le chiffrement de volume est-il configuré quelque part dans le
    déploiement, ou relève-t-il entièrement de l'opérateur sans artefact dans ce dépôt ?
22. Un proxy sortant est-il imposé au niveau du démon Docker ou de l'hôte, puisqu'aucun
    n'est configuré dans le dépôt (`framework.yaml:17-18` sans `proxy`) ?
23. `/api/metrics` est-il effectivement protégé en production, et quel est le « admin
    scrape token » évoqué dans `infra/monitoring/prometheus/alert.rules.yml` ?

**Sur la CI et la chaîne logicielle**
24. Quels sont les réglages de protection de branche sur `main` (contrôles requis,
    exigence de signature, nombre de revues) ? Non déterminable depuis les fichiers.
25. Les ~97 tests fonctionnels sont-ils exécutés dans un pipeline automatisé quelconque,
    ou seulement en local via `make test`, comme l'indique `ci.yml:103-108` ?
26. Quel est le taux de couverture réellement mesuré, et Codecov bloque-t-il des PR
    étant donné `require_ci_to_pass: false` et `fail_ci_if_error: false` ?
27. Le SBOM CycloneDX produit à chaque exécution est-il conservé, signé ou publié
    au-delà des 30 jours de rétention d'artefact GitHub ?

**Sur les composants non lus dans cette passe**
28. Les 7 classes de `src/Application/Auth/Oidc/` comportent-elles des contrôles
    déterministes (TTL de nonce/état, épinglage d'émetteur et d'audience, rôle par défaut
    au provisionnement) qui devraient figurer au §4 ?
29. `scripts/check-credentials.py` ne porte aucun en-tête : que valide-t-il exactement ?
30. Que fait `.github/scripts/create-ci-env.sh`, invoqué par tous les jobs CI mais non lu ?
31. `TestReplyGenerateCommand.php` : quel est son nom de commande et sa description
    déclarés ?
32. `RSPAMD_URL` est inutilisé dans ce dépôt alors que le DTO d'ingestion porte des champs
    `rspamd` (`IngestRawRequestDto.php:30`) : existe-t-il un composant d'ingestion externe
    hors dépôt qui appelle rspamd ?
33. `.env.dist:146-148` décrit un « IMAPFlow Watcher » sans code ni service correspondant :
    ce composant est-il encore déployé, et où vit son code ?

---

*Fin de la phase 0.*

