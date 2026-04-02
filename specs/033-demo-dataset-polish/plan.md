# 033 — Plan: Demo Dataset Polish

## Overview

Enhance the outbound template system with scam-context injection, per-persona signatures, message length variation, and more follow-up diversity. Fix CS-Fixer CI failure from spec 032.

## Step 1: Fix PHP-CS-Fixer CI failure

Run `php-cs-fixer fix` on `GenerateDemoDataCommand.php` to fix formatting issues introduced in spec 032. This must be done first to unblock the CI.

## Step 2: Add scam-context injection system

Create context phrase pools per scam type (5-6 phrases each, 12 types = ~65 phrases).

Modify outbound template selection to:
1. Pick a base template from the persona group
2. Replace `{context}` placeholder with a randomly selected context phrase for the conversation's scam type
3. Replace `{context2}` with a different phrase from the same pool (for templates that reference the situation twice)

Modify existing outbound templates to include `{context}` and `{context2}` placeholders where they currently say "this situation", "your request", "the information", etc.

## Step 3: Add per-persona signature phrases

Create 2-3 signature phrases per persona (27 personas × 2.5 avg = ~68 phrases).

Modify `applyPersonaFlair()` to:
1. Pick 1 signature phrase per message
2. Insert it naturally (append to first paragraph, or add as a new sentence mid-text)
3. Never repeat the same signature phrase consecutively

This replaces the current simple flair (replace "I am" → "im") with richer persona-specific content.

## Step 4: Add message length variants

For each outbound template, create 3 variants:
- **Short** (30-60 words): cut the template to the first 1-2 sentences + a question
- **Medium** (current): keep as-is
- **Long** (extend): add 1-2 sentences of persona-appropriate padding

The generator picks a length based on weighted random: 20% short, 50% medium, 30% long.

Implementation: rather than tripling templates, create a `adjustLength()` method that trims or extends the selected template.

## Step 5: Double follow-up inbound templates

For each scam type, add 4-6 new follow-up templates across all stages:
- Rewrite existing templates with different wording for the same intent
- Add follow-up placeholders: `{deadline}`, `{consequence}`, `{threat_count}`

## Step 6: Add follow-up placeholders

Create placeholder pools:
```php
'{deadline}' => ['24 hours', '48 hours', 'by Friday', 'end of business today', 'within the hour', 'before midnight']
'{consequence}' => ['permanent suspension', 'legal action', 'account closure', 'service interruption', 'criminal referral', 'data deletion']
'{threat_count}' => random_int(3, 47)
```

Add these to the `resolveConversationPlaceholders()` method so each conversation gets fixed values.

## Step 7: Regenerate + validate

1. Run `php-cs-fixer fix` to ensure CI passes
2. Run `scambuster:demo:generate`
3. Validate: outbound uniqueness ≥ 70%, inbound ≥ 90%, IOC coherence = 0
4. Manual review: 10 conversations — check context references and persona distinctiveness
5. `make demo-up` → all screens populated
6. Push to main → merge to demo

## Execution order

```
Step 1 (CS-Fixer fix)
  → Step 2 (context injection)
    → Step 3 (persona signatures)
      → Step 4 (length variants)
        → Step 5 (more follow-ups)
          → Step 6 (follow-up placeholders)
            → Step 7 (validate + deploy)
```
