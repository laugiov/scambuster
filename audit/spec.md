# Spécification — quoi et pourquoi

> **Portée.** Ce document énonce **ce qui doit être vrai** du système et **pourquoi**,
> sans nommer aucune technologie. Les choix de mise en œuvre sont dans
> `audit/plan.md` ; l'ordonnancement dans `audit/tasks.md`.
>
> **Règle de traçabilité.** Chaque exigence porte l'identifiant de l'écart de
> `audit/02_gap.md` dont elle dérive. **Une exigence sans identifiant d'écart est
> supprimée** — aucune n'a été conservée à ce titre.
>
> **Cadre.** Scénario S2 — entité régulée NIS2, entité essentielle, auto-hébergée,
> périmètre UE sans hypothèse nationale.
>
> **Vocabulaire.**
> **Zone d'engagement** : partie du système en contact avec des correspondants non
> maîtrisés. **Zone de traitement** : partie détenant les données et la logique
> métier. **Engagement** : production et émission d'un message vers un tiers.
> **Déployeur** : l'entité qui exploite. **Éditeur** : celui qui publie le produit.

---

## EX-01 — L'engagement n'a pas lieu sans base légale déclarée

**Dérive de :** G-01, G-02
**Pourquoi.** Informer une personne physique qu'elle interagit avec un système d'IA
est une obligation de conception pesant sur l'éditeur, dont l'exemption est réservée
aux systèmes autorisés par la loi à rechercher des infractions pénales. Un déployeur
qui ne relève pas de cette exemption ne peut pas engager la conversation, et le
produit ne doit pas le lui permettre par inadvertance.

**Exigences.**
1. Sur une installation neuve, aucune fonction d'engagement n'est active.
2. L'activation de l'engagement est une décision explicite et distincte de tout
   interrupteur d'exploitation courante ; elle ne peut pas résulter d'un défaut de
   configuration.
3. L'activation exige que le déployeur consigne la base sur laquelle il l'active.
4. Toutes les fonctions ne relevant pas de l'engagement — réception, analyse,
   extraction, corrélation, restitution, diffusion — restent pleinement opérantes
   lorsque l'engagement est inactif.
5. L'activation et la désactivation sont des événements journalisés.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A1.1 | Sécurité — aucune émission par défaut | Sur une installation issue de la configuration livrée, une campagne d'essai injectant au moins 20 messages entrants produit **0 message sortant** |
| A1.2 | Sécurité — pas de contournement | Aucun chemin d'appel ne permet d'émettre lorsque l'engagement est inactif ; la propriété est vérifiée par un test automatisé qui couvre **tous** les points d'entrée d'émission |
| A1.3 | Fonctionnel — valeur préservée | Avec engagement inactif, la même campagne produit un volume d'indicateurs extraits **égal** à celui obtenu avec engagement actif sur les mêmes messages entrants |
| A1.4 | Auditabilité | Chaque changement d'état produit une entrée d'audit portant l'acteur, l'horodatage et l'état résultant |

---

## EX-02 — Produire un message et l'émettre sont deux privilèges distincts

**Dérive de :** G-21
**Pourquoi.** L'organe de direction doit approuver et superviser les mesures. Il ne
peut superviser un acte qui engage l'entité auprès d'un tiers si rien ne le distingue,
en termes de droits, d'une opération interne sans effet extérieur.

**Exigences.**
1. Le droit de produire un message et le droit de l'émettre sont deux privilèges
   séparés.
2. Le composant automatisé qui pilote le flux ne détient pas le droit d'émission.
3. La séparation est effective à tous les points d'entrée qui provoquent une émission
   ou qui enregistrent une émission comme effectuée.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A2.1 | Sécurité | Un principal détenant le seul droit de production se voit refuser l'émission sur **100 %** des points d'entrée concernés, vérifié par test automatisé |
| A2.2 | Performance | La séparation n'introduit **aucune** validation humaine dans le chemin nominal : le débit d'émission est inchangé |
| A2.3 | Auditabilité | Tout refus lié à un privilège manquant produit une entrée d'audit |

