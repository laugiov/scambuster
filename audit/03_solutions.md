# Phase 3 — Solutions

> **Périmètre.** Les écarts bloquants validés en phase 2, **plus G-30 réintégré**.
> Six dossiers de solution.
>
> **Correction apportée au raisonnement de la phase 2.** G-30 y était écarté au motif
> qu'il dépendait de G-01. Ce motif était trop fort : sur les trois gardes qui
> s'ouvrent, `PaymentInstigationGuard` et le brief du director sont entièrement
> indépendants de la transparence, et `OperationalLeakageDetector` ne l'est que
> partiellement — la fuite d'un nom d'hôte interne reste un problème quelle que soit
> la divulgation. G-30 est donc traité ici.
>
> **Grille de comparaison** appliquée à chaque option : effort · dette introduite ·
> coût d'exploitation pour une équipe de 1 à 3 personnes · nouvelle surface d'attaque
> créée.
>
> **Contrainte de dimensionnement (R5).** Cadence réelle : trafic courriel humain,
> quelques messages par minute au pic ; plafonds configurés à 50 conversations
> actives/jour et 200 appels LLM/heure (`config/packages/rate_limiter.yaml:33-41`).
> Toute proposition est confrontée à cette mesure, et le sur-dimensionnement est
> signalé.
>
> **Zones** — au sens du zonage établi en `01_scope.md` §D.1 :
> **ZE** = zone d'engagement exposée (IMAP, n8n, SMTP) ·
> **ZT** = zone de traitement isolée (backend, PostgreSQL, Redis).

---

## G-01/02 — Transparence du système d'IA

**Exigence.** Règlement (UE) 2024/1689 art. 50(1) : le fournisseur conçoit le système
de façon que la personne physique soit informée qu'elle interagit avec un système
d'IA. Exemption réservée aux systèmes autorisés par la loi à rechercher des
infractions pénales.

### Option 1 — Divulguer dans le courriel sortant

Insérer une mention perceptible dans chaque réponse (première ligne du corps ou bloc
de pied non retirable), et **inverser** les trois mécanismes qui s'y opposent :
retirer les 3 motifs pertinents de `FORBIDDEN_PATTERNS`, réécrire la règle CORE
`BasePromptRules.php:41`, retirer le code `automation_reveal` des codes surveillés de
l'oracle.

| Critère | Évaluation |
|---|---|
| Effort | Faible techniquement — quelques dizaines de lignes, plus une regénération du baseline GUARD |
| Dette introduite | **Maximale sur le plan fonctionnel.** [DÉDUIT] L'escroc se désengage dès le premier message : la valeur produite par le système — 85 créneaux d'enregistrement, `attempts_avg 1.894`, des conversations à 25–50 tours selon le type — repose entièrement sur la crédibilité de l'échange. Raisonnement : `ConversationLifecycleConfig.php:22-55` dimensionne des politiques allant jusqu'à 50 tours et 60 jours ; aucune n'a de sens face à un interlocuteur qui sait |
| Coût d'exploitation 1–3 pers. | Nul en exploitation, mais l'appareil de sécurité anti-divulgation devient sans objet et doit être démantelé proprement plutôt qu'abandonné en place |
| Nouvelle surface d'attaque | Aucune |

### Option 2 — Divulgation hors du corps, dans les en-têtes techniques

Ajouter un en-tête de message signalant la nature artificielle, sans modifier le corps
ni les garde-fous.

| Critère | Évaluation |
|---|---|
| Effort | Très faible — un en-tête ajouté dans `ReplyCompositionService.php:311` |
| Dette introduite | **Conformité douteuse.** [DÉDUIT] Les lignes directrices de la Commission du 20 juillet 2026 exigent une divulgation perceptible dans l'interaction elle-même et écartent explicitement l'enfouissement dans des mentions annexes. Un en-tête que ni un client de messagerie ni un lecteur humain n'affiche relève de la même logique. Raisonnement : le critère retenu est la perceptibilité par la personne, pas la présence technique de l'information |
| Coût d'exploitation | Nul |
| Nouvelle surface d'attaque | Aucune — mais l'en-tête devient un marqueur de détection du honeypot, exploitable par tout adversaire qui lit les sources de message |

