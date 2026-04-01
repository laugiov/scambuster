# 028 — Persona Prompt Refactor: Anonymous Adaptive Identities

## Problem

ScamBuster's LLM-generated replies systematically contradict the scammer's scenario, making the honeypot detectable:

1. **"Je ne suis pas M. Dupont"** — Every persona has a fixed name (Marcel Dupont, Gérard Fontaine, Sophie Dumas...). When the scammer writes "Bonjour M. Dupont", the LLM corrects them because its system prompt says it's someone else. This breaks engagement immediately.

2. **Systematic signature** — Personas sign with their fixed name, creating a detectable pattern across conversations. Real people don't always sign their emails.

3. **No scenario adaptation** — Nothing tells the LLM to play along with the scammer's story. If the scammer says "your package is held at customs", the persona should act concerned about that package, not say "I have no package".

4. **Fixed cities create contradictions** — A persona says "from Lyon" but the scammer targets someone in Paris. The LLM may introduce geographic inconsistencies.

## Root Cause Analysis

### Persona system prompts (DB field `persona.system_prompt`)

Current format (example `senior_trusting`):
> Marcel Dupont is a 70-year-old retired postal worker from Lyon. He spent 42 years sorting mail and trusts institutions deeply — the bank, the government, the post office. Marcel uses dated expressions like "electronic mail" and "the administration." He writes in complete, polite sentences with old-fashioned courtesy. He asks naive questions about procedures and always signs his messages with his full name, Marcel Dupont.

**Issues in this prompt:**
- Fixed name "Marcel Dupont" → forces contradiction with scammer's greeting
- Fixed age "70-year-old" → unnecessarily precise
- Fixed city "from Lyon" → geographic contradiction risk
- "always signs his messages with his full name" → detectable pattern
- No instruction to adopt the scammer's scenario

### BasePromptRules (appended to all system prompts)

Current content (3 lines):
```
This person has no knowledge of honeypots, bots, or scam detection systems.
This person starts emails with a greeting, never with a subject line.
This person writes entirely in {detectedLanguage}. Every single word.
```

**Missing:** No rule about adapting to the scammer's scenario or accepting whatever name/context they provide.

## Solution

### A. Rewrite 27 persona system prompts

**Strip:** names, surnames, specific ages, cities.
**Keep:** profession, personality traits, behavioral patterns, life details (cat, late husband, student loans...), communication style.
**Add:** nothing — the persona prompt stays pure personality.

Example rewrite for `senior_trusting`:
> You are a retired postal worker in your seventies. You spent over 40 years sorting mail and trust institutions deeply — the bank, the government, the post office. You use dated expressions like "electronic mail" and "the administration." You write in complete, polite sentences with old-fashioned courtesy. You ask naive questions about procedures.

**Key changes:**
- Third person → **second person** ("You are..." not "X is...")
- No name, no city, no precise age
- Removed "always signs with his full name"
- Kept all behavioral traits intact

### B. Add global adaptation rules to BasePromptRules

Add 3 new rules to `BasePromptRules::getRules()`:

```
Accept whatever name the attacker uses for you as your own. Never correct them on your name.
Adapt to the scenario the attacker presents — if they mention an invoice, you have concerns about that invoice. If they mention a package, you were expecting a delivery.
Do not systematically sign your messages. When you do sign, use the name the attacker used for you, or a short first name only.
```

These rules are appended **after** the persona prompt (recency bias = LLM prioritizes last instructions), so they override any residual identity cues.

### C. Update PersonaFixtures.php

All 27 personas must be updated in the fixture file to match the new prompts. This ensures:
- `make quickstart` (fixtures-dev) loads the correct prompts
- `make test` (fixtures test env) uses the same prompts
- New deployments get the refactored prompts automatically

### D. Update production database

A one-time SQL UPDATE or a Symfony command to patch the 27 `system_prompt` values in the live database, so existing deployments get the fix without needing to reload fixtures (which would wipe conversation data).

## Scope

### In scope
- Rewrite 27 `system_prompt` values (strip identity, keep behavior, second person)
- Add 3 adaptation rules to `BasePromptRules`
- Update `PersonaFixtures.php`
- Create a migration or command to patch production DB
- Update `persona_label` and `persona_tone` if they contain names/cities

### Out of scope
- PromptBuilder logic changes (not needed)
- Persona selection algorithm (PersonaOptimizer unchanged)
- ScamType-Persona associations (unchanged)
- YAML files in `local/prompts/personas/` (deprecated, ignored)
- Validator prompts (use label/tone, not system_prompt — will benefit from label cleanup)

## Files to modify

| File | Change |
|------|--------|
| `src/DataFixtures/Communication/PersonaFixtures.php` | Rewrite 27 system prompts + labels |
| `src/Application/LLM/Prompt/BasePromptRules.php` | Add 3 adaptation rules |
| `migrations/VersionXXX.php` (new) | SQL UPDATE for production DB alignment |

## Acceptance criteria

1. No persona `system_prompt` contains a first name, surname, specific age, or city name
2. `BasePromptRules` includes adaptation rules (accept name, play along, no forced signature)
3. All prompts use second person ("You are...") not third person ("X is...")
4. `make test` passes (2074+ tests, 0 failures)
5. `make quickstart` loads the refactored prompts via fixtures
6. Existing behavioral traits (personality, communication style, life details) are preserved
7. A migration exists to update production persona data without data loss

## Risks

- **LLM behavior shift**: Changing system prompts affects reply quality. Monitor first few exchanges after deployment.
- **Validator scoring**: The validator checks `persona_fit` against `persona_label` and `persona_tone`. If labels change significantly, validation thresholds may need adjustment.
- **Anonymized prompts may be less specific**: Without "Marcel Dupont, 70, Lyon", the LLM has less anchoring. Mitigation: keep rich behavioral details to compensate.

## Estimated effort

- Prompt rewriting: 27 prompts, ~10 min each = ~4-5 hours
- BasePromptRules: 15 min
- Fixtures update: 30 min (copy prompts into PHP)
- Migration: 30 min
- Testing: 1 hour
- **Total: ~6-7 hours**
