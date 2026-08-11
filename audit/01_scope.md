# Phase 1 — Cadre cible et menaces

> Statuts : `[VÉRIFIÉ]` lu dans un fichier (chemin + ligne). `[DÉDUIT]` inférence,
> raisonnement explicité. `[INCONNU]` non déterminable. `[NON SOURCÉ]` exigence
> normative dont la source n'a pas été vérifiée (R4).
>
> **Note de méthode sur R4.** La partie A ci-dessous compare des *cadres de
> déploiement*. Elle nomme des régimes juridiques pour situer qui autorise et qui
> répond, mais **n'en tire aucune conclusion normative** : chaque référence porte
> la marque `[À SOURCER — 1B]`. La vérification texte par texte, avec article et
> version, est l'objet de la partie B, exécutée après validation du scénario
> pilote.

---

## A. Trois scénarios de déploiement

### Rappels factuels de la phase 0 qui contraignent les trois scénarios

Ces faits ne sont pas rediscutés ; ils sont repris de `audit/00_inventory.md`.

| Fait | Référence phase 0 |
|---|---|
| Le système répond par courriel à des adversaires, sous **identité fictive d'opérateur** | §1.10, `ReplyHandler.php:126` → `SendEmailController.php:47` ; personas `PersonaFixtures` (27) |
| L'engagement est **entrant seulement** : la réponse suppose un message reçu | §4.5 D50 « Verrou A » (`ReplyHandler.php:168-186`), `DISCLAIMER.md:12` |
| L'inférence par défaut sort vers **`https://api.openai.com`** | §2.1 E1, `.env.dist:307` ; 7 sites d'appel codent un modèle OpenAI **en dur** (§3.2) |
| Les embeddings sortent vers OpenAI, **endpoint en dur, sans abstraction** | §2.1 E4, `EmbeddingService.php:20,68` |
| Les **valeurs d'IOC fournies par l'adversaire** sont soumises à urlscan.io et VirusTotal | §2.3 E23–E25, `WF-EXTRACT-AND-ENRICH-IOC.json:114` |
| **Aucune liste blanche de sortie, aucun proxy sortant, un seul réseau Docker** | §2.6, `framework.yaml:17-18`, `docker-compose.yml:261-262` |
| Le corps brut RFC822 des courriels de tiers est **conservé intégralement** | §7.2, `Message.php:23-24`, `IngestHandler.php:180` |
| Des **profils psychologiques de tiers** générés par LLM sont persistés, **sans purge** | §7.2, §7.7, `Version2026070600000000.php:29-36` |
| La rétention 6/12 mois **n'est pas planifiée** ; la purge automatique ne supprime rien physiquement | §7.6, `scheduler.sh:16-24` contre `PurgeService.php:25,55` |
| **Aucune anonymisation n'existe** dans le chemin de purge | §7.7 |
| Le journal d'audit est **chaîné par HMAC mais non immuable** : le `REVOKE` n'est ni appliqué ni documenté | §8.9, `Version2026041200100000.php:20-24`, `rg REVOKE docs/` → ∅ |
| La clé HMAC d'audit est une **variable d'environnement en clair** | §6.1, `AuditHmacChainer.php:19-20` |
| **12 contrôleurs sur 145 émettent un audit** ; 4 des 6 surfaces d'export n'en émettent aucun | §8.7 |
| **Blocage d'export des IOC financiers** avec libération par verdict d'analyste — contrôle réel et fonctionnel | §4.8 D66–D73 |
| **Aucun tag git, aucun processus de publication, historique public écrasé** (10 commits, 6 jours) | §0, §9.11 |
| **Un seul mainteneur** ; licence MIT sans garantie | §0, `LICENSE`, `GOVERNANCE.md:5` |
| Aucun SBOM publié hors artefact CI à 30 jours ; **aucune image épinglée par empreinte** | §9.7, §9.8, §10.3 |
| Cadence réelle : trafic courriel humain — les limiteurs plafonnent à **50 conversations/jour, 200 appels LLM/heure** | §4.5 D44 |

---

### S1 — Unité d'investigation *law enforcement*, auto-hébergée

**Description.** Un service d'enquête (police judiciaire, gendarmerie, service à
compétence nationale) déploie ScamBuster sur son infrastructure pour engager des
escrocs qui sollicitent des boîtes-appâts, en extraire des IOC et alimenter des
procédures.

