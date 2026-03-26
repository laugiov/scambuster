# ScamBuster - Analyse approfondie & Propositions d'amélioration

> Analyse réalisée sur l'ensemble du backend Symfony (agents LLM, prompts, orchestration, validation)

---

## Table des matières

1. [Points forts](#1-points-forts)
2. [Points faibles identifiés](#2-points-faibles-identifiés)
3. [Propositions d'amélioration](#3-propositions-damélioration)
4. [Priorisation](#4-priorisation)

---

## 1. Points forts

### Architecture solide
- **DDD + Hexagonal** bien appliqué : ports/adapters pour les LLM, entités riches, value objects
- **Multi-provider LLM** (OpenAI, Anthropic, Ollama, Mock) interchangeables via config
- **Pipeline de validation double** : PolicyGuard (syntaxique, <1ms) + ReplyValidator (sémantique LLM) = ~99% de sécurité
- **Boucle itérative** (3 tentatives max) avec feedback enrichi entre les passages

### Stratégie adaptative
- **Epsilon-greedy avec UCB1** pour la sélection de persona : bon compromis exploration/exploitation
- **27 personas** couvrant 7 archétypes : diversité des profils de victimes crédibles
- **Détection de convergence** avec réduction automatique de epsilon

### Sécurité & garde-fous
- **Kill switch** global, rate limiting, détection d'injection de prompt (2 couches)
- **PolicyGuard** couvre les cas critiques (menaces, usurpation d'autorité, PII)
- **Fallback placeholder** en cas d'échec total de génération

### Intelligence contextuelle
- **ConversationAnalyzer** avec règles situationnelles (post-IBAN, accusation de bot, agression)
- **ReciprocityManager** pour le réalisme conversationnel (donner/prendre)
- **ContextAnalyzer** avec state slots (stage, IOCs manquants, canal cible)

---

## 2. Points faibles identifiés

### 2.1 Qualité des prompts - Le coeur du problème

#### A. Prompts trop longs et surchargés
Le `PromptBuilder` empile **10+ couches d'instructions** dans le user prompt :
- Contexte, slots, historique, réciprocité, dialogue de génération, format, anti-répétition, règle linguistique finale...
- **Problème** : Les LLM perdent en cohérence quand le prompt est trop dense. Les instructions se contredisent ou se diluent mutuellement. Le modèle ne sait plus quelle instruction prioriser.

#### B. Mélange français/anglais dans les prompts système
- Les règles de base (BASE_PROMPT_RULES) sont en français
- Le prompt de l'IocExtractor est en anglais
- Les instructions de diversité mélangent les deux
- **Problème** : Incohérence linguistique qui confond le modèle, surtout quand on lui demande ensuite de détecter et respecter la langue de l'attaquant.

#### C. Instructions négatives omniprésentes
Les prompts sont dominés par des "NE PAS", "JAMAIS", "INTERDIT" :
- "NE JAMAIS écrire Objet:"
- "NE JAMAIS mélanger les langues"
- "NE RÉPÈTE PAS"
- "NE RÉUTILISE PAS"
- **Problème** : Les LLM répondent mieux aux instructions positives. Les instructions négatives attirent paradoxalement l'attention du modèle sur ce qu'il ne devrait PAS faire, augmentant la probabilité de violation.

#### D. Règle linguistique répétée 3 fois
La règle "réponds dans la même langue" apparaît en début de system prompt, dans le user prompt, ET en fin de user prompt avec des emojis d'alerte.
- **Problème** : Si le modèle ne respecte pas cette règle, la répéter 3 fois ne résout rien. Cela indique un problème structurel (prompts en français qui orientent le modèle vers le français).

#### E. Anti-répétition trop rigide et verbeuse
Le ConversationAnalyzer génère des instructions détaillées (interdictions, obligations) qui sont ensuite injectées dans le prompt du Generator.
- **Problème** : On demande à un LLM (Analyzer) de générer des instructions pour un autre LLM (Generator). Cette chaîne introduit du bruit, des instructions parfois contradictoires, et une complexité inutile. Le résultat est souvent des messages qui *semblent* variés mais sont artificiellement contraints.

### 2.2 Orchestration et génération

#### F. Température unique pour tous les contextes
- Generator : 0.6 fixe, quel que soit le stade de conversation ou la situation
- **Problème** : Un premier contact nécessite plus de créativité (temp plus haute), tandis qu'une négociation de paiement demande plus de précision (temp plus basse).

#### G. Max tokens trop bas (400)
- Le Generator est limité à 400 tokens
- **Problème** : Combiné avec la contrainte 50-150 mots de PolicyGuard, le modèle est pris en étau. 400 tokens ≈ 300 mots en français, mais le modèle doit aussi générer sa "réflexion interne" dans ces 400 tokens si on utilise certains modèles. Marge trop juste.

#### H. Fallback placeholder statique et en français uniquement
```
Bonjour, Merci pour votre message. J'ai bien reçu votre email...
```
- **Problème** : Si l'attaquant écrit en anglais et que la génération échoue 3 fois, on envoie un message en français. Incohérent et potentiellement grillé par le scammer.

#### I. IOCLikelihoodScorer purement heuristique
- Scoring basé sur des règles simples (+25 si question, +25 si canal cible mentionné...)
- **Problème** : Ne capture pas la subtilité contextuelle. Un message peut être excellent pour l'extraction d'IOCs sans contenir de "?" explicite (ex: "J'ai besoin de votre IBAN pour le virement").

### 2.3 Personas et contextualisation

#### J. System prompts des personas trop génériques
Chaque persona a un prompt spécifique, mais ils suivent tous la même structure rigide :
```
RÈGLE #1 — ADAPTATION DU TON
RÈGLE #2 — VARIATION OBLIGATOIRE
[prompt spécifique]
COMPORTEMENT GÉNÉRAL
INTERDICTIONS
SORTIE
```
- **Problème** : L'enveloppe commune (BASE_PROMPT_RULES + BASE_BEHAVIOR) est si lourde qu'elle écrase la personnalité unique de chaque persona. Un "retraité isolé" et un "entrepreneur pressé" finissent par produire des messages avec le même ton administratif.

#### K. Pas de mémoire inter-session
- Le sender history est basique (résumé des conversations précédentes)
- **Problème** : Si le même scammer revient avec une nouvelle adresse, le système ne capitalise pas sur ce qu'il a appris. Pas de profiling progressif de l'attaquant.

#### L. ConversationAnalyzer trop coûteux pour ce qu'il apporte
- Utilise gpt-4o avec 3000 max tokens pour générer des instructions anti-répétition
- **Problème** : Coût disproportionné. Le retour (interdictions/obligations) pourrait être obtenu par des heuristiques simples ou un modèle plus léger.

### 2.4 Validation

#### M. PolicyGuard - Seuils rigides
- Fenêtre 50-150 mots stricte
- **Problème** : Certains contextes nécessitent des réponses très courtes (accusation de bot → 30-40 mots recommandés par le ConversationAnalyzer) mais PolicyGuard rejette en dessous de 50. Contradiction interne.

#### N. ReplyValidator trop permissif par design
Le prompt du validator dit explicitement :
> "APPROUVE même si le ton n'est pas parfait"
> "APPROUVE même si le message est moyen"
> "REJETTE SEULEMENT si clairement robotique"
- **Problème** : On a un validateur qui laisse passer les messages "moyens". Cela garantit la sécurité mais pas la qualité. Le validator devrait aussi évaluer l'efficacité stratégique.

---

## 3. Propositions d'amélioration

### P1. Restructurer les prompts avec une architecture en couches claires

**Impact : FORT | Effort : MOYEN**

Remplacer l'empilement actuel par une structure hiérarchique claire :

```
SYSTEM PROMPT (stable, court) :
├── Identité du persona (2-3 phrases max)
├── Règle linguistique (1 phrase)
└── Contraintes de sécurité (liste courte)

USER PROMPT (dynamique, structuré) :
├── SECTION 1 - Situation : State slots en format clé:valeur
├── SECTION 2 - Historique : Derniers messages (max 3-5)
├── SECTION 3 - Objectif : 1 instruction claire (ex: "Obtiens le BIC")
└── SECTION 4 - Style : Ton + longueur cible
```

**Principe** : Chaque section a un rôle unique. Pas de répétition. Pas d'instructions contradictoires.

### P2. Passer les prompts système dans la langue du contexte détecté

**Impact : FORT | Effort : MOYEN**

Au lieu de répéter 3 fois "réponds dans la même langue" :
- Détecter la langue du dernier message entrant (avec un appel LLM léger ou une lib comme `lingua`)
- Construire le prompt ENTIER dans cette langue
- Maintenir des templates de prompts en FR, EN, ES

**Résultat** : Le modèle produit naturellement dans la bonne langue sans instruction explicite.

### P3. Reformuler les instructions en positif

**Impact : MOYEN | Effort : FAIBLE**

Transformer systématiquement :
| Avant (négatif) | Après (positif) |
|---|---|
| "NE JAMAIS écrire Objet:" | "Commence directement par la salutation" |
| "NE RÉPÈTE PAS les mêmes mots" | "Utilise un vocabulaire frais à chaque message" |
| "NE RÉUTILISE PAS les formules" | "Invente une nouvelle formule de clôture" |
| "NE JAMAIS mélanger les langues" | "Écris entièrement en {langue_détectée}" |

### P4. Température dynamique selon le contexte

**Impact : MOYEN | Effort : FAIBLE**

```php
$temperature = match($stage) {
    'first_contact' => 0.75,   // Créativité pour accrocher
    'follow_up'     => 0.55,   // Équilibre
    'payment_push'  => 0.35,   // Précision pour ne pas griller la couverture
};

// Ajustements situationnels
if ($botAccusation) $temperature = 0.80;  // Réponse très humaine nécessaire
if ($postIban)      $temperature = 0.40;  // Précision maximale
```

### P5. Découpler le ConversationAnalyzer et simplifier

**Impact : FORT | Effort : MOYEN**

Remplacer l'approche actuelle (LLM qui génère des instructions pour un autre LLM) par :

**Option A - Heuristiques intelligentes** (recommandé pour le coût) :
- Extraire les N-grams des messages précédents côté PHP
- Calculer la similarité cosinus entre l'ouverture proposée et les précédentes
- Détecter les patterns répétés par comptage simple
- Injecter uniquement : `"Évite ces ouvertures déjà utilisées : [liste]"`

**Option B - Prompt condensé** (si on garde le LLM) :
- Réduire le ConversationAnalyzer à un seul output : `{"tone": "...", "avoid": ["..."], "goal": "..."}`
- Utiliser gpt-4o-mini au lieu de gpt-4o
- Max 500 tokens au lieu de 3000

### P6. Harmoniser PolicyGuard avec les recommandations du ConversationAnalyzer

**Impact : MOYEN | Effort : FAIBLE**

```php
// PolicyGuard adaptatif
$minWords = match(true) {
    $context->isBotAccusation()  => 25,
    $context->isAggression()     => 30,
    $context->isEvasiveScammer() => 35,
    default                      => 50,
};
$maxWords = match(true) {
    $context->isBotAccusation()  => 60,
    $context->isAggression()     => 80,
    default                      => 150,
};
```

### P7. Fallback multilingue et contextuel

**Impact : MOYEN | Effort : FAIBLE**

```php
private const FALLBACKS = [
    'fr' => "Bonjour, merci pour votre message. Je prends note et reviens vers vous rapidement.",
    'en' => "Hello, thank you for your message. I'll review everything and get back to you shortly.",
    'es' => "Hola, gracias por su mensaje. Lo revisaré y le responderé pronto.",
];
```

### P8. Enrichir le IOCLikelihoodScorer avec du contexte

**Impact : MOYEN | Effort : MOYEN**

Ajouter des signaux contextuels :
- **+20** : Le message crée un prétexte crédible pour demander un IOC (ex: "pour le virement j'aurais besoin de...")
- **+15** : Continuité thématique avec le dernier message de l'attaquant
- **+10** : Le message démontre de la confiance/naïveté (encourage le scammer à donner plus)
- **-25** : Le message ferme une porte conversationnelle ("merci, c'est tout")
- Envisager un scoring LLM léger (gpt-4o-mini, 100 tokens) pour les cas ambigus

### P9. Alléger les system prompts des personas

**Impact : FORT | Effort : MOYEN**

Réduire le wrapper commun au strict minimum :

```
Tu es {persona_label}. {1 phrase de personnalité}.
Ton : {tone}.
{prompt_spécifique_court - max 100 mots}
```

Déplacer toutes les règles techniques (format, interdictions, sécurité) dans le user prompt ou dans le system prompt du validator. Le persona system prompt doit être **100% dédié à la personnalité**.

### P10. Ajouter un "Quality Scorer" post-validation

**Impact : FORT | Effort : MOYEN**

Actuellement : le validator dit "approved/rejected" (binaire).
Proposition : ajouter un score de qualité 1-10 avec critères :
- **Naturel** (1-10) : Est-ce qu'un humain écrirait ça ?
- **Stratégique** (1-10) : Est-ce que ça fait avancer l'extraction d'IOCs ?
- **Persona-fit** (1-10) : Est-ce cohérent avec le profil ?

Si le score moyen < 6/10, relancer la génération même si "approved".
Permet de distinguer entre "acceptable" et "bon".

### P11. Implémenter un profiling progressif de l'attaquant

**Impact : MOYEN | Effort : FORT**

Créer une entité `AttackerProfile` enrichie au fil des conversations :
```php
class AttackerProfile {
    string $emailCluster;          // Emails liés au même acteur
    string $detectedLanguage;
    string $communicationStyle;     // formel/informel/agressif
    array $knownTactics;           // techniques observées
    array $vulnerabilities;        // ce qui a marché pour l'engager
    float $sophisticationScore;    // niveau de sophistication
}
```

Utilisé pour adapter la stratégie : un scammer sophistiqué nécessite un persona plus crédible.

### P12. Ajouter un mécanisme de "few-shot examples" dynamiques

**Impact : FORT | Effort : MOYEN**

Maintenir une base de **messages exemplaires** (validés manuellement) par :
- Type de scam
- Persona
- Stage de conversation
- Situation (post-IBAN, accusation de bot, etc.)

Injecter 1-2 exemples pertinents dans le prompt :
```
Voici un exemple de bonne réponse dans un contexte similaire :
---
{exemple}
---
Inspire-toi du style mais ne copie pas.
```

**Pourquoi** : Le few-shot est le levier le plus puissant pour améliorer la qualité de génération. Plus efficace que 50 lignes d'instructions.

### P13. Réduire la fenêtre d'historique intelligemment

**Impact : MOYEN | Effort : FAIBLE**

Au lieu de toujours envoyer les N derniers messages :
- **first_contact** : 1-2 messages (pas besoin de plus)
- **follow_up** : 3-4 messages
- **payment_push** : 2-3 messages + résumé des échanges clés

Ajouter un résumé LLM des messages anciens plutôt que de les envoyer en brut.

### P14. Implémenter un A/B testing sur les prompts

**Impact : FORT | Effort : MOYEN**

Le système a déjà l'epsilon-greedy pour les personas. Étendre ce mécanisme aux **variantes de prompts** :
- Tester différentes structures de prompt (court vs détaillé)
- Tester différents tons d'instruction (directif vs suggestif)
- Mesurer l'impact sur : taux d'IOCs extraits, durée d'engagement, taux de réponse

### P15. Migrer vers des modèles plus adaptés

**Impact : FORT | Effort : FAIBLE**

- **Generator** : Considérer `claude-sonnet-4-6` ou `gpt-4.1` pour un meilleur suivi d'instructions complexes
- **ConversationAnalyzer** : Downgrader vers `gpt-4o-mini` ou `claude-haiku-4-5` (suffisant pour de l'analyse de patterns)
- **Validator** : Garder un modèle léger mais envisager un fine-tuning sur les cas de rejet

---

## 4. Priorisation

### Quick wins (< 1 jour chacun)
| # | Proposition | Impact |
|---|---|---|
| P3 | Reformuler les instructions en positif | Qualité des réponses |
| P4 | Température dynamique | Pertinence contextuelle |
| P6 | Harmoniser PolicyGuard/ConversationAnalyzer | Cohérence interne |
| P7 | Fallback multilingue | Couverture des cas d'échec |

### Améliorations majeures (1-3 jours chacune)
| # | Proposition | Impact |
|---|---|---|
| P1 | Restructurer les prompts en couches | **Qualité globale** |
| P2 | Prompts dans la langue détectée | **Respect linguistique** |
| P9 | Alléger les system prompts personas | **Personnalité des réponses** |
| P12 | Few-shot examples dynamiques | **Qualité de génération** |
| P5 | Découpler le ConversationAnalyzer | **Coût + cohérence** |

### Évolutions structurelles (1+ semaine)
| # | Proposition | Impact |
|---|---|---|
| P10 | Quality Scorer post-validation | Qualité garantie |
| P11 | Profiling progressif de l'attaquant | Intelligence tactique |
| P14 | A/B testing sur les prompts | Optimisation continue |
| P15 | Migration de modèles | Performance + coût |

---

## Conclusion

Le principal levier d'amélioration est la **simplification et restructuration des prompts** (P1, P2, P3, P9). Le système actuel souffre d'un excès d'instructions qui noient le signal dans le bruit. Un prompt plus court, plus clair, avec des exemples concrets (P12) produira des réponses significativement meilleures qu'un prompt exhaustif qui essaie de tout couvrir.

La deuxième priorité est l'**harmonisation des composants** (P5, P6) : le ConversationAnalyzer recommande des messages courts mais PolicyGuard les rejette, le système demande de la variation mais contraint trop le format.

Enfin, le passage d'une validation binaire (approved/rejected) à un **scoring de qualité** (P10) permettra de distinguer entre "acceptable" et "excellent", ce qui est la clé pour sortir de la médiocrité des réponses "moyennes mais approuvées".