### Option 3 — Ne rien construire : restreindre le périmètre du produit et le documenter

Reconnaître que **la fonction d'engagement n'est pas déployable en S2**, et rendre le
produit déployable en S2 **sans elle**.

Le système comporte deux fonctions séparables :

| Fonction | Interagit avec une personne physique ? | Art. 50(1) applicable ? |
|---|---|---|
| Ingestion, extraction d'IOC et de TTP, classification, clustering, profilage, export STIX/MISP/TAXII | **Non** — traitement de courriels reçus | **Non** |
| Génération et envoi de réponses | **Oui** | **Oui** |

La mesure : faire de l'engagement une fonction **désactivée par défaut**, activable
seulement par un déployeur déclarant relever de l'exemption, et le documenter comme
tel dans `SECURITY.md` et `DISCLAIMER.md`.

| Critère | Évaluation |
|---|---|
| Effort | **Faible — le mécanisme existe déjà.** `SCAMBUSTER_KILL_SWITCH` bloque la génération (`ReplyCadenceService.php:55-77` → `ReplyHandler.php:137-139`). Le travail consiste à inverser son défaut, à en faire une décision de configuration explicite plutôt qu'un interrupteur d'urgence, et à étendre son effet à `/send-email`, qu'il ne couvre pas aujourd'hui |
| Dette introduite | Faible. Le produit conserve l'essentiel de sa valeur CTI : les 148 routes, le clustering, les TTP, les exports et le blocage financier restent opérants |
| Coût d'exploitation 1–3 pers. | Nul. Un déployeur S2 exploite une plateforme de honeypot passif ; un déployeur relevant de l'exemption active l'engagement |
| Nouvelle surface d'attaque | Aucune. **Réduction** de surface : sans engagement, `SMTP`, les gardes LLM sortants et la moitié du pipeline ne sont plus exposés |

### Recommandation — Option 3

Trois raisons.

**1. C'est la seule option qui préserve à la fois la conformité et la valeur.**
L'option 1 rend le produit conforme et inopérant ; l'option 2 ne le rend probablement
pas conforme. L'option 3 le rend conforme **en S2** et le laisse pleinement opérant
**en S1**, cadre pour lequel l'exemption existe précisément.

**2. Elle est cohérente avec ce que le projet affirme déjà de lui-même.**
`DISCLAIMER.md:34-37` qualifie l'enveloppe de sécurité de « load-bearing, not
decorative ». Rendre l'engagement conditionnel à une base légale est le prolongement
exact de cette position, pas une concession.

**3. Elle coûte le moins et supprime le plus.** [DÉDUIT] Désactiver l'engagement par
défaut retire de la zone exposée l'ensemble du chemin d'envoi et rend sans objet, pour
un déployeur S2, six des dix menaces du modèle — T1, T3 partiellement, T5, T6, T9 et
une partie de T2. Raisonnement : ces menaces ont toutes pour vecteur la génération ou
l'envoi d'une réponse, ou l'enrichissement déclenché par le pipeline de réponse.

**Ce qui reste à construire, et qui est modeste** : un paramètre d'activation
explicite distinct du kill switch d'urgence ; un refus de démarrage si l'engagement
est activé sans déclaration de base légale ; l'extension du blocage à
`SendEmailController` ; la mise à jour de `SECURITY.md` et `DISCLAIMER.md`.

---

## G-21 — Aucune séparation entre générer et envoyer

**Exigence.** Directive (UE) 2022/2555 art. 20 (approbation et supervision par
l'organe de direction) et art. 21(2)(i) (politiques de contrôle d'accès).

### Option 1 — Permission d'envoi distincte

Créer une permission `reply:send` distincte de `reply:generate` ; l'exiger sur
`SendEmailController` et `MarkReplySentController` ; ne pas l'accorder au principal
n8n.

| Critère | Évaluation |
|---|---|
| Effort | **Très faible.** Un cas ajouté à `src/Domain/User/Permission.php:19-40` (14 → 15), deux attributs `#[IsGranted]` modifiés, un jeu de fixtures. `PermissionVoter` fonctionne déjà par permission |
| Dette introduite | Nulle — c'est l'usage prévu du modèle de permissions existant |
| Coût d'exploitation 1–3 pers. | **Nul en régime nominal.** L'envoi reste automatisé ; c'est le porteur de la permission qui change, pas le débit |
| Nouvelle surface d'attaque | Aucune. Réduction : un n8n compromis ne peut plus envoyer |

