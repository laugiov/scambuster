# 028 — Plan: Persona Prompt Refactor

## Overview

Three surgical changes: rewrite 27 system prompts, add 3 global rules, create a migration for production alignment.

## Step 1: Update BasePromptRules.php

Add 3 adaptation rules to `BasePromptRules::getRules()`:

```php
'Accept whatever name the attacker uses for you as your own. Never correct them on your name.',
'Adapt to the scenario the attacker presents — if they mention an invoice, you have concerns about that invoice. If they mention a package, you were expecting a delivery.',
'Do not systematically sign your messages. When you do sign, use the name the attacker used for you, or a short first name only.',
```

These are appended after the persona prompt (recency bias — LLM prioritizes last instructions).

**File:** `src/Application/LLM/Prompt/BasePromptRules.php`

## Step 2: Rewrite 27 persona system prompts in PersonaFixtures.php

For each persona, apply these rules:
- **Remove:** first name, surname, specific age, city/region name
- **Keep:** profession, personality traits, behavioral patterns, life details (cat, late husband, student loans...), communication style quirks
- **Switch:** third person ("X is a...") → second person ("You are a...")
- **Remove:** any instruction to sign with a specific name

### Rewrite guidelines per persona

| Code | Remove | Keep |
|------|--------|------|
| `senior_trusting` | Marcel Dupont, 70, Lyon, "signs with full name" | Retired postal worker, trusts institutions, dated expressions, old-fashioned courtesy |
| `senior_suspicious` | Brigitte Moreau, 68, Strasbourg | Retired teacher, bank fraud victim, questions everything, mentions son-in-law in IT |
| `senior_isolated` | Odette Blanchard, 75, Nantes | Widow, cat Minou, late husband Raymond, grandchildren far away, rambling |
| `small_business_owner` | Philippe Garnier, 52, Toulouse, "Boulangerie Garnier" | Bakery owner, 4 employees, wakes 3 AM, zero patience, plain vocabulary |
| `entrepreneur_rushed` | Karim Benziane, 38, Paris | Digital agency CEO, 15 employees, typos, KPI/ASAP/ROI jargon, telegraphic |
| `accountant_meticulous` | Catherine Vidal, 45, Bordeaux, Mr. Lefèvre | Corporate accountant, 20 years invoices, reference numbers, VAT codes, mentions manager |
| `freelance_cautious` | Léa Martin, 34, Montpellier | Freelance graphic designer, home studio, asks scope/timelines/budgets, portfolio mention |
| `admin_assistant` | Emma Petit, 29, Lille | Admin assistant, insurance company, 3 managers, overwhelmed, checks with manager |
| `tech_newbie` | Monique Faure, 62, Grenoble | Retired nurse, first laptop last Christmas, "internet button", daughter set up email |
| `tech_intermediate` | Julien Roche, 40, Lyon | Marketing manager, clears cache, troubleshoots Wi-Fi, neutral tone, curious |
| `student_busy` | Chloé Durand, 22, Rennes | Communications student, part-time coffee shop, "tbh"/"rn"/"idk", skips punctuation |
| `lonely_divorcee` | Nathalie Renard, 48, Annecy | Recently divorced, 18 years marriage, two teenage kids, hiking, guarded warmth |
| `hopeless_romantic` | François Beaumont, 55, Avignon | Librarian, reads love stories, florid language, ellipses, falls fast |
| `widow_grieving` | Henri Marchand, 65, Dijon, Claire | Recently widowed, wife passed 8 months ago, 38 years together, melancholic, empty chair |
| `bank_customer` | Bernard Leroy, 58, Marseille | Retail bank customer, same account 30 years, formal, trusts official tone |
| `worried_customer` | Sophie Dumas, 42, Nantes | Panicked, mother of three, exclamation-filled, impulsive, clicks without verifying |
| `investor_greedy` | Thierry Roussel, 50, Nice | Amateur investor, COVID discovery, ROI/yields jargon, fears missing out |
| `lottery_skeptic` | Damien Cartier, 44, Rennes | Systems engineer, methodical, asks for proof, mentions probability |
| `lottery_believer` | Gérard Fontaine, 67, Biarritz | Optimistic retiree, breathless enthusiasm, plans cruise/bicycles, processing fees normal |
| `lonely_person` | Antoine Lefèvre, 35, Clermont-Ferrand | Introverted software tester, lives alone, talks to plants, craves connection |
| `confused_user` | Martine Bouvier, 55, Limoges | Office worker, filing/photocopies, repetitive sentences, trusts "experts" |
| `debtor_desperate` | Rachid Hamidi, 40, Saint-Denis | Single father of two, lost warehouse job, mounting debts, desperate for lifeline |
| `job_seeker` | Thomas Girard, 27, Lille | Recent graduate, international business, unemployed 5 months, student loans |
| `buyer_eager` | Amélie Vasseur, 33, Rouen | Online shopping enthusiast, flash sales, bubbly, impulsive, clicks before thinking |
| `elderly_person` | Sylvie Perrot, 72, Aix-en-Provence, Jacqueline | Grandmother, four children, seven grandchildren, "the screen thing", Sunday roasts |
| `generic_user` | Pierre Lambert, 45, Tours | Office worker, logistics company, moderate, balanced, neither suspicious nor naive |
| `charity_donor` | Jacqueline Morel, 69, Pau | Retired pharmacist, sponsors children via NGO, volunteers at food bank, compassionate |

**File:** `src/DataFixtures/Communication/PersonaFixtures.php`

## Step 3: Create migration for production DB

A Doctrine migration that runs 27 UPDATE statements to patch `persona.system_prompt` in production without wiping data.

```sql
UPDATE persona SET system_prompt = '...' WHERE persona_code = 'senior_trusting';
UPDATE persona SET system_prompt = '...' WHERE persona_code = 'senior_suspicious';
-- ... 25 more
```

**File:** `migrations/VersionYYYYMMDDHHMMSS.php`

## Step 4: Verify

1. `make test` — all 2074+ tests pass
2. `make quickstart` on fresh env — fixtures load new prompts
3. Manual check: query DB to confirm no names/cities in system_prompt
4. Grep check: no first names remain in PersonaFixtures.php system_prompt strings

## Execution order

```
Step 1 (BasePromptRules)  →  Step 2 (Fixtures)  →  Step 3 (Migration)  →  Step 4 (Verify)
```

Steps 1 and 2 are independent and can be done in parallel. Step 3 depends on Step 2 (needs final prompt texts). Step 4 is last.

## Risk mitigation

- **Before committing**: run `make test` to verify no test depends on specific persona names
- **After deployment**: monitor first 5-10 LLM replies to check adaptation quality
- **Rollback**: migration is reversible (store old prompts in DOWN method)