| Axe | Analyse |
|---|---|
| **Qui autorise l'engagement** | Une autorité judiciaire, pas l'exploitant technique. L'échange sous identité d'emprunt avec une personne soupçonnée relève en France du régime de l'**enquête sous pseudonyme** [À SOURCER — 1B], qui réserve l'acte à des agents individuellement **habilités et affectés**, sous autorisation et contrôle du parquet ou du juge. [DÉDUIT] Le point dur n'est pas technique : le code exécute l'acte d'enquête **sans agent dans la boucle**. Raisonnement : le pipeline n8n `WF-INTAKE → WF-REPLY-GENERATE → WF-REPLY-SEND` enchaîne réception, génération et envoi sans point d'arrêt humain (§1.10), et `/send-email` n'est gardé que par `reply:generate` — la même permission que la génération (§12 Q8, `SendEmailController.php:48`). L'habilitation individuelle n'a aucune contrepartie dans le modèle de permissions (14 permissions, `Permission.php:19-40`), qui ne distingue ni l'agent habilité ni l'autorisation dossier par dossier |
| **Qui porte la responsabilité juridique** | L'État, via le service et son chef de service ; l'agent pour l'acte d'enquête. L'éditeur est hors de la chaîne : `LICENSE` (MIT) et `DISCLAIMER.md:43-45` excluent toute garantie |
| **Quelle autorité homologue** | Homologation de sécurité interne au ministère, prononcée par l'autorité qualifiée du système ; instruction et référentiels d'État [À SOURCER — 1B]. Si le système est rattaché à un périmètre d'importance vitale, l'autorité nationale de sécurité des systèmes d'information intervient [À SOURCER — 1B] |
| **Livrable attendu de l'éditeur** | Dossier d'homologation complet : analyse de risque formelle, description exhaustive des flux et des zones, **preuve du caractère déterministe des contrôles opposables**, chaîne de preuve des artefacts, SBOM, engagement de correction des vulnérabilités, versions supportées. [VÉRIFIÉ] Aucun de ces livrables n'existe : pas de tag ni de version (§0), pas de SBOM publié (§9.8), pas de politique de versions supportées au-delà de `SECURITY.md:5-7` (« main \| Yes ») |
| **Point de rupture propre à S1** | La **valeur probante**. Le journal d'audit est chaîné par HMAC mais reste physiquement modifiable : le `REVOKE UPDATE/DELETE` est explicitement renvoyé à une étape d'exploitation (`Version2026041200100000.php:20-24`) qui **n'est documentée nulle part** (§8.9, DOC-23), et la clé HMAC est une variable d'environnement lisible par le même processus qui écrit les lignes (§6.1). [DÉDUIT] Un opérateur disposant du conteneur peut réécrire une ligne **et** recalculer la chaîne : la propriété obtenue est une détection d'altération accidentelle, pas une preuve opposable contre l'exploitant lui-même. Raisonnement : `AuditHmacChainer.php:19-20` lit `AUDIT_HMAC_KEY` dans l'environnement du processus applicatif, et `VerifyAuditChainCommand` recalcule avec cette même clé |

---

### S2 — Entité régulée NIS2, entité essentielle, auto-hébergée

**Description.** Une entité soumise à NIS2 en tant qu'entité essentielle (opérateur
d'énergie, de transport, de santé, banque, fournisseur d'infrastructure numérique)
déploie ScamBuster dans son propre SOC/CERT, sur ses propres boîtes-appâts, pour
produire du renseignement sur les campagnes qui la visent et alimenter son SIEM et
ses partages sectoriels.