### Option 2 — File d'approbation analyste avant envoi

Chaque brouillon attend un verdict humain, sur le modèle de la file de revue des IOC
financiers.

| Critère | Évaluation |
|---|---|
| Effort | Moyen — le patron existe (`SubmitIocFeedbackController`, `IocFeedbackService`, écran de revue), à transposer aux réponses |
| Dette introduite | **Élevée.** Rend l'exploitation dépendante d'une présence humaine continue |
| Coût d'exploitation 1–3 pers. | **Prohibitif.** [DÉDUIT] À 50 conversations actives par jour et une cadence minimale de 6 h entre réponses, la file produit un flux d'approbations réparti sur toute la journée que 1 à 3 personnes ne peuvent pas tenir sans devenir le facteur limitant. Raisonnement : `rate_limiter.yaml:38-41` et `ReplyCadenceService.php:27` |
| Nouvelle surface d'attaque | Faible |

### Option 3 — Ne rien construire : documenter et faire acter le risque

Documenter que la génération et l'envoi partagent une permission, et fournir un
modèle d'acceptation de risque signé par l'organe de direction.

| Critère | Évaluation |
|---|---|
| Effort | Minimal |
| Dette introduite | **Reporte l'écart sur chaque déployeur**, indéfiniment |
| Coût d'exploitation | Nul |
| Nouvelle surface d'attaque | Aucune |

### Recommandation — Option 1

Elle satisfait l'exigence de séparation des rôles pour un coût quasi nul, sans
toucher au débit. [DÉDUIT] L'option 2 confond deux besoins distincts : la séparation
des privilèges — ce qu'exige l'art. 21(2)(i) — et la validation humaine de chaque
acte, qu'aucune source citée n'impose en S2. Raisonnement : l'art. 20 exige que
l'organe de direction approuve et supervise **les mesures**, pas chaque message.

L'option 1 se combine naturellement à la recommandation G-01 : lorsque l'engagement
est désactivé, la permission `reply:send` n'est accordée à personne.

---

## G-03/04/05 — Souveraineté de l'inférence

**Exigence.** Règlement (UE) 2016/679 art. 32(1) et 28(1) ; Directive (UE) 2022/2555
art. 21(2)(d).

### Option 1 — Bascule complète vers une inférence locale

Refactoriser les 7 sites qui codent un modèle en dur, doter `EmbeddingService` d'une
abstraction de fournisseur, retirer la seconde interface `LLMServiceInterface`, et
faire de `LLM_PROVIDER=ollama` une bascule réellement totale.

| Critère | Évaluation |
|---|---|
| Effort | **Moyen et borné.** 7 sites (`ReplyValidator.php:103`, `OperationalLeakageDetector.php:28`, `PaymentInstigationGuard.php:50`, `ConversationAnalyzer.php:27`, `ConversationHistoryService.php:229`, `ConversationQualityAuditor.php:77`, `EmbeddingService.php:18`) + une interface d'embeddings + le retrait d'un alias DI |
| Dette introduite | **Une régression de qualité à mesurer** — traitée ci-dessous. Le port `LLMClientInterface` et l'adaptateur `OllamaClient` existent déjà |
| Coût d'exploitation 1–3 pers. | **Modéré.** Un serveur d'inférence à administrer. [DÉDUIT] À quelques messages par minute au pic, une seule instance sur un GPU d'entrée de gamme suffit ; il n'y a **aucun besoin** de service de mise à l'échelle, de file d'inférence ni de grappe. Raisonnement : 200 appels LLM/heure au plafond configuré, soit ~3/min, contre un débit de plusieurs requêtes par seconde pour une instance unique |
| Nouvelle surface d'attaque | **Faible et interne.** Un service d'inférence dans la ZT. En contrepartie, suppression de trois destinations Internet depuis la ZT |

### Option 2 — Passerelle d'inférence à point de sortie unique

Conserver le fournisseur externe, mais imposer que **tout** appel transite par un
composant interne unique, seul autorisé à sortir, avec liste blanche et journalisation.

