# 034 — Fix Scam Classification + Risk Score Pipeline

## Problem

All ingested emails get `scam_type = UNKNOWN` and `score_risk = 50` regardless of content.

### Root cause: Classification

`IngestHandler.php` line 329 hardcodes every new conversation to `unknown` scam type. Classification is designed to happen **during reply generation** in `ReplyHandler.getConversationContext()` — but this is too late. The persona selection, engagement strategy, and conversation lifecycle policy all depend on the scam type being correct from the start.

### Root cause: Risk Score

The n8n workflow `WF-INTAKE-EMAIL-V2` sends `score_risk: 50` in the ingest payload. After IOC enrichment (VirusTotal, URLscan), the risk score is **never updated** back on the conversation. The `IocHandler.calculateMessageRisk()` method exists but its result is never persisted.

## Solution

### A. Trigger classification at ingestion time

After the conversation is created in `IngestHandler`, call `ScamClassificationHandler::classifyConversation()` immediately. This uses the LLM to classify the first inbound message and updates the conversation's scam_type.

```php
// In IngestHandler, after conversation creation:
if ($this->scamClassifier !== null) {
    try {
        $this->scamClassifier->classifyConversation($conversation->getConvId());
    } catch (\Throwable $e) {
        $this->logger->warning('Auto-classification failed, keeping UNKNOWN', ['error' => $e->getMessage()]);
    }
}
```

Fallback: if classification fails (LLM error, timeout), keep UNKNOWN — the ReplyHandler will retry during reply generation.

### B. Update risk score after IOC enrichment

After `IocHandler` processes enrichment results (VirusTotal, URLscan), compute the aggregated risk score and persist it to the conversation:

```php
// In IocHandler, after enrichment:
$riskData = $this->calculateMessageRisk($msgId);
$conversation = $message->getConversation();
$conversation->updateRiskScore((int) $riskData['score_agg']);
$this->em->flush();
```

### C. Compute initial risk from IOCs at ingestion

Even before enrichment, compute an initial risk score based on the types and count of extracted IOCs:
- Has IBAN/wallet → risk +20
- Has phone → risk +10
- Has URL with suspicious domain → risk +15
- Has multiple IOC types → risk +5 per type
- Base risk from scam type: PHISHING=40, CEO_FRAUD=70, ROMANCE=30, etc.

## Files to modify

| File | Change |
|------|--------|
| `src/Application/Communication/IngestHandler.php` | Call classification after conversation creation; compute initial risk |
| `src/Application/Communication/IocHandler.php` | After enrichment, update conversation risk score |
| `tests/Integration/Communication/IngestHandlerTest.php` | Test classification is triggered |
| `tests/Unit/Application/Communication/RiskScorerTest.php` | Test initial risk computation |

## Acceptance criteria

1. New conversations are classified with correct scam type (not UNKNOWN) within 5 seconds of ingestion
2. If LLM classification fails, conversation stays UNKNOWN (graceful fallback)
3. Risk score reflects IOC presence immediately after ingestion (not fixed at 50)
4. Risk score updates after enrichment data arrives
5. `make test` passes
6. CI green (PHPStan, CS-Fixer, tests)