| Axe | Analyse |
|---|---|
| **Qui autorise l'engagement** | L'entité elle-même. NIS2 place la responsabilité de l'approbation et du suivi des mesures de gestion des risques sur l'**organe de direction** [À SOURCER — 1B]. L'engagement relève de la défense du système d'information de l'entité, pas d'un acte d'enquête pénale : aucune habilitation judiciaire n'est requise. [DÉDUIT] C'est la différence structurante avec S1 — l'autorisation est un acte de gouvernance interne, que le système peut matérialiser (verdict d'analyste, journal, kill switch), et non une habilitation nominative que le code ne modélise pas |
| **Qui porte la responsabilité juridique** | L'entité, seule et sans ambiguïté, sur deux fondements cumulés : responsable de traitement au sens du RGPD pour les données personnelles de tiers ingérées [À SOURCER — 1B], et entité essentielle au titre de NIS2 pour la sécurité du système lui-même. `DISCLAIMER.md:13` place explicitement cette qualité sur l'exploitant : « You are the data controller » |
| **Quelle autorité homologue** | **Aucune homologation externe préalable.** Le régime est celui de la supervision : mesures de gestion des risques sous la responsabilité de l'entité, contrôle *a posteriori* par l'autorité nationale compétente [À SOURCER — 1B]. Ce qui est attendu est une **acceptation de risque formalisée en interne**, opposable en cas de contrôle, et non un agrément préalable |
| **Livrable attendu de l'éditeur** | Un produit documenté et vérifiable, pas une garantie : SBOM par version, politique de versions supportées et de correction des vulnérabilités, description exacte et à jour des contrôles (ce que chacun couvre **et ne couvre pas**), capacité à fonctionner sans dépendance à une API d'inférence externe, et éléments de preuve exploitables par le SIEM de l'entité. [DÉDUIT] Ce livrable est **de même nature** que ce que le projet produit déjà — il en manque la discipline de publication, pas la substance. Raisonnement : les contrôles existent et sont testés (83 contrôles déterministes recensés au §4, 572 tests backend au §9.1, porte GUARD au §5.1) ; ce qui manque est un tag, un SBOM attaché à ce tag, et une documentation qui ne contredise pas le code sur 24 points (§11) |
| **Point de rupture propre à S2** | La **souveraineté de l'inférence** et le **zonage**. Le contenu intégral de courriels de tiers part vers un fournisseur d'inférence hors zone de l'entité (§2.1 E1, E4) sans qu'aucune liste blanche de sortie n'existe (§2.6) ; sept sites d'appel codent un modèle OpenAI en dur (§3.2) ; les embeddings n'ont **aucune abstraction de fournisseur** (`EmbeddingService.php:20`). [DÉDUIT] Une entité essentielle peut accepter ce transfert par contrat, mais elle ne peut pas *démontrer* qu'elle le maîtrise : rien dans le code ne permet de garantir qu'aucun contenu ne sort, puisque `LLM_PROVIDER=ollama` ne déroute ni les embeddings ni les enrichissements urlscan/VirusTotal du workflow n8n |

---

### S3 — Nœud d'un observatoire fédéré opéré par des tiers

**Description.** Plusieurs organisations exploitent chacune un nœud ScamBuster et
mettent en commun leurs IOC, clusters et TTP via TAXII/STIX dans un observatoire
partagé.

| Axe | Analyse |
|---|---|
| **Qui autorise l'engagement** | Chaque opérateur de nœud pour son propre nœud, dans le cadre d'une charte de fédération. [DÉDUIT] Aucune autorité ne peut autoriser l'engagement pour l'ensemble : la fédération partage des **sorties**, pas des actes |
| **Qui porte la responsabilité juridique** | **Ambiguë, et c'est le problème central.** Chaque nœud est responsable de traitement pour ce qu'il ingère, mais la mise en commun d'IOC contenant des données personnelles de tiers (adresses, IBAN, numéros de téléphone — §7.2, table `indicator`) crée une question de responsabilité conjointe entre nœuds [À SOURCER — 1B]. [DÉDUIT] Le code n'offre aucune prise sur ce point : la table `indicator` n'a **aucune purge** (§7.7), et un IOC exporté vers la fédération devient irrécupérable — il n'existe ni identifiant de nœud d'origine, ni mécanisme de rétractation, ni signature de flux |
| **Quelle autorité homologue** | Aucune. Il faudrait construire un cadre de confiance : certification des nœuds, identité de nœud, signature et provenance des flux, procédure d'exclusion |
| **Livrable attendu de l'éditeur** | Tout ce que demande S2, **plus** : identité et attestation de nœud, provenance signée des IOC, constructions reproductibles, multi-tenance, et une gouvernance qui survive à une personne. [VÉRIFIÉ] Le projet est à un mainteneur (`git shortlog -sne` : 2 identités, une personne), sans tag ni processus de publication (§9.11), et le seul artefact multi-tenant du code a été **retiré** (`Version2026041112000000.php:35` — `ALTER TABLE app_users DROP COLUMN tenant_id`) |
| **Point de rupture propre à S3** | L'**empoisonnement du flux** et l'**intégrité de bout en bout**. Les IOC sont enrichis par soumission de la valeur fournie par l'adversaire à urlscan.io et VirusTotal (§2.3 E23–E25) : un adversaire qui a compris l'automatisation choisit ses IOC pour piloter l'enrichissement, donc le clustering, donc ce que reçoit toute la fédération. [VÉRIFIÉ] Le seul filtre humain existant, le blocage d'export financier (§4.8), ne couvre que **10 types financiers** — les domaines, URL, IP et adresses courriel, c'est-à-dire l'essentiel du flux CTI, partent **sans verdict d'analyste** |

---

### Tableau comparatif

| Critère | **S1 — Law enforcement** | **S2 — Entité NIS2 essentielle** | **S3 — Observatoire fédéré** |
|---|---|---|---|
| Autorise l'engagement | Autorité judiciaire, agent habilité nominativement | Organe de direction de l'entité | Chaque opérateur de nœud, sous charte |
| Nature de l'autorisation | **Habilitation individuelle par acte** — non modélisée dans le code | **Gouvernance interne** — modélisable avec l'existant | **Contractuelle** — sans effet technique |
| Porte la responsabilité | État / service / agent | **L'entité, seule et sans ambiguïté** | **Distribuée, responsabilité conjointe non résolue** |
| Homologue | Autorité qualifiée ministérielle, homologation préalable | **Aucune homologation externe** ; acceptation de risque interne, contrôle *a posteriori* | Aucune ; cadre de confiance à créer *ex nihilo* |
| Livrable exigé de l'éditeur | Dossier d'homologation, preuve de déterminisme, chaîne de preuve | **SBOM, versions supportées, doc exacte, inférence locale, preuves SIEM** | Tout S2 + identité de nœud, flux signés, multi-tenance, gouvernance pluri-personnes |
| Écart le plus dur | **Valeur probante** du journal (§8.9) et absence d'humain habilité dans la boucle | **Souveraineté de l'inférence** (§2.1) et zonage (§2.6) | **Empoisonnement du flux** (§2.3) et intégrité inter-nœuds |
| Nature de l'écart dominant | **Institutionnelle** — le logiciel ne peut pas produire l'habilitation | **Technique et bornée** | **Institutionnelle + technique**, cumulées |
| Compatible avec un mainteneur unique | Non — exige un engagement de support | **Oui** — l'entité assume l'exploitation | Non — la fédération dépend de la pérennité de l'éditeur |
| Le blocage d'export financier existant (§4.8) suffit-il ? | Non — il faut une traçabilité par acte | **Oui pour la classe qu'il couvre**, à étendre | Non — 10 types couverts sur ~36 |
| Les artefacts produits servent-ils aux autres scénarios ? | Sur-spécifiés pour S2/S3 | **Oui — sous-ensemble commun de S1 et S3** | Sur-spécifiés, inatteignables d'abord |

---

### Recommandation : **S2, entité régulée NIS2 essentielle, auto-hébergée**

Cinq arguments, dans l'ordre de force.

**1. C'est le seul scénario dont la chaîne d'autorisation ne demande pas au logiciel
ce qu'un logiciel ne peut pas produire.** S1 exige qu'un agent individuellement
habilité porte l'acte d'engagement. [DÉDUIT] Aucune évolution technique ne crée une
habilitation : au mieux on ajoute une porte d'approbation humaine — que le pipeline
n'a d'ailleurs pas aujourd'hui (§12 Q8) — mais l'obstacle est la qualité de la
personne, pas l'existence de la porte. En S2, l'autorisation est un acte de
gouvernance interne, et cela, le système sait le matérialiser : verdict d'analyste
(§4.8 D71), kill switch (§4.5 D47), journal chaîné (§8.3).

**2. La responsabilité y est mono-partie, donc opposable.** S2 place l'entité en
responsable de traitement unique. S3 laisse ouverte une responsabilité conjointe
entre nœuds sur des données personnelles de tiers, alors même que la table
`indicator` n'a **aucune purge** (§7.7) et qu'aucun mécanisme de rétractation
n'existe. [DÉDUIT] Traiter la souveraineté et la rétention est déjà exigeant ;
y ajouter une question de responsabilité conjointe non résolue rendrait l'analyse
d'écart indécidable, puisque l'obligation elle-même serait indéterminée.

**3. Les écarts de S2 sont techniques et bornés ; ceux de S1 et S3 sont
institutionnels.** Les trois points durs de S2 — souveraineté de l'inférence, zonage
de la couche d'engagement, immuabilité du journal — sont des travaux de conception
dont le périmètre est mesurable depuis le code : 7 sites de modèle en dur (§3.2),
1 service d'embeddings sans abstraction, 2 nœuds n8n d'enrichissement externe,
1 étape `REVOKE` non appliquée. La valeur probante exigée par S1 et le cadre de
confiance exigé par S3 ne sont pas des travaux de conception logicielle.

**4. Le scénario est compatible avec la réalité du projet.** [VÉRIFIÉ] Un mainteneur,
aucun tag, historique public écrasé sur 6 jours, licence MIT sans garantie. S1 et S3
supposent tous deux un éditeur qui s'engage : support, correction sous délai,
pérennité. S2 est le seul où **l'exploitant assume l'exploitation** et où l'éditeur
doit seulement livrer un produit documenté et vérifiable.

**5. S2 est un sous-ensemble, pas un détour.** SBOM par version, politique de
versions supportées, inférence locale, journal immuable, chaîne de preuve jusqu'au
SIEM, documentation exacte : ces livrables sont exigés par S2 **et** sont des
préalables de S1 et de S3. [DÉDUIT] Choisir S2 ne ferme aucune porte ; choisir S1
d'abord conduirait à construire une chaîne de preuve judiciaire avant d'avoir réglé
la question de savoir si le contenu des courriels sort du périmètre de l'entité —
c'est-à-dire à durcir la preuve d'un flux dont la licéité n'est pas encore établie.

**Réserve explicite.** S2 ne supprime pas les blocages non techniques ; il les
réduit à un ensemble traitable. Trois subsistent et sont analysés en partie C :
la base légale du traitement des données personnelles de tiers apparaissant dans
les échanges, l'usage d'identités synthétiques par une entité soumise à obligation
de loyauté, et le risque d'escalade. [DÉDUIT] Ces trois points ne dépendent pas du
scénario retenu : ils existent identiquement en S1 et S3, où ils s'ajoutent aux
obstacles propres à ces cadres.

---

### Ce que je n'ai pas pu vérifier — partie A

1. Le déploiement de production actuel relève-t-il déjà de l'un de ces trois cadres,
   ou d'un quatrième — recherche académique, exploitation individuelle — dont les
   obligations diffèrent ?
2. L'exploitant actuel est-il lui-même une entité régulée, ou une personne physique
   agissant à titre de recherche ?
3. Dans quelle juridiction les boîtes-appâts de production sont-elles hébergées, et
   dans laquelle l'exploitant est-il établi ?
4. Le tiers déployeur visé par cet audit est-il identifié, ou le besoin est-il
   générique ?
5. Existe-t-il un engagement de support ou un contrat de service envisagé entre
   l'éditeur et un déployeur, qui modifierait la répartition des livrables ?
6. Les boîtes-appâts sont-elles des adresses créées de toutes pièces, ou des adresses
   ayant appartenu à des personnes réelles — ce qui changerait la nature des données
   reçues ?
7. Le volume réel de conversations simultanées en production est-il proche des
   plafonds configurés (50 conversations actives/jour, `rate_limiter.yaml:38-41`) ou
   d'un ordre de grandeur en dessous ?
8. Une analyse d'impact relative à la protection des données a-t-elle été
   effectivement conduite et validée, ou `docs/09_dpia_template.md` est-il resté à
   l'état de gabarit — son nom de fichier comporte « template » ?
9. Des artefacts produits par le système ont-ils déjà été transmis à une autorité,
   un CERT ou un tiers, et sous quelle forme ?
10. Le partage TAXII est-il activé en production (`TAXII_API_KEY` a un défaut vide,
    `.env.dist:396`), et si oui, avec quels destinataires ?
11. L'exploitant a-t-il déjà reçu une réclamation, une demande d'accès ou une
    contestation d'une personne concernée apparaissant dans les échanges ?
12. Le scénario pilote doit-il être choisi pour un déployeur français, européen, ou
    sans hypothèse de juridiction — ce qui détermine directement la liste de
    référentiels applicables en partie B ?

---

<!-- PARTIES B, C, D : en attente de validation du scénario pilote (STOP 1) -->