---

## EX-03 — L'absence de sortie du contenu est démontrable

**Dérive de :** G-03, G-04, G-05
**Pourquoi.** L'obligation ne porte pas seulement sur la licéité d'un transfert mais
sur la capacité de l'entité à établir la maîtrise de ses flux. Un réglage qui ne
bascule qu'une partie des chemins ne permet aucune démonstration.

**Exigences.**
1. Le choix du moteur d'inférence est **unique et global** : un seul réglage détermine
   la destination de la totalité des appels, sans exception.
2. Aucun chemin d'appel ne comporte de destination ni d'identifiant de modèle figés
   dans le code.
3. Toutes les fonctions faisant appel à un modèle — production de message, contrôles
   de sortie, classification, extraction, corrélation, vectorisation, profilage,
   évaluation — sont soumises à ce réglage unique.
4. Le déployeur dispose d'un moyen de vérifier, sans lire le code, que la
   configuration en vigueur n'autorise aucune sortie de contenu.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A3.1 | Sécurité — exhaustivité | Configuré sur un moteur interne au périmètre, le système émet **0 requête** vers une destination externe portant du contenu de message, mesuré par observation du trafic sortant sur une campagne complète |
| A3.2 | Sécurité — non-régression | Un contrôle automatisé échoue si un identifiant de modèle ou une destination d'inférence est réintroduit en dur dans le code |
| A3.3 | Auditabilité | Une commande de diagnostic restitue, pour chaque fonction faisant appel à un modèle, la destination effective résolue |

---

## EX-04 — La régression de qualité est mesurée avant tout changement de moteur

**Dérive de :** G-03, G-04, G-05
**Pourquoi.** Rapatrier l'inférence dans le périmètre déplace la qualité. Sans mesure
préalable sur le corpus existant, le déployeur ne peut ni accepter la régression ni la
refuser en connaissance de cause.

**Exigences.**
1. La comparaison s'effectue sur le corpus de référence existant, sans le modifier.
2. Le protocole isole la régression de la fonction de production de celle des
   fonctions de contrôle : **les fonctions de contrôle sont maintenues sur un moteur
   de référence fixe** pendant la mesure de la production.
3. Une seconde campagne mesure l'effet cumulé lorsque les fonctions de contrôle
   basculent également.
4. Le critère de décision est celui déjà en vigueur pour la non-régression ; aucun
   critère nouveau n'est introduit.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A4.1 | Reproductibilité | Deux exécutions du protocole sur la même configuration produisent des écarts inférieurs à la tolérance déjà en vigueur |
| A4.2 | Validité | L'empreinte de l'oracle de sécurité est identique entre la référence et le candidat ; toute divergence invalide la comparaison |
| A4.3 | Complétude | Le rapport porte, au minimum : taux d'approbation, taux de repli, nombre moyen de tentatives, taux par code de violation, et précision/rappel d'extraction d'indicateurs |
| A4.4 | Décision | La bascule n'est prononcée que si la comparaison de non-régression est concluante contre la référence |

---

## EX-05 — La zone d'engagement ne peut pas atteindre le magasin de données

**Dérive de :** G-07, G-08
**Pourquoi.** Le composant qui dialogue avec des correspondants non maîtrisés et
traite leurs pièces jointes est le plus exposé. S'il partage le domaine réseau du
magasin de données, sa compromission donne un accès direct à l'ensemble des données.

**Exigences.**
1. La zone d'engagement et la zone de traitement sont deux domaines distincts.
2. Depuis la zone d'engagement, seuls les points d'entrée applicatifs strictement
   nécessaires au flux sont joignables.
3. Le magasin de données et le magasin d'état volatil ne sont joignables que depuis la
   zone de traitement.
4. La zone de traitement ne dispose d'aucune sortie vers l'extérieur au-delà des
   destinations explicitement recensées.