| Critère | Évaluation |
|---|---|
| Effort | Moyen — un composant à écrire, plus le même refactor des 7 sites (sans quoi la passerelle est contournée) |
| Dette introduite | Un composant maison de plus à maintenir |
| Coût d'exploitation | Faible |
| Nouvelle surface d'attaque | **Réelle** : la passerelle voit tout le trafic en clair et détient la clé d'API |
| Limite de fond | [DÉDUIT] **Elle rend le flux démontrable mais ne l'arrête pas.** Le contenu de tiers sort toujours. L'écart G-03 subsiste ; seul G-04 est traité |

### Option 3 — Ne rien construire : contractualiser et documenter

Fournir un accord de sous-traitance type, une clause de non-entraînement, et une
matrice exhaustive des flux sortants avec leurs déclencheurs.

| Critère | Évaluation |
|---|---|
| Effort | Faible — la matrice des flux existe déjà : `00_inventory.md` §2 la dresse (17 sorties backend, 11 nœuds n8n) |
| Dette introduite | Reporte la charge sur chaque déployeur |
| Coût d'exploitation | Nul |
| Nouvelle surface d'attaque | Aucune |
| Limite de fond | Ne traite ni G-04 ni G-05 : même contractualisé, le déployeur ne peut pas **démontrer** qu'aucune donnée ne sort, puisque les 7 sites en dur restent |

### Recommandation — Option 1

L'option 3 laisse l'entité incapable de démontrer sa maîtrise, ce qui est précisément
l'exigence de l'art. 32(1)(d). L'option 2 exige le même refactor que l'option 1 tout
en laissant le contenu sortir : elle coûte autant et livre moins. L'option 1 est la
seule qui referme les trois écarts.

**Précision de dimensionnement (R5).** Le refactor des 7 sites est requis **dans les
trois options** — il conditionne toute affirmation de maîtrise du flux. C'est le
travail structurant ; le choix du fournisseur vient après.

### Protocole de mesure de la régression de qualité

Exigé explicitement par la commande. Le point remarquable est que **le harnais de
mesure existe déjà** et n'a pas à être construit.

**Corpus disponible** [VÉRIFIÉ] : 99 fixtures `.eml` — 65 dans
`tests/Smoke/ReplyObjectiveFixtures/`, 34 dans `tests/Smoke/CialdiniMirrorFixtures/`
(couvrant EN/FR/DE/ES) ; baseline gelé `tests/Smoke/guard-baseline.json` avec
`recording_slots 85`, `out_texts_scored 85`, `errors 0`, empreinte d'oracle
`374f95367add`, somme de contrôle `.sha256` associée.

**Instruments existants** : `scambuster:smoke:reply-objective` →
`CanaryAggregate` → `scambuster:guard:check` avec comparaison au baseline et
tolérance de 0,05 ; `app:eval:ioc-extraction-metrics` (précision/rappel/F1 sur jeu
annoté) ; `app:evaluate:reply-quality` ; `app:eval:run-judge`.

**Protocole en cinq étapes.**

| Étape | Action | Sortie attendue |
|---|---|---|
| 1 | Regeler un baseline de référence sur `gpt-4o-mini` avec le corpus courant, en vérifiant que l'empreinte d'oracle est inchangée | Baseline de contrôle |
| 2 | **Figer le modèle juge.** Exécuter la campagne candidate avec le modèle local **en génération seulement**, `ReplyValidator` et `OperationalLeakageDetector` restant sur le modèle de référence | Isole la régression du générateur |
| 3 | Exécuter `scambuster:smoke:reply-objective` puis `guard:check` sur le candidat | Deltas par code de violation + `approved_rate`, `fallback_rate`, `attempts_avg` |
| 4 | Exécuter `app:eval:ioc-extraction-metrics` sur le même corpus, avant et après | Précision/rappel/F1 d'extraction |
| 5 | Rejouer les étapes 2 à 4 avec le modèle local **également en juge** | Mesure l'effet cumulé générateur + juges |

**Pourquoi l'étape 2 est le point critique.** [DÉDUIT] Basculer `LLM_PROVIDER` change
le modèle du générateur **et** celui des deux juges LLM simultanément, puisque tous
héritent du même fournisseur. Une campagne naïve mesurerait donc une composée de deux
régressions et pourrait produire un résultat trompeusement bon : un juge affaibli
approuve davantage, ce qui fait *monter* `approved_rate` alors même que la qualité
baisse. Raisonnement : `approved_rate` est calculé à partir des décisions du
validateur (`CanaryAggregate.php:29-83`), lui-même un appel LLM
(`ReplyValidator.php:109`).

