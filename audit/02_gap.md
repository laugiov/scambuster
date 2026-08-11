# Phase 2 — Analyse d'écart

> **Cadre.** Scénario pilote **S2** — entité régulée NIS2, entité essentielle,
> auto-hébergée, périmètre **UE sans hypothèse nationale**. L'objet analysé est **la
> configuration livrée par défaut** (`.env.dist`, `docker-compose.prod.yml`,
> documentation), c'est-à-dire ce qu'obtient un tiers déployeur — et non le
> déploiement expérimental du mainteneur.
>
> **Règle appliquée.** Aucun écart sans source normative citée avec texte et article.
> Les jugements d'architecte sans référentiel sont relégués en section « Avis non
> opposables » et **ne comptent pas** comme écarts.
>
> **Sources mobilisées** (établies en phase 1B, `audit/01_scope.md` §B) :
> Règlement (UE) 2024/1689 art. 50(1) · Directive (UE) 2022/2555 art. 20, 21(1),
> 21(2)(a) à (j), 23 · Règlement (UE) 2016/679 art. 5, 6, 9, 14, 16, 17, 32, 35 ·
> Règlement (UE) 2024/2847.
>
> **Échelle de gravité.** *Bloquant* = un tiers déployeur régulé ne peut pas mettre
> en service sans traiter le point. *Majeur* = mise en service possible sous réserve
> documentée, correction attendue au premier cycle. *Modéré* = à traiter au fil de
> l'eau. *Mineur* = dette identifiée.

---

## 1. Transparence du système d'IA

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-01** | Un système d'IA destiné à interagir directement avec des personnes physiques doit être **conçu et développé** de façon que la personne soit informée qu'elle interagit avec un système d'IA. Obligation du **fournisseur**. L'exemption est réservée aux systèmes autorisés par la loi à détecter, prévenir, rechercher ou poursuivre des infractions pénales | Règlement (UE) 2024/1689, **art. 50(1)** — applicable depuis le **2 août 2026** | Trois mécanismes imposent l'inverse : `src/Application/LLM/PolicyGuard.php:47-54` (`FORBIDDEN_PATTERNS`, 6 motifs bloquant `I am a bot`, `automated system`, `artificial intelligence`) ; `src/Application/LLM/Prompt/BasePromptRules.php:41` (règle CORE déclarée non surchargeable) ; `src/Application/Guard/SafetyInvariantOracle.php:153-165` (code `automation_reveal` traité comme violation par la porte GUARD) | **Aucune divulgation n'est possible, et l'absence de divulgation est activement défendue par trois contrôles indépendants.** L'exemption ne couvre pas une entité privée NIS2 | **Bloquant** | L'exemption pourrait être élargie par une autorisation nationale accordée au déployeur, ou la clause « unless this is obvious » pourrait être retenue si un régulateur jugeait qu'un escroc professionnel opérant à grande échelle est réputé averti. Aucune de ces deux lectures n'est acquise, et la seconde est contredite par la conception même du produit |
| **G-02** | Lorsque la divulgation est exigée, elle doit être perceptible dans l'interaction elle-même, et non enfouie dans des conditions générales | Règlement (UE) 2024/1689, art. 50(1) ; lignes directrices de la Commission du **20 juillet 2026** | Aucun mécanisme de divulgation n'existe dans le chemin de composition : `src/Application/Communication/ReplyCompositionService.php:311` compose et envoie sans en-tête ni mention ajoutée | Pas de point d'insertion pour une mention de nature artificielle dans le courriel sortant | **Bloquant** (dépendant de G-01) | Techniquement trivial une fois G-01 tranché ; ne mérite pas d'être traité tant que la question de principe est ouverte |

---

