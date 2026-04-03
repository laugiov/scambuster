# 034 — Plan: Fix Scam Classification + Risk Score

## Step 1: Add classification to IngestHandler

1. Inject `ScamClassificationHandler` into `IngestHandler` constructor
2. After conversation creation + first message insertion, call `classifyConversation()`
3. Wrap in try/catch — keep UNKNOWN on failure
4. Log classification result

## Step 2: Compute initial risk score from IOCs

1. After IOC extraction in IngestHandler, compute a basic risk score:
   - Base score from scam type (after classification)
   - +20 if IBAN/wallet present, +10 if phone, +15 if suspicious URL, +5 per IOC type
2. Update conversation.score_risk with computed value
3. Replace hardcoded `50` in n8n workflow with dynamic score (or let backend compute)

## Step 3: Update risk score after enrichment

1. In `IocHandler`, after enrichment PATCH endpoint processes results, recalculate conversation risk
2. Use existing `calculateMessageRisk()` to aggregate
3. Update `conversation.score_risk` via `updateRiskScore()`

## Step 4: Write tests

1. Integration test: ingest an email → verify scam_type is NOT UNKNOWN
2. Integration test: ingest with IOCs → verify risk > 50
3. Unit test: risk computation from IOC types
4. Integration test: enrichment callback updates risk score

## Step 5: CS-Fixer + PHPStan + CI verification