**Régression attendue, et indicateurs qui la révéleront.**

| Indicateur | Valeur de référence | Sens attendu | Pourquoi |
|---|---|---|---|
| `attempts_avg` | **1,894** | **Hausse** | Un modèle plus faible franchit moins souvent PolicyGuard et le seuil `iocScore` du premier coup ; plafond à 3 tentatives |
| `fallback_rate` | **0,0** | **Hausse** | Conséquence directe de l'épuisement des 3 tentatives |
| `language_mismatch` | **0,0353** | **Hausse marquée** | Point le plus exposé : le corpus est multilingue et les modèles locaux quantifiés perdent d'abord en tenue de langue |
| `word_band` | 0,0 | Hausse | Respect moins fiable des bandes 12–150 mots de `PolicyGuardConfig` |
| `payment_token` | **0,294** | Instable | Rappel : code **informationnel**, non bloquant — il ne fera pas échouer la porte quel que soit le résultat |
| Rappel d'extraction d'IOC | à mesurer à l'étape 4 | **Baisse** | Tâche d'extraction structurée en JSON, sensible à la taille du modèle |

**Critère d'acceptation proposé.** Le `fallback_rate` étant bilatéral dans le
comparateur avec une tolérance de 0,05 (`CanaryBaselineComparator.php:107-109`), et
les codes de violation à baseline nul étant signalés dès toute valeur non nulle, le
seuil de décision est déjà défini par l'outil. **Il n'y a pas de nouveau critère à
inventer** : la bascule est acceptable si `guard:check` sort en zéro contre le
baseline de référence, avec l'empreinte d'oracle inchangée.

**Signalement de sur-dimensionnement.** [DÉDUIT] Aucune infrastructure de service de
modèle — grappe, équilibrage, quantification dynamique, mise en cache de préfixes —
n'est justifiée à 3 appels/minute. Le seul choix dimensionnant est celui de la taille
du modèle, et il se tranche par la mesure ci-dessus, pas par l'architecture.

---

## G-07/08 — Zonage et filtrage de sortie

**Exigence.** Directive (UE) 2022/2555 art. 21(2)(a).

### Option 1 — Deux réseaux Docker et une matrice de flux explicite

Séparer `net-engagement` (n8n, transports IMAP/SMTP) et `net-traitement`
(backend, PostgreSQL, Redis, inférence locale) ; marquer `net-traitement` en
`internal: true` ; n'exposer du backend vers la ZE que les routes nécessaires à n8n.

| Critère | Évaluation |
|---|---|
| Effort | **Faible.** Modification de `docker-compose.prod.yml` ; aucun code applicatif |
| Dette introduite | Nulle — c'est le geste standard |
| Coût d'exploitation 1–3 pers. | Faible. Une matrice de flux à tenir à jour |
| Nouvelle surface d'attaque | Aucune. **Réduction majeure** : un n8n compromis perd l'accès direct à PostgreSQL et Redis (menace T4) |
| Limite | `internal: true` sur la ZT est compatible avec la recommandation G-03 (inférence locale) mais **incompatible avec le maintien d'un fournisseur externe** — les deux dossiers doivent être tranchés ensemble |

### Option 2 — Segmentation par hôtes séparés et proxy sortant filtrant

ZE et ZT sur deux machines distinctes, avec un proxy explicite à liste blanche.

| Critère | Évaluation |
|---|---|
| Effort | Élevé |
| Dette introduite | Deux hôtes à administrer, un proxy à maintenir |
| Coût d'exploitation 1–3 pers. | **Élevé, et sur-dimensionné.** [DÉDUIT] À quelques messages par minute, deux hôtes doublent la charge d'administration sans bénéfice proportionné pour une équipe de cette taille. Raisonnement : le gain de sécurité par rapport à l'option 1 est marginal — deux réseaux Docker distincts couvrent déjà le pivot latéral, qui est la menace identifiée |
| Nouvelle surface d'attaque | Le proxy lui-même |