## 2. Souveraineté de l'inférence et sortie de la dépendance à une API externe

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-03** | Le responsable de traitement met en œuvre des mesures techniques garantissant la confidentialité des données, et n'a recours qu'à des sous-traitants présentant des garanties suffisantes | Règlement (UE) 2016/679, **art. 32(1)** et **art. 28(1)** ; Directive (UE) 2022/2555, **art. 21(2)(d)** (sécurité de la chaîne d'approvisionnement) | Le corps intégral des courriels de tiers est transmis à `%llm.api_url%`, défaut `https://api.openai.com/v1` (`.env.dist:307`, `src/Infrastructure/LLM/Provider/OpenAIClient.php:48`) | Le contenu personnel de tiers — dont des données potentiellement relevant de l'art. 9 — sort du périmètre de l'entité vers un fournisseur d'inférence externe, par **défaut de livraison** | **Bloquant** | Le transfert peut être couvert contractuellement par un accord de sous-traitance et une clause de non-entraînement ; beaucoup d'entités régulées opèrent déjà ainsi. L'argument tient pour la licéité, mais pas pour la démontrabilité — voir G-04 |
| **G-04** | Les mesures doivent être **vérifiables** : l'entité doit pouvoir établir qu'aucune donnée ne sort | Règlement (UE) 2016/679, art. 32(1)(d) (procédure de test et d'évaluation régulière) ; Directive (UE) 2022/2555, art. 21(2)(f) | **7 sites d'appel codent un identifiant de modèle OpenAI en dur** : `ReplyValidator.php:103`, `OperationalLeakageDetector.php:28`, `PaymentInstigationGuard.php:50`, `ConversationAnalyzer.php:27`, `ConversationHistoryService.php:229`, `ConversationQualityAuditor.php:77`, `EmbeddingService.php:18` | **Positionner `LLM_PROVIDER=ollama` ne bascule pas l'ensemble du système.** L'entité ne peut pas démontrer l'absence de sortie, même en configurant un fournisseur local | **Bloquant** | Un tiers averti peut auditer lui-même les 7 sites et patcher ; le projet est en MIT. Argument valable pour un déployeur disposant d'ingénierie PHP, nul pour les autres |
| **G-05** | Idem | Idem G-04 | `src/Application/LLM/EmbeddingService.php:20` — constante `API_URL = 'https://api.openai.com/v1/embeddings'`, appelée `:68` via `HttpClientInterface` **direct**, sans passer par `LLMClientInterface` | Le service d'embeddings **n'a aucune abstraction de fournisseur** : il n'est pas basculable, même par configuration | **Bloquant** | Les embeddings ne portent pas de texte en clair en sortie — seulement en entrée. Argument faible : c'est le texte du message qui est transmis pour être vectorisé |
| **G-06** | Idem | Idem G-04 | `src/Infrastructure/LLM/OpenAIService.php:51` — endpoint `https://api.openai.com/v1/chat/completions` en dur, seconde interface `LLMServiceInterface` coexistant avec le port hexagonal (`config/services.yaml:525-532`) | **Deux abstractions LLM concurrentes**, dont une non basculable, câblée au générateur préprod | **Majeur** | Ce chemin ne sert que la génération de données de préproduction, jamais la production. Argument recevable — à condition que le service ne soit pas déployé, ce que le profil compose `preprod` ne garantit pas |

---

## 3. Zonage et isolation de la couche d'engagement

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-07** | Politiques de sécurité des systèmes d'information et d'analyse de risque, incluant la segmentation | Directive (UE) 2022/2555, **art. 21(2)(a)** | Un seul réseau bridge `scambuster` (`docker-compose.yml:261-262`) ; aucun `internal: true` ; aucune restriction `network_mode` | **Aucune segmentation entre la zone d'engagement (n8n, IMAP, SMTP) et la zone de traitement (PostgreSQL, Redis, backend).** Le composant qui parle à des adversaires partage le réseau du magasin de données | **Bloquant** | Un déployeur régulé refera de toute façon son propre réseau ; le compose livré est un modèle de développement. Argument sérieux — mais `docker-compose.prod.yml` est présenté comme la cible de production et porte le même défaut |
| **G-08** | Idem, volet contrôle des flux sortants | Directive (UE) 2022/2555, art. 21(2)(a) | `framework.http_client` activé sans `proxy` ni client à portée restreinte (`config/packages/framework.yaml:17-18`) ; les seules occurrences de `no_proxy` sont dans le stub auto-généré `config/reference.php:484,537`, non appliqué | **Aucune liste blanche de sortie ni proxy sortant.** Toute vulnérabilité d'exécution de code dans la zone de traitement dispose d'une sortie Internet libre | **Bloquant** | Le filtrage de sortie relève classiquement de l'infrastructure hôte, pas de l'applicatif. Argument recevable, à condition que le produit **documente** la liste des destinations légitimes — ce qu'il ne fait pas |
| **G-09** | Sécurité des ressources humaines, politiques de contrôle d'accès et gestion des actifs | Directive (UE) 2022/2555, **art. 21(2)(i)** ; politique d'usage de la cryptographie, **art. 21(2)(h)** | n8n détient les identifiants IMAP/SMTP de production, chiffrés par `N8N_ENCRYPTION_KEY` dans `./data/n8n` (`docker-compose.yml:248` ; `.github/scripts/check-no-vault-resurrection.sh:5-8` : « n8n now stores its own IMAP credentials ») | **Le composant le plus exposé détient les identifiants de production**, dans le même réseau que la base | **Majeur** | C'est le résultat d'une décision documentée et assumée — le retrait de Vault en avril 2026 — motivée par le fait que Vault était du code mort. Revenir en arrière réintroduirait la complexité qui avait justifié le retrait |
| **G-10** | Sécurité de l'acquisition, du développement et de la maintenance, incluant le traitement des vulnérabilités | Directive (UE) 2022/2555, **art. 21(2)(e)** | `src/Application/Communication/EmailParsingService.php:275` — `$mimeType = $part->getContentType() ?? 'application/octet-stream'` ; plafond de taille à 25 Mo (`:27`), exclusion des parties `inline` (`:308-314`) | **Aucune liste blanche ni noire de types MIME** sur les pièces jointes provenant d'adversaires, traitées dans la zone exposée | **Majeur** | Le contenu binaire n'est pas persisté par l'application (§7.3) et seul le texte océrisé est conservé ; la surface d'exploitation est donc celle du parseur MIME, à jour (`zbateson/mail-mime-parser 3.0.5`) |

---

## 4. Intégrité et chaîne de preuve des IOC jusqu'au SIEM

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-11** | Les données doivent être adéquates, pertinentes et limitées à ce qui est nécessaire | Règlement (UE) 2016/679, **art. 5(1)(c)** | Le blocage d'export financier couvre **10 types** (`src/Domain/Communication/IocCategory.php:33-43`) sur ~36 extraits (`IocExtractor.php:30-58`). Export TAXII **actif vers OpenCTI** (confirmé par le commanditaire) | **Les domaines, URL, IP et adresses courriel — l'essentiel du flux CTI — sortent sans verdict humain**, alors qu'ils peuvent désigner des tiers innocents | **Bloquant** | Ces types sont l'objet même d'un flux CTI ; les retenir tous derrière un verdict humain rendrait le produit inexploitable à une personne. L'argument est fort et impose une réponse graduée, non un blocage total |
| **G-12** | Droit de rectification et droit à l'effacement, y compris auprès des destinataires auxquels les données ont été communiquées | Règlement (UE) 2016/679, **art. 16, 17(2) et 19** | Aucun identifiant de nœud d'origine ni mécanisme de rétractation dans le chemin d'export : `src/Application/Taxii/TaxiiService.php:291`, `src/Application/Export/IocFeedExporter.php:219` | **Un IOC exporté vers OpenCTI est irrécupérable.** L'entité ne peut pas satisfaire une demande d'effacement portant sur une donnée déjà partagée | **Majeur** | Le partage CTI opère traditionnellement en mode pousser-oublier ; aucun standard TAXII n'impose la rétractation. Argument recevable sur l'état de l'art, pas sur l'obligation |
| **G-13** | Les données ne sont conservées que le temps nécessaire | Règlement (UE) 2016/679, **art. 5(1)(e)** | **La table `indicator` n'a aucune routine de purge** ; elle n'est pas atteinte par la cascade de suppression de conversation (aucune clé étrangère vers `message`) — §7.7 | Les valeurs d'IOC — adresses courriel, IBAN, téléphones, IP de tiers — sont conservées **sans limite** | **Majeur** | La valeur de renseignement d'un IOC est précisément sa persistance ; `docs/09_dpia_template.md:38` revendique explicitement une conservation indéfinie pour les TTP « as IOCs ». Le débat porte sur la qualification, pas sur le mécanisme |
| **G-14** | Idem G-03 : garanties sur les destinataires | Règlement (UE) 2016/679, art. 5(1)(c) et **art. 44** (transferts vers des pays tiers) | `n8n/workflows/WF-EXTRACT-AND-ENRICH-IOC.json:114,136,224` — la valeur d'IOC fournie par l'adversaire est soumise à **urlscan.io** et **VirusTotal** | Transmission de données possiblement personnelles à deux services tiers, hors de tout contrôle de l'entité et **hors du périmètre observé par PolicyGuard et par la porte GUARD** | **Majeur** | Ces deux services sont l'outillage standard d'un SOC ; leur usage est banal et documenté. Argument solide pour la pratique, faible pour la démonstration de conformité — et il n'adresse pas le canal latéral de découverte du honeypot (menace T3) |
| **G-15** | Traitement des incidents : détection, réponse, signalement | Directive (UE) 2022/2555, **art. 21(2)(b)** et **art. 23** | `SIEM_PROVIDER=none` par défaut (`.env.dist:421`) ; adaptateurs `file`, `syslog`, `null` disponibles (`src/Infrastructure/Siem/SiemCompilerPass.php:51-67`) | **À l'installation, aucun événement de sécurité ne sort du système.** Le déployeur doit découvrir et activer le connecteur | **Majeur** | Un défaut `none` est le choix conservateur : il évite d'écrire vers un collecteur non configuré. Argument légitime — mais il devrait alors être accompagné d'un refus de démarrage en production, comme le fait déjà `CheckSecretsCommand` pour les secrets |

---

## 5. Auditabilité et immuabilité des journaux

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-16** | Garantir l'intégrité des données traitées | Règlement (UE) 2016/679, **art. 32(1)(b)** ; Directive (UE) 2022/2555, art. 21(2)(a) | `backend-symfony/migrations/Version2026041200100000.php:20-24` renvoie le `REVOKE UPDATE/DELETE ON audit_log` à une étape d'exploitation « documented in `docs/runbooks/audit-hmac-key-rotation.md` » ; **`rg -n "REVOKE" docs/` ne renvoie aucun résultat** | **La table d'audit n'est immuable dans aucun environnement**, et l'étape qui la rendrait telle n'est documentée nulle part | **Majeur** | La chaîne HMAC détecte déjà toute altération ; le `REVOKE` n'ajoute qu'une défense en profondeur. Argument valable **si** la vérification était bloquante — or `scheduler.sh:95` journalise `CRITICAL` et **poursuit la boucle** |
| **G-17** | Politiques relatives à l'usage de la cryptographie | Directive (UE) 2022/2555, **art. 21(2)(h)** | `src/Application/Audit/AuditHmacChainer.php:19-20` — `AUDIT_HMAC_KEY` lue dans l'environnement du processus applicatif, celui-là même qui écrit les lignes | **La clé de scellement est détenue par le processus scellant** : la propriété obtenue est la détection d'altération accidentelle, non la résistance à un opérateur | **Majeur** | En S2 la finalité n'est pas probatoire (`01_scope.md` §B.4 X5) : le modèle de menace pertinent est l'altération accidentelle et l'intrusion externe, pas l'exploitant malveillant. Argument recevable — c'est ce qui déclasse cet écart de « bloquant » à « majeur » |
| **G-18** | Traitement des incidents et journalisation des accès | Directive (UE) 2022/2555, art. 21(2)(b) et 21(2)(i) | **145 fichiers `*Controller.php` sous `src/UI/Http` ; 12 référencent `auditLogger`.** Sans entrée d'audit : `DeleteConversationController`, `DeleteMessageController`, `UploadAttachmentController`, `DownloadAttachmentController`, `ExportIocsStixController`, `ExportConversationStixController`, `ExportIocsFeedController`, `SendEmailController`, les 4 contrôleurs TAXII, `ExportClusterStixController` | **Les suppressions et 4 des 6 surfaces d'export n'émettent aucun audit.** `SECURITY.md:38` affirme pourtant « All operations logged for traceability » | **Majeur** | Les exports sont tracés indirectement par les journaux d'accès HTTP du reverse proxy. Argument faible : ces journaux ne portent ni l'identité de l'acteur applicatif, ni le volume exporté, ni les identifiants d'IOC concernés |
| **G-19** | Minimisation, et sécurité du traitement | Règlement (UE) 2016/679, art. 5(1)(c) et art. 32 | `src/Application/LLM/ReplyValidator.php:144` — `'response' => $response`, **texte de complétion intégral, non tronqué**, écrit dans le journal applicatif sur `JsonException`. Trois autres sites tronquent à 200 ou 500 caractères (`ScamClassifier.php:69`, `ConversationAnalyzer.php:852`, `ContextualEnricher.php:68`) | Du texte généré, susceptible de contenir du contenu dérivé de données personnelles, part dans le journal applicatif — qui en production sort sur **`php://stderr`** sans rotation configurée (`config/packages/monolog.yaml:73-77` ; aucun bloc `logging:` dans les fichiers compose) | **Modéré** | Le chemin n'est atteint que sur JSON malformé, cas rare. Argument recevable sur la fréquence, pas sur la nature |
| **G-20** | Idem | Règlement (UE) 2016/679, art. 32 | `src/Infrastructure/EventListener/Security/PiiMaskingProcessor.php:18-19` — docblock : « Does NOT affect the audit_log database table » ; s'applique à `message` et `context` uniquement (`:38-40`) | Le masquage PII **ne couvre pas la table d'audit**, qui stocke pourtant `ip_address` et un champ `details` JSON libre (`AuditLog.php:55,61`) | **Modéré** | La table d'audit doit précisément conserver l'identité de l'acteur pour être utile ; masquer y serait contre-productif. Argument juste pour `actor_id`, discutable pour `details` dont le contenu n'est pas borné |

---

## 6. Identités et accès

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-21** | Politiques de contrôle d'accès ; séparation des rôles | Directive (UE) 2022/2555, **art. 21(2)(i)** ; approbation et supervision par l'organe de direction, **art. 20** | `src/UI/Http/Communication/SendEmailController.php:48` et `MarkReplySentController.php:66` sont gardés par `#[IsGranted('reply:generate')]` — **la même permission que la génération**. Confirmé par le commanditaire : l'envoi est automatisé, sans validation humaine | **Aucune séparation entre produire un brouillon et engager l'entité auprès d'un tiers.** Un principal n8n porteur d'une seule permission fait les deux | **Bloquant** | L'automatisation intégrale est la proposition de valeur du produit ; imposer une validation humaine à chaque message détruirait le débit et la cadence naturelle. Argument fort — il commande une séparation de **permissions** plutôt qu'une validation systématique |
| **G-22** | Idem, volet gestion des secrets | Directive (UE) 2022/2555, art. 21(2)(h) et (i) | `src/Security/SecretPolicy.php:67-69` — retourne `[]` immédiatement hors production ; `CheckSecretsCommand.php:37-45` contrôle **7 variables**. Non contrôlées : `POSTGRES_PASSWORD` (défaut `postgres`), `LOGIN_HASH_SALT`, `RSPAMD_PASSWORD`, `INGEST_PASSWORD`, `HONEYPOT_IMAP_PASSWORD`, `LLM_API_KEY`, `OIDC_CLIENT_SECRET`, `TAXII_API_KEY`, `MISP_API_KEY` | **7 variables contrôlées sur 24 d'apparence secrète.** `POSTGRES_PASSWORD=postgres` passe le contrôle de démarrage en production | **Majeur** | Le contrôle est explicitement conçu comme un garde-fou minimal (« It only *strengthens* posture », `:8-18`) et la base n'est pas exposée en production (`docker-compose.prod.yml:42`, sans `ports:`). Argument recevable pour PostgreSQL, pas pour `HONEYPOT_IMAP_PASSWORD` ni `LLM_API_KEY` |
| **G-23** | Recours à l'authentification multifacteur | Directive (UE) 2022/2555, **art. 21(2)(j)** | TOTP disponible (`config/packages/scheb_2fa.yaml:1-8`), OIDC disponible et **opt-in** (`OIDC_ENABLED`, `.env.dist:75`) | [DÉDUIT] Rien dans la configuration livrée n'impose l'authentification multifacteur pour les comptes opérateur ; elle est offerte, non exigée. Raisonnement : `OIDC_ENABLED` est un drapeau, et aucun contrôle d'accès de `security.yaml` ne requiert `IS_AUTHENTICATED_2FA` | **Majeur** | Le produit fournit les deux mécanismes ; leur activation relève de la politique du déployeur. Argument recevable — l'écart porte alors sur l'absence de refus de démarrage en production sans MFA active |

---

## 7. Cycle de vie logiciel — SBOM, CVE, avis de sécurité, versions

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-24** | Signalement des vulnérabilités activement exploitées et des incidents graves, par produit et par version | Règlement (UE) 2024/2847 — obligations de signalement applicables au **11 septembre 2026** | `git tag -l | wc -l` → **0** ; aucun workflow de publication ; `CHANGELOG.md:9` en tête `## [Unreleased]` ; `SECURITY.md:5-7` : versions supportées = « main \| Yes » | **Aucune version identifiable.** L'éditeur ne peut désigner ni la version affectée par une vulnérabilité, ni celle qui la corrige ; le déployeur ne peut pas déclarer ce qu'il exécute | **Bloquant** | ScamBuster pourrait relever de l'exemption logiciel libre du CRA, auquel cas l'obligation ne pèse pas sur l'éditeur. Point non tranché — il figure en question ouverte, et il ne dispense pas du besoin opérationnel de versionner |
| **G-25** | Sécurité de la chaîne d'approvisionnement — l'entité doit tenir compte des vulnérabilités des tiers et de la sécurité des produits intégrés | Directive (UE) 2022/2555, **art. 21(2)(d)** | Un SBOM CycloneDX **est** produit par l'action Trivy (`.github/workflows/ci.yml:332-337`) mais **uniquement en artefact CI conservé 30 jours** (`:339-344`) ; jamais attaché à une version, jamais publié | **Le déployeur ne peut pas obtenir le SBOM de ce qu'il exécute.** Le mécanisme existe, la distribution manque | **Bloquant** | Un déployeur peut regénérer le SBOM lui-même depuis la source. Argument valable techniquement, nul contractuellement : il ne prouve pas la correspondance avec l'artefact déployé |
| **G-26** | Idem | Directive (UE) 2022/2555, art. 21(2)(d) | **Aucune image n'est épinglée par `@sha256:`** ; `nginx:alpine` en tag flottant (`infra/docker/demo/Dockerfile.frontend:20`) ; `postgres:15-alpine`, `redis:7-alpine`, `node:20-alpine` en tags mobiles. Les actions GitHub, elles, **sont** épinglées par SHA (`ci.yml:18,181,270,311,317,333,340`) | Deux constructions du même commit peuvent produire deux images différentes | **Majeur** | L'épinglage par empreinte fige aussi les correctifs de sécurité de l'image de base, et les Dockerfiles exécutent `apt-get upgrade` à la construction (`.trivyignore:6-7`). C'est un arbitrage assumé et défendable, mais il n'est écrit nulle part |
| **G-27** | Idem | Directive (UE) 2022/2555, art. 21(2)(d) | `.github/workflows/ci.yml:252-255` — Gitleaks v8.21.2 téléchargé par tag depuis les releases GitHub, **sans vérification de somme de contrôle** | Un outil de sécurité entre dans la chaîne de construction sans contrôle d'intégrité | **Modéré** | L'outil ne produit pas d'artefact livré ; sa compromission fausserait un contrôle sans altérer le produit. Argument juste, portée limitée |
| **G-28** | Politiques et procédures d'évaluation de l'efficacité des mesures | Directive (UE) 2022/2555, **art. 21(2)(f)** | `backend-symfony/phpunit.ci.xml` ne déclare **aucune suite `functional`** ; motif inscrit dans `ci.yml:103-108`. **97 fichiers de test fonctionnel** ne sont exécutés dans aucun pipeline | Le tiers de la couverture de test portant sur les contrôleurs n'est jamais exécuté automatiquement | **Majeur** | Le motif documenté est technique et honnête — nommer la suite faisait passer ~855 tests de contrôleur en silence. Le contournement est légitime ; c'est son caractère durable qui pose problème |
| **G-29** | Idem | Directive (UE) 2022/2555, art. 21(2)(f) | `codecov.yml:2` — `require_ci_to_pass: false` ; cibles `auto` (`:6-11`) ; envoi CI en `fail_ci_if_error: false` (`ci.yml:184`) ; **aucun seuil numérique de couverture** dans `phpunit.xml.dist` ni `phpunit.ci.xml` | Aucune régression de couverture ne peut faire échouer une intégration | **Modéré** | Les seuils de couverture produisent surtout du test de complaisance ; leur absence est un choix d'ingénierie répandu et défendable |

---

## 8. Mode dégradé et reprise

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-30** | Continuité d'activité, gestion des sauvegardes, reprise sur sinistre | Directive (UE) 2022/2555, **art. 21(2)(c)** | Trois gardes échouent **ouvert** sur la même cause — indisponibilité du fournisseur d'inférence : `OperationalLeakageDetector.php:59-89` (retourne « pas de fuite »), `PaymentInstigationGuard.php:162-179` (approuve hors des 12 jetons de repli), `ReplyHandler.php:104-110` (le brief du director retourne `null`, « replies are never blocked by this gate ») | **Une panne unique dégrade simultanément trois contrôles de sécurité, sans que le service s'arrête.** Le repli déterministe résiduel se limite à 12 motifs de vocabulaire de paiement | **Bloquant** | Chaque échec ouvert est individuellement justifié dans le code, avec un motif explicite (`OperationalLeakageDetector.php:21-24` : « The hard gate is the PolicyGuard regex deny-list … it MUST fail open »). L'argument tient garde par garde ; il ne tient plus pour les trois ensemble, car la cause de panne est commune |
| **G-31** | Traitement des incidents | Directive (UE) 2022/2555, art. 21(2)(b) | `src/Application/Communication/PromptInjectionDetector.php:18` — « Detection is forensic -- it does not block ingestion or modify the reply pipeline » ; `IngestPostProcessor.php:564-577` émet un audit d'issue **`'blocked'`** alors que rien n'est bloqué | **L'enregistrement d'audit contredit le comportement réel.** Un analyste lisant le journal conclura qu'une injection à haut risque a été neutralisée | **Majeur** | Le champ `outcome` peut se lire comme « l'événement a été classé bloquant », non « l'action a été bloquée ». Lecture défendable en interne, indéfendable dans un dossier d'incident |
| **G-32** | Sauvegardes et reprise | Directive (UE) 2022/2555, art. 21(2)(c) | `infra/docker/backend/scheduler.sh:101-124` — `pg_dump` quotidien, vérification de **taille** (`:109`), rotation à 7 jours (`:112`) | [DÉDUIT] Aucune procédure de restauration testée n'existe dans le dépôt : la vérification porte sur la taille du fichier, pas sur sa restaurabilité. Raisonnement : recherche de `pg_restore` dans `scripts/`, `infra/` et `Makefile` — aucun résultat | **Modéré** | Une sauvegarde non restaurée reste une sauvegarde ; le test de restauration est classiquement une procédure d'exploitation, pas un livrable produit |
| **G-33** | Idem | Directive (UE) 2022/2555, art. 21(2)(c) | `scheduler.sh:112` — rétention des sauvegardes à **7 jours**, contenant l'intégralité des données, y compris celles logiquement supprimées | [DÉDUIT] La rétention des sauvegardes et la politique de conservation des données ne sont pas articulées : une donnée supprimée logiquement à 90 jours subsiste dans les dumps jusqu'à 7 jours après. Raisonnement : les deux mécanismes n'ont aucun point de contact dans le code | **Mineur** | L'écart de 7 jours est court et l'articulation sauvegarde/purge est un problème classique sans solution élégante |

---

## 9. Protection des données — conservation, minimisation, information

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-34** | Limitation de la conservation | Règlement (UE) 2016/679, **art. 5(1)(e)** | `app:purge:rgpd` — seule commande appliquant la suppression logique à 6 mois et **la seule du code source qui supprime physiquement** (`PurgeService.php:25,55`) — **n'apparaît pas dans `infra/docker/backend/scheduler.sh`** (ni `:16-24`, ni `:40-147`) | **La rétention 6/12 mois annoncée n'est jamais appliquée.** La commande planifiée, `app:cleanup:weekly`, supprime logiquement à 90 jours et ne supprime rien physiquement | **Bloquant** | Le déployeur peut planifier la commande lui-même. Argument recevable **si** elle était documentée comme à planifier — or `docs/compliance/gdpr-record-of-processing.md:53` affirme l'inverse : « `app:cleanup:weekly`, automatic » |
| **G-35** | Idem | Règlement (UE) 2016/679, art. 5(1)(e) | **Aucune routine d'anonymisation, de pseudonymisation ou de caviardage n'existe** dans `src/`. Les deux seules opérations sont « positionner `deleted_at` » et « DELETE physique » | `docs/09_dpia_template.md:33` et `:146` annoncent une anonymisation à 6 mois qui n'a aucune implémentation | **Majeur** | L'anonymisation d'un corps de courriel libre est un problème ouvert ; la suppression physique est un substitut plus sûr que sa mauvaise implémentation. Argument technique solide — l'écart porte alors sur l'annonce, non sur l'absence |
| **G-36** | Idem | Règlement (UE) 2016/679, art. 5(1)(e) et art. 9 | La table `threat_actor_psych_profile` (`migrations/Version2026070600000000.php:29-36`) stocke `behavioural_summary`, `victim_targeting`, `escalation_pattern` — **profil psychologique d'un tiers généré par LLM**. Aucune purge ; aucune clé étrangère vers les conversations | Des profils comportementaux de personnes physiques sont conservés sans limite et **ne figurent dans aucun registre de traitement** — ni `gdpr-record-of-processing.md`, ni `09_dpia_template.md` | **Bloquant** | Un profil de mode opératoire adverse est du renseignement, non un profil de personne. Argument défendable **jusqu'au moment où** le cluster est rattaché à un individu identifiable — ce que le clustering par IOC fait précisément |
| **G-37** | Information des personnes lorsque les données n'ont pas été collectées auprès d'elles ; exception d'effort disproportionné d'interprétation stricte, assortie de mesures appropriées dont la mise à disposition publique de l'information | Règlement (UE) 2016/679, **art. 14(1), 14(2), 14(5)(b)** | Aucun mécanisme d'information des tiers cités dans les échanges. Aucune mise à disposition publique d'information de traitement | Les tiers — victimes, mules, personnes citées — ne sont ni informés, ni couverts par une mesure de substitution | **Majeur** | L'exception d'effort disproportionné est plausible : ces personnes ne sont pas identifiables de façon fiable depuis un courriel d'escroc. Argument sérieux — mais l'exception impose des **mesures appropriées**, dont une information publique, qui n'existe pas |
| **G-38** | Analyse d'impact relative à la protection des données | Règlement (UE) 2016/679, **art. 35** | `docs/09_dpia_template.md` — le nom du fichier porte « template » ; le document décrit des contrôles non implémentés (anonymisation, 16 motifs, RLS) | [DÉDUIT] Le livrable est un gabarit et non une analyse conduite, et il décrit un système qui n'est pas celui du code. Un déployeur qui s'en servirait produirait une AIPD fausse. Raisonnement : trois affirmations vérifiablement contredites par le code (DOC-05, DOC-09, DOC-11) | **Bloquant** | Un gabarit est exactement ce qu'un éditeur doit fournir — l'AIPD incombe au responsable de traitement. Argument juste sur le principe ; il ne couvre pas le fait que le gabarit décrive des contrôles inexistants |
| **G-39** | Traitement des catégories particulières de données | Règlement (UE) 2016/679, **art. 9(1)** | Aucun tri ni détection de données de l'art. 9 à l'ingestion. `MessageAnonymizer` (`:23-37`) masque 5 motifs, **et uniquement pour la construction de prompt** (`ContextualEnricher.php:26`), jamais pour le stockage | Des données de santé, d'opinion ou d'orientation présentes dans un corps de courriel ou une pièce jointe océrisée sont stockées sans qualification ni exception de l'art. 9(2) | **Majeur** | La détection automatique de données de l'art. 9 en texte libre est peu fiable et produirait autant de faux négatifs. Argument technique recevable ; il commande une réponse par la minimisation à l'ingestion plutôt que par la détection |

---

## 10. Documentation d'homologation

| ID | Exigence | Source normative | État actuel [VÉRIFIÉ] | Écart | Gravité | Meilleur argument pour ne pas le traiter maintenant |
|---|---|---|---|---|---|---|
| **G-40** | Politiques et procédures permettant d'évaluer l'efficacité des mesures de gestion des risques | Directive (UE) 2022/2555, **art. 21(2)(f)** ; documentation technique du produit, Règlement (UE) 2024/2847 | **24 contradictions documentation/code prouvées** (`00_inventory.md` §11), dont : contrôles annoncés sans implémentation (DOC-07 « drogues, armes, CSAM » ; DOC-11 politiques RLS), comptages faux (DOC-06 permissions, DOC-09 motifs), chiffrement au repos annoncé et absent (DOC-19), rétention annoncée automatique et non planifiée (DOC-03, DOC-04) | **Le dossier documentaire ne peut pas servir de base à une acceptation de risque** : un déployeur qui s'y fie décrira un système qui n'existe pas | **Bloquant** | La documentation est explicitement signalée comme partiellement périmée par le commanditaire lui-même ; le code fait foi et il est lisible. Argument recevable pour un déployeur disposant d'ingénierie PHP, nul pour une acceptation de risque signée par un organe de direction |
| **G-41** | Idem | Idem | `migrations/Version2026041200100000.php:20-24` référence une étape d'exploitation dans un runbook qui ne la contient pas ; `docs/runbooks/audit-hmac-key-rotation.md:32-33` référence un « rebuild script » qui n'existe nulle part | Deux procédures d'exploitation critiques renvoient à une documentation inexistante | **Majeur** | Écart de faible coût de correction ; sa gravité tient à ce qu'il porte précisément sur les deux procédures qui conditionnent l'intégrité du journal |

---

## Avis non opposables

> Jugements d'architecte que **je ne rattache à aucun référentiel**. Ils ne sont pas
> des écarts et ne doivent pas être traités comme tels.

| # | Avis | Fondement factuel |
|---|---|---|
| A-01 | Le classement de `payment_token` en code informationnel, avec un baseline à **0,294**, signifie que ~29 % des sorties de référence contiennent du vocabulaire d'infrastructure de paiement et qu'une hausse ne peut jamais faire échouer la porte GUARD. C'est un choix de conception que je trouve difficile à défendre, mais aucun référentiel ne l'interdit | `tests/Smoke/guard-baseline.json` ; `SafetyInvariantOracle.php:70-72` ; `CanaryBaselineComparator.php:116-119` |
| A-02 | Les 25 motifs de détection d'injection sont **anglophones** alors que le corpus de fixtures est EN/FR/DE/ES. Déséquilibre net, sans obligation normative de couverture linguistique | `PromptInjectionPatternMatcher.php:19-124` ; fixtures `tests/Smoke/CialdiniMirrorFixtures/` |
| A-03 | La normalisation homoglyphe **se dégrade silencieusement** si l'extension intl est absente (`:273-274`), sans que rien ne le signale au démarrage. Défaut de conception, pas de conformité | `PromptInjectionPatternMatcher.php:273-274` |
| A-04 | Deux abstractions LLM concurrentes et deux mécanismes de chiffrement de champ avec deux sources de clé distinctes (`APP_SECRET` dérivé, `TOTP_ENCRYPTION_KEY` direct) constituent une dette de cohérence | `config/services.yaml:525-532` ; `SmtpDsnEncryptor.php:43` ; `EncryptedStringType.php:97` |
| A-05 | Le filtre d'IP privées ne couvre que 4 plages — ni `169.254/16`, ni `100.64/10`, ni les plages privées IPv6. Qualité d'extraction, pas conformité | `IocExtractorOrchestrator.php:285-299` |
| A-06 | La rotation d'`APP_SECRET` rend illisibles tous les DSN SMTP chiffrés, et le code le reconnaît en renvoyant à une « future spec ». Dette assumée et documentée | `SmtpDsnEncryptor.php:22-23` |
| A-07 | `getQuarantinedCount()` est un stub retournant 0, ce qui rend la mise en quarantaine invisible à la supervision | `SenderFloodDetector.php:84-89` |
| A-08 | `MIN_HOURS_BETWEEN_REPLIES = 6` fixe crée une signature temporelle exploitable à l'échelle de plusieurs conversations. Menace T1 réelle, mais **rendue sans objet par G-01** si la transparence est imposée | `ReplyCadenceService.php:27` |

---

## Les 5 écarts réellement bloquants pour le scénario pilote

Sélection sous contrainte : ce sont les cinq points **sans lesquels un tiers déployeur
régulé ne peut pas mettre en service**, ordonnés par gravité décroissante.

| Rang | Écart | Regroupe | Pourquoi celui-ci et pas un autre |
|---|---|---|---|
| **1** | **G-01/G-02 — Transparence du système d'IA** | G-01, G-02 | Seul écart qui met en cause la **viabilité du produit** dans le scénario retenu, et non sa qualité. Obligation applicable depuis le 2 août 2026, pesant sur l'**éditeur**, et à laquelle trois mécanismes du code s'opposent activement. Tous les autres écarts se corrigent par de la conception ; celui-ci exige d'abord une décision de principe |
| **2** | **G-21 — Aucune séparation entre générer et envoyer** | G-21 | Confirmé par le commanditaire : l'envoi est automatisé. `/send-email` porte la même permission que la génération. Un organe de direction NIS2 doit approuver et superviser les mesures (art. 20) ; il ne peut superviser un acte d'engagement envers un tiers qu'aucun contrôle ne distingue d'une génération de brouillon |
| **3** | **G-03/G-04/G-05 — Souveraineté de l'inférence** | G-03, G-04, G-05 | Le contenu intégral de courriels de tiers sort par défaut, et **positionner un fournisseur local ne suffit pas** : 7 sites en dur et un service d'embeddings sans abstraction. L'entité ne peut pas démontrer la maîtrise du flux, ce qui est distinct de la licéité du transfert |
| **4** | **G-07/G-08 — Zonage et filtrage de sortie** | G-07, G-08 | Le composant qui parle IMAP à des adversaires et télécharge leurs pièces jointes partage le réseau du magasin de données, sans aucun filtrage de sortie. C'est le préalable technique de tous les autres : sans zone, ni G-03 ni G-11 ne sont démontrables |
| **5** | **G-24/G-25 — Version identifiable et SBOM distribué** | G-24, G-25 | Sans tag ni SBOM attaché à une version, le déployeur ne peut ni déclarer ce qu'il exécute au titre de NIS2 art. 21(2)(d), ni recevoir un avis de sécurité utilisable. **Échéance CRA au 11 septembre 2026.** Coût de correction faible, effet de levier élevé sur tout le reste |

### Écarts que j'écarterais du périmètre immédiat

Classés par motif d'écartement, avec l'argument retenu.

| Écarté | Motif |
|---|---|
| **G-30 — échecs ouverts corrélés** | Bloquant sur le fond, mais **conditionné à G-01** : si la transparence est imposée, `OperationalLeakageDetector` change d'objet et le raisonnement sur les échecs ouverts doit être refait. Le traiter avant serait construire sur une hypothèse en cours de révision |
| **G-11 — IOC non financiers sans verdict** | L'argument de non-traitement est fort : retenir tous les types derrière un verdict humain rendrait le produit inexploitable à une personne. Appelle une **réponse graduée** en phase 3, pas un blocage |
| **G-34 — purge non planifiée** | Correction d'une ligne dans `scheduler.sh`. Le blocage réel n'est pas la purge mais **G-40**, la documentation qui affirme le contraire |
| **G-36 — profils psychologiques** | Bloquant sur le fond, mais la qualification — renseignement adverse ou profil de personne — est une **décision juridique du déployeur**, pas un écart produit. À documenter, pas à construire |
| **G-38 — AIPD à l'état de gabarit** | L'AIPD incombe au responsable de traitement. L'écart produit réel est que le gabarit décrit des contrôles inexistants, donc **absorbé par G-40** |
| **G-16, G-17, G-18 — immuabilité et couverture d'audit** | Déclassés par le choix de S2 : les règles de preuve numérique sont écartées du périmètre (`01_scope.md` §B.4 X5). Restent des écarts NIS2 art. 21(2), à traiter au premier cycle, non à la mise en service |
| **G-23 — MFA non imposée** | Les deux mécanismes existent ; l'écart porte sur l'absence de refus de démarrage. Correction triviale, sans effet structurant |
| **G-06, G-27, G-29, G-32, G-33** | Modérés à mineurs, arguments de non-traitement recevables tels quels |
| **G-19, G-20 — PII dans les journaux** | Modérés ; le volume est faible et le chemin rare |
| **G-31 — audit `'blocked'` trompeur** | Correction d'un libellé. Réel, mais sans coût ni structure |

---

## Ce que je n'ai pas pu vérifier — phase 2

1. ScamBuster relève-t-il de l'exemption logiciel libre du Règlement (UE) 2024/2847,
   ou de la catégorie « open source steward » — ce qui déciderait si G-24 est un écart
   normatif ou seulement un besoin opérationnel ?
2. Les lignes directrices de la Commission du 20 juillet 2026 traitent-elles du canal
   asynchrone : où doit figurer la mention de nature artificielle dans un courriel ?
3. Une autorité de protection des données a-t-elle publié une position sur les
   boîtes-appâts et l'engagement automatisé avec un expéditeur non sollicité ?
4. Le déployeur cible est-il qualifié d'entité **essentielle** ou **importante** — les
   obligations de supervision, donc la gravité de plusieurs écarts, en dépendent ?
5. Un profil de mode opératoire rattaché à un cluster d'IOC constitue-t-il une donnée
   personnelle au sens de l'art. 4(1) lorsque le cluster n'identifie aucune personne
   nommée ? La réponse fait basculer G-36 d'un côté ou de l'autre.
6. Les IOC déjà exportés vers OpenCTI contiennent-ils des données de tiers non
   escrocs, et le nœud OpenCTI redistribue-t-il vers des tiers ?
7. `docker-compose.prod.yml` est-il présenté comme une cible de production supportée,
   ou comme un exemple — ce qui change la gravité de G-07 et G-08 ?
8. Le profil compose `preprod` peut-il être actif en production, ce qui rendrait
   `OpenAIService` (G-06) atteignable hors préproduction ?
9. Existe-t-il une raison technique connue empêchant `EmbeddingService` de passer par
   `LLMClientInterface`, ou est-ce une omission ?
10. Une restauration de sauvegarde a-t-elle déjà été exécutée avec succès, dans
    quelque environnement que ce soit ?
11. L'extension PHP `intl` est-elle garantie présente dans les images livrées — dont
    dépend le fait que A-03 soit un risque théorique ou effectif ?
12. Quelle est la position de l'éditeur sur le fait que la moitié de l'appareil de
    sécurité du produit protège un invariant que l'art. 50(1) interdit dans le
    scénario retenu ?
13. Les permissions `reply:generate` et une éventuelle permission d'envoi séparée
    ont-elles déjà été envisagées, et écartées pour un motif que je n'ai pas trouvé ?
14. Le SBOM CycloneDX produit par Trivy couvre-t-il les dépendances PHP et npm, ou
    seulement les paquets système de l'image ?

---

*Fin de la phase 2 — arrêt protocolaire STOP 2.*