5. Les identifiants d'accès aux boîtes de réception et d'émission ne sont pas détenus
   par un composant disposant d'un accès au magasin de données.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A5.1 | Sécurité | Depuis la zone d'engagement, toute tentative de connexion directe au magasin de données ou au magasin d'état échoue, vérifié par test |
| A5.2 | Sécurité | La liste des points d'entrée joignables depuis la zone d'engagement est explicite et son dépassement est détecté |
| A5.3 | Fonctionnel | Le flux nominal, de la réception à la restitution, fonctionne sans dégradation après cloisonnement |

---

## EX-06 — Les flux sortants sont recensés et publiés

**Dérive de :** G-07, G-08
**Pourquoi.** Le déployeur doit construire sa propre politique de filtrage. Il ne peut
le faire que si l'éditeur lui fournit la liste exhaustive des destinations légitimes,
de leurs déclencheurs et de leur caractère optionnel ou non.

**Exigences.**
1. Chaque flux sortant est décrit par sa destination, son protocole, son déclencheur,
   la nature des données transmises et son caractère obligatoire ou facultatif.
2. Le recensement couvre l'ensemble des composants, y compris ceux d'orchestration.
3. Le recensement est vérifiable automatiquement contre l'état du code.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A6.1 | Complétude | Un contrôle automatisé échoue si un flux sortant existe dans le code sans figurer au recensement |
| A6.2 | Exploitabilité | Le recensement suffit à construire une politique de filtrage sans lecture du code |
| A6.3 | Sécurité | Tout flux transmettant une donnée d'origine adverse à un tiers est signalé comme tel et est désactivable indépendamment |

---

## EX-07 — Ce qui est exécuté est identifiable

**Dérive de :** G-24, G-25
**Pourquoi.** Sans version, l'éditeur ne peut désigner ni ce qui est affecté par une
vulnérabilité ni ce qui la corrige, et le déployeur ne peut déclarer ce qu'il exploite.

**Exigences.**
1. Chaque publication porte un identifiant de version stable et ordonné.
2. Le système restitue à l'exécution la version qu'il exécute.
3. Une politique de versions supportées énonce ce qui reçoit des correctifs et pour
   combien de temps.
4. Le journal des changements associe chaque changement à une version publiée.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A7.1 | Auditabilité | La version restituée à l'exécution correspond exactement à la publication déployée |
| A7.2 | Exploitabilité | À partir d'un identifiant de version, il est possible d'établir la liste des changements et des correctifs de sécurité inclus |
| A7.3 | Délai | Une vulnérabilité signalée reçoit une version identifiée corrigeant ou documentant le point, dans le délai annoncé par la politique |

---

## EX-08 — La composition logicielle est publiée avec chaque version

**Dérive de :** G-24, G-25
**Pourquoi. **Le déployeur doit tenir compte des risques provenant de ses
fournisseurs et des composants intégrés. Il ne peut le faire que s'il obtient la
composition exacte de ce qu'il exécute, associée à la version déployée.

**Exigences.**
1. Chaque publication est accompagnée de sa nomenclature de composants.
2. La nomenclature couvre les composants applicatifs et ceux du socle d'exécution.
3. La nomenclature est obtenue sans reconstruire le produit.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A8.1 | Complétude | La nomenclature recense les dépendances directes et transitives des deux chaînes applicatives ainsi que les paquets du socle |
| A8.2 | Correspondance | La nomenclature publiée correspond à l'artefact publié sous la même version |
| A8.3 | Exploitabilité | Le déployeur peut confronter la nomenclature à un référentiel de vulnérabilités sans intervention de l'éditeur |

---

## EX-09 — La défaillance du moteur d'inférence conduit à un état sûr et visible

**Dérive de :** G-30
**Pourquoi.** Plusieurs contrôles de sécurité délèguent leur décision à un moteur
d'inférence et adoptent, en cas d'indisponibilité, un comportement permissif. Une
défaillance unique dégrade donc simultanément plusieurs contrôles, sans que le service
s'interrompe et sans que l'exploitant en soit informé.