### Option 3 — Ne rien construire : livrer la matrice de flux et laisser segmenter

Documenter les 17 sorties backend et 11 nœuds n8n avec leurs déclencheurs, et fournir
un exemple de segmentation sans l'imposer.

| Critère | Évaluation |
|---|---|
| Effort | Très faible — la matrice existe (`00_inventory.md` §2) |
| Dette introduite | Chaque déployeur refait le travail |
| Coût d'exploitation | Nul |
| Nouvelle surface d'attaque | Aucune |
| Limite | `docker-compose.prod.yml` restant présenté comme cible de production, un déployeur le prendra tel quel |

### Recommandation — Option 1, complétée par le livrable de l'option 3

L'option 1 traite la menace principale — le pivot depuis la ZE vers le magasin de
données — pour un coût de quelques lignes de configuration. L'option 2 est
sur-dimensionnée à cette cadence. Le livrable documentaire de l'option 3 reste
nécessaire : sans matrice de flux publiée, le déployeur ne peut pas construire sa
propre liste blanche de sortie, quelle que soit la segmentation livrée.

---

## G-24/25 — Version identifiable et SBOM distribué

**Exigence.** Règlement (UE) 2024/2847, signalement par produit et par version,
premier jalon au **11 septembre 2026** ; Directive (UE) 2022/2555 art. 21(2)(d).

### Option 1 — Publication versionnée avec SBOM attaché

Poser des tags SemVer, ajouter un workflow de publication qui attache le SBOM
CycloneDX déjà produit, et remplacer « main | Yes » de `SECURITY.md:5-7` par une
politique de versions supportées.

| Critère | Évaluation |
|---|---|
| Effort | **Faible.** Le SBOM est déjà généré (`ci.yml:332-337`) ; il n'est pas distribué. Il s'agit de l'attacher à une publication, pas de le produire |
| Dette introduite | Une discipline de publication à tenir. `CHANGELOG.md` est déjà au format Keep a Changelog |
| Coût d'exploitation 1–3 pers. | **Faible et récurrent.** C'est le seul poste qui ajoute une charge permanente, de l'ordre de quelques minutes par publication |
| Nouvelle surface d'attaque | Aucune |

### Option 2 — Chaîne d'approvisionnement complète

Ajouter la signature d'artefacts, l'attestation de provenance, les constructions
reproductibles et l'épinglage par empreinte.

| Critère | Évaluation |
|---|---|
| Effort | Élevé |
| Dette introduite | Élevée — clés de signature à gérer et à faire tourner |
| Coût d'exploitation 1–3 pers. | **Sur-dimensionné.** [DÉDUIT] Aucune source citée n'exige la signature d'artefacts ni l'attestation de provenance pour ce scénario ; l'art. 21(2)(d) demande de tenir compte des risques de la chaîne, ce que la publication versionnée avec SBOM satisfait. Raisonnement : l'exigence porte sur la connaissance et la maîtrise des composants, pas sur leur attestation cryptographique |
| Nouvelle surface d'attaque | Gestion de clés |

### Option 3 — Ne rien construire : documenter le commit déployé

Indiquer au déployeur de consigner l'empreinte de commit qu'il exécute.

| Critère | Évaluation |
|---|---|
| Effort | Nul |
| Dette introduite | L'éditeur reste incapable de désigner une version affectée par une vulnérabilité |
| Coût d'exploitation | Nul |
| Nouvelle surface d'attaque | Aucune |
| Limite | Ne satisfait pas l'obligation de signalement du CRA, qui s'exprime par produit et par version |

### Recommandation — Option 1

Coût le plus faible du dossier, effet de levier le plus élevé : sans version, aucune
des cinq autres recommandations n'est livrable de façon identifiable. L'option 2 est
écartée comme sur-dimensionnée au regard des sources citées et de la taille de
l'équipe. L'épinglage des images par empreinte (G-26), qui en fait partie, reste un
arbitrage ouvert que je place en avis plutôt qu'en recommandation : il fige aussi les
correctifs de l'image de base, et les Dockerfiles exécutent déjà `apt-get upgrade`
à la construction.

---

## G-30 — Échecs ouverts corrélés sur une cause unique

**Exigence.** Directive (UE) 2022/2555 art. 21(2)(c).

