# 034 — Tasks: Fix Scam Classification + Risk Score

## Task 1: Inject ScamClassificationHandler into IngestHandler
- [ ] Add `ScamClassificationHandler` as constructor dependency
- [ ] After conversation creation + first message, call `classifyConversation(convId)`
- [ ] Wrap in try/catch with warning log on failure
- [ ] Verify ScamClassificationHandler exists and has `classifyConversation()` method

## Task 2: Compute initial risk score from IOC types
- [ ] Create `computeInitialRisk(string $scamTypeCode, array $iocs): int` method
- [ ] Base score per scam type: PHISHING=40, CEO_FRAUD=70, ROMANCE=30, INVOICE_FRAUD=60, TECH_SUPPORT=35, INVESTMENT=50, LOTTERY=30, ADVANCE_FEE_419=40, JOB_OFFER=35, CHARITY=25, PHISH_MALWARE=65
- [ ] Bonus: +20 if any IBAN/wallet, +10 if phone, +15 if URL count > 1, +5 per distinct IOC type
- [ ] Cap at 100
- [ ] Replace hardcoded `$dto->score_risk` with computed value

## Task 3: Update risk after enrichment
- [ ] In `IocHandler` enrichment callback (or PATCH endpoint handler), after updating IOC enrichment data:
  - Get parent message's conversation
  - Call `calculateMessageRisk(msgId)`
  - Update `conversation.score_risk` with `max(current, new_score)`
  - Flush

## Task 4: Write integration tests
- [ ] Test: ingest a phishing email → scam_type != UNKNOWN (may need mock LLM)
- [ ] Test: ingest email with IBAN → risk_score > 50
- [ ] Test: ingest email with no IOCs → risk_score = base for scam type
- [ ] Test: classification failure → scam_type stays UNKNOWN (graceful)

## Task 5: Write unit tests
- [ ] Test: `computeInitialRisk('PHISHING', [url, email])` returns expected score
- [ ] Test: `computeInitialRisk('CEO_FRAUD', [iban])` returns high score
- [ ] Test: `computeInitialRisk('UNKNOWN', [])` returns low default

## Task 6: CI verification
- [ ] `make test` passes
- [ ] `make stan` passes
- [ ] PHP-CS-Fixer clean
- [ ] No regressions on existing tests