**Exigences.**
1. L'indisponibilité du moteur d'inférence suspend l'engagement, plutôt que de le
   poursuivre avec des contrôles dégradés.
2. L'état de suspension est observable par l'exploitant et déclenche une alerte.
3. La reprise après rétablissement est explicite et journalisée.
4. Le seuil de déclenchement distingue une erreur transitoire d'une indisponibilité.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A9.1 | Sécurité | En indisponibilité simulée du moteur, le système produit **0 message sortant** |
| A9.2 | Observabilité | L'état de suspension est exposé en supervision dans un délai inférieur à une période de collecte, et déclenche une alerte |
| A9.3 | Disponibilité | Une erreur isolée ne déclenche pas la suspension ; le seuil est configurable et documenté |
| A9.4 | Auditabilité | Suspension et reprise produisent chacune une entrée d'audit |

---

## EX-10 — La documentation décrit le système qui existe

**Dérive de :** G-40, G-41
**Pourquoi.** Une acceptation de risque est signée par un organe de direction sur la
foi d'un dossier documentaire. Vingt-quatre contradictions prouvées entre la
documentation et le code rendent ce dossier inutilisable : un déployeur qui s'y fie
décrit un système qui n'existe pas.

**Exigences.**
1. Toute affirmation portant sur un contrôle implémenté est vérifiable dans le code.
2. Les contrôles annoncés et absents sont retirés ou requalifiés en projet.
3. Les durées, comptages et seuils annoncés correspondent aux valeurs du code.
4. Toute procédure d'exploitation référencée existe.
5. La documentation destinée au déployeur distingue ce qui est livré actif, livré
   inactif, et non livré.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A10.1 | Exactitude | Les 25 contradictions recensées sont traitées, chacune par correction de la documentation ou par implémentation du contrôle annoncé |
| A10.2 | Non-régression | Un contrôle automatisé vérifie la correspondance des valeurs numériques citées dans la documentation avec celles du code |
| A10.3 | Complétude | Aucune procédure d'exploitation référencée n'est absente |

---

## Exigences transverses

### EX-11 — Le mode par défaut est le mode sûr

**Dérive de :** G-15, G-22, G-34
**Pourquoi.** Trois écarts partagent une même cause : la configuration livrée retient
le réglage permissif — pas d'export d'événements de sécurité, contrôle de secrets
limité, conservation non appliquée. Pour un tiers déployeur, le défaut livré fait foi.

**Exigences.**
1. Un réglage dont la valeur permissive affaiblit une propriété de sécurité ou une
   obligation de conservation n'est pas retenu comme valeur par défaut.
2. Lorsqu'un défaut sûr n'est pas possible, le démarrage en production est refusé tant
   que le déployeur n'a pas tranché explicitement.

**Critères d'acceptation.**

| # | Critère | Mesure |
|---|---|---|
| A11.1 | Sécurité | Sur une installation issue de la configuration livrée, aucune propriété de sécurité ne dépend d'une action que le déployeur devrait deviner |
| A11.2 | Exploitabilité | Le refus de démarrage énonce précisément le réglage manquant et l'action attendue |

---

## Ce que cette spécification ne couvre pas

Énoncé pour éviter toute lecture extensive.

| Hors périmètre | Motif |
|---|---|
| Valeur probante des artefacts devant une juridiction | Écartée avec le scénario S1 ; l'exigence retenue est la fiabilité et l'auditabilité, non la preuve opposable |
| Responsabilité civile envers un tiers lésé | Hors de portée technique |
| Qualification juridique des profils de mode opératoire | Décision du responsable de traitement, non écart produit |
| Anonymisation du contenu libre | Écart réel, mais l'argument de non-traitement retenu en phase 2 tient : la suppression est un substitut plus sûr qu'une anonymisation mal implémentée |
| Signature et attestation de provenance des artefacts | Sur-dimensionné au regard des sources citées et de la taille de l'équipe |
| Fédération multi-nœuds | Relève du scénario S3, non retenu |