**Rappel du constat.** Trois contrôles s'ouvrent sur l'indisponibilité du fournisseur
d'inférence : `OperationalLeakageDetector.php:59-89` (retourne « pas de fuite »),
`PaymentInstigationGuard.php:162-179` (approuve hors des 12 jetons de repli),
`ReplyHandler.php:104-110` (le brief du director retourne `null`). Le repli
déterministe résiduel se limite à 12 motifs de vocabulaire de paiement.

### Option 1 — Coupe-circuit sur la santé du fournisseur

Instrumenter la disponibilité de l'inférence et, en cas d'indisponibilité,
**suspendre la génération** au lieu de générer avec des gardes dégradées.

| Critère | Évaluation |
|---|---|
| Effort | **Faible.** Le mécanisme d'arrêt existe : couche cache du kill switch (`ReplyCadenceService.php:30`, clé `llm.killswitch.active`). Il s'agit de le piloter depuis un compteur d'échecs fournisseur plutôt que depuis la seule bascule d'administration |
| Dette introduite | Faible. Un état de plus à superviser — déjà exposé par la jauge `scambuster_kill_switch` (`MetricsController.php:97-100`) |
| Coût d'exploitation 1–3 pers. | Faible. Une alerte Prometheus existe déjà pour le kill switch (`ScamBusterKillSwitchActive`) |
| Nouvelle surface d'attaque | **Une, à traiter** : un adversaire capable de provoquer des échecs d'inférence — saturation du budget, charges utiles pathologiques — obtient un déni de service sur la génération. [DÉDUIT] Conséquence acceptable : l'arrêt de la génération est le mode dégradé sûr, l'inverse du risque actuel. Raisonnement : ne pas répondre n'expose personne ; répondre avec trois gardes ouvertes expose l'entité |

### Option 2 — Élargir le repli déterministe

Étendre les 12 motifs de `PAYMENT_INFRA_TOKEN_PATTERNS` et ajouter un repli
déterministe à `OperationalLeakageDetector`.

| Critère | Évaluation |
|---|---|
| Effort | Moyen, et **sans fin**. L'oracle GUARD porte déjà 16 motifs contre 12 dans le garde, et son propre docblock reconnaît que « Residual free-paraphrase … is an inherent limit » (`SafetyInvariantOracle.php:84-85`) |
| Dette introduite | **Élevée.** Chaque motif ajouté doit être répliqué dans l'oracle sous peine de faire échouer les tests de dérive (`SafetyInvariantOracleTest.php:219-246`) |
| Coût d'exploitation | Faible |
| Nouvelle surface d'attaque | Aucune |
| Limite de fond | Traite le symptôme. La classe de contenu visée par le détecteur de fuite est précisément la paraphrase, que par construction aucune liste de motifs ne couvre |

### Option 3 — Ne rien construire : documenter le mode dégradé

Décrire explicitement dans la documentation d'exploitation que l'indisponibilité de
l'inférence dégrade trois contrôles, et laisser le déployeur décider de couper.

| Critère | Évaluation |
|---|---|
| Effort | Minimal |
| Dette introduite | L'écart demeure, et il n'est même pas observable : rien ne signale à l'exploitant que les gardes sont ouvertes |
| Coût d'exploitation | Nul |
| Nouvelle surface d'attaque | Aucune |

### Recommandation — Option 1

Elle réutilise un mécanisme existant, transforme une dégradation silencieuse en état
observable et alerté, et retient le seul mode dégradé sûr pour un système dont la
fonction est d'écrire à des tiers. L'option 2 poursuit une complétude que le code
lui-même déclare inatteignable. L'option 3 laisse une dégradation invisible, ce qui
est le point précis que l'art. 21(2)(c) vise.

**Interaction avec la recommandation G-03.** [DÉDUIT] Une inférence locale déplace le
mode de défaillance sans le supprimer : la panne devient interne et mieux maîtrisée,
mais reste une cause unique pour les trois gardes. Le coupe-circuit reste donc
nécessaire après la bascule. Raisonnement : les trois `catch (\Throwable)` sont
indifférents à l'identité du fournisseur.

---

## Synthèse des recommandations

| Écart | Recommandation | Effort | Charge d'exploitation ajoutée | Zone concernée |
|---|---|---|---|---|
| **G-01/02** | Option 3 — engagement désactivé par défaut, activable sur déclaration de base légale | Faible | Nulle | ZE — retire le chemin d'envoi |
| **G-21** | Option 1 — permission `reply:send` distincte | Très faible | Nulle | ZT |
| **G-03/04/05** | Option 1 — bascule complète vers l'inférence locale, après mesure | Moyen | Modérée — un service à administrer | ZT |
| **G-07/08** | Option 1 + livrable documentaire de l'option 3 | Faible | Faible | Frontière ZE/ZT |
| **G-24/25** | Option 1 — publication versionnée avec SBOM attaché | Faible | **Faible et récurrente** | Hors zone — chaîne de production |
| **G-30** | Option 1 — coupe-circuit sur la santé du fournisseur | Faible | Faible | ZT |

**Deux observations sur l'ensemble.**

**Le coût total est modeste, et concentré sur un seul poste.** Cinq des six
recommandations sont d'effort faible à très faible et réutilisent des mécanismes déjà
présents — kill switch, modèle de permissions, harnais GUARD, générateur de SBOM.
Seule la souveraineté de l'inférence représente un travail de conception réel, et son
poste dimensionnant est la mesure de régression, non l'infrastructure.

**Aucune technologie nouvelle n'est introduite.** [DÉDUIT] Les six dossiers se
résolvent avec le port `LLMClientInterface` existant, l'adaptateur `OllamaClient`
existant, les réseaux Docker, le modèle de permissions Symfony et le SBOM déjà
généré. Conformément à R5, aucun besoin mesuré ne justifie une file de messages, un
maillage de services, une infrastructure de signature ni un service d'inférence
distribué — la cadence de quelques messages par minute les exclut tous.

---

## Ce que je n'ai pas pu vérifier — phase 3

1. Désactiver l'engagement suffit-il à écarter l'art. 50(1), ou l'ingestion d'un
   courriel et la production d'un accusé de réception automatique constitueraient-ils
   déjà une « interaction directe » ?
2. Un déployeur relevant de l'exemption doit-il en apporter la preuve à l'éditeur, et
   sous quelle forme, pour que l'activation de l'engagement soit défendable côté
   fournisseur ?
3. Quel matériel d'inférence le déployeur cible est-il prêt à provisionner — ce qui
   borne la taille de modèle et donc l'ampleur de la régression mesurée ?
4. Le corpus de 99 fixtures est-il représentatif du trafic réel en distribution de
   langues et de types d'arnaque, ou sur-représente-t-il certains cas ?
5. Le jeu annoté de référence utilisé par `app:eval:ioc-extraction-metrics` est-il
   présent dans le dépôt, et quelle est sa taille ?
6. `OllamaClient` a-t-il déjà été exercé contre un serveur réel, ou seulement en test
   unitaire — l'adaptateur est-il éprouvé ?
7. Le format de réponse JSON structuré exigé par plusieurs appels
   (`response_format: json_object`) est-il supporté par l'API Ollama de la même
   manière, ou faut-il prévoir une couche de tolérance ?
8. Combien de temps prend une campagne complète de 85 créneaux avec un modèle local,
   sachant qu'elle prend ~35 min avec `gpt-4o-mini` ?
9. Existe-t-il une contrainte opérationnelle empêchant `net-traitement` d'être
   `internal: true` — un besoin de sortie que je n'aurais pas recensé ?
10. La suppression de `LLMServiceInterface` casse-t-elle le générateur de
    préproduction, et ce générateur est-il encore utilisé ?
11. Le SBOM produit par Trivy couvre-t-il les dépendances PHP et npm, ou seulement
    les paquets système — ce qui déterminerait si l'option G-24/25 est suffisante ?
12. Une politique de versions supportées à une seule branche est-elle acceptable au
    regard du CRA, ou faut-il maintenir une branche de correctifs ?
13. Le coupe-circuit doit-il suspendre aussi l'extraction d'IOC et la classification,
    qui appellent également le LLM, ou seulement la génération de réponses ?
14. Quel seuil d'échecs consécutifs déclenche le coupe-circuit sans le rendre
    hypersensible aux erreurs transitoires du fournisseur ?

---

*Fin de la phase 3.*
