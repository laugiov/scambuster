# 033 — Tasks: Demo Dataset Polish

## Task 1: Fix PHP-CS-Fixer CI failure
- [ ] Run `docker compose exec backend-dev vendor/bin/php-cs-fixer fix` on GenerateDemoDataCommand.php
- [ ] Commit the fix
- [ ] Verify CI passes

## Task 2: Create scam-context phrase pools
- [ ] Define 5-6 context phrases per scam type (12 types × ~5 = ~65 phrases)
- [ ] PHISHING: "my account", "this suspicious activity", "the security alert", "the verification link", "my account details"
- [ ] PHISH_CREDENTIALS: "my password", "this login issue", "my email access", "the authentication problem", "my credentials"
- [ ] ROMANCE: "your message", "our connection", "getting to know you", "your story", "hearing from you"
- [ ] INVOICE_FRAUD: "this invoice", "the payment details", "the bank change", "the transfer", "our accounts department"
- [ ] TECH_SUPPORT: "my computer", "this virus warning", "the security alert", "my device", "the malware issue"
- [ ] CEO_FRAUD: "this transfer request", "the wire payment", "your instructions", "this urgent matter", "the confidential deal"
- [ ] INVESTMENT: "this investment", "the trading platform", "the returns you mentioned", "the deposit", "this opportunity"
- [ ] LOTTERY: "this prize", "my winnings", "the lottery notification", "the claim process", "the processing fee"
- [ ] ADVANCE_FEE_419: "this proposal", "the fund transfer", "the estate", "the partnership", "the legal documents"
- [ ] JOB_OFFER: "this job offer", "the position", "the onboarding process", "the remote work opportunity", "the equipment fee"
- [ ] CHARITY: "your cause", "the donation", "the children you mentioned", "the relief effort", "the organization"
- [ ] PHISH_MALWARE: "this file", "the attachment", "the document you shared", "the download link", "the report"

## Task 3: Inject {context} into outbound templates
- [ ] Add `{context}` and `{context2}` placeholders in existing outbound templates where generic language appears
- [ ] Replace "this situation" → "{context}" throughout
- [ ] Replace "your request" → "{context}" or "{context2}"
- [ ] Replace "the information you provided" → "the details about {context}"
- [ ] Each template should have 1-2 context references
- [ ] Test that context phrases read naturally in all scam type combinations

## Task 4: Create per-persona signature phrases
- [ ] Define 2-3 signature phrases for each of the 27 personas
- [ ] Formal group: bank_customer, accountant_meticulous, admin_assistant (3 × 3 = 9 phrases)
- [ ] Anxious group: worried_customer, tech_newbie, debtor_desperate (3 × 3 = 9)
- [ ] Warm group: senior_isolated, lonely_divorcee, lonely_person, charity_donor (4 × 3 = 12)
- [ ] Skeptical group: senior_suspicious, lottery_skeptic (2 × 3 = 6)
- [ ] Direct group: small_business_owner, entrepreneur_rushed (2 × 3 = 6)
- [ ] Casual group: student_busy, buyer_eager (2 × 3 = 6)
- [ ] Romantic group: hopeless_romantic, widow_grieving (2 × 3 = 6)
- [ ] Neutral group: remaining 8 personas (8 × 2 = 16)
- [ ] Total: ~70 signature phrases

## Task 5: Implement signature injection in applyPersonaFlair()
- [ ] Rewrite `applyPersonaFlair()` to:
  1. Pick 1 signature phrase for this persona (not same as last used)
  2. Insert it as a natural sentence (after first paragraph or before closing)
  3. Apply existing flair (typos, abbreviations, exclamation marks) on top
- [ ] Track last used signature per conversation to avoid repetition

## Task 6: Implement message length variation
- [ ] Create `adjustLength(string $text, string $length): string` method
- [ ] Short (20% chance): keep first 1-2 sentences + add a short question
- [ ] Medium (50% chance): keep as-is
- [ ] Long (30% chance): add 1-2 sentences of filler appropriate to persona group
- [ ] Filler examples: warm → personal anecdote, anxious → more worry, formal → additional procedure mention
- [ ] Apply after template selection and before persona flair

## Task 7: Write additional follow-up inbound templates
- [ ] Add 4-6 new templates per scam type across stages
- [ ] PHISHING: +5 follow-ups (total ~16)
- [ ] ROMANCE: +5 (varied emotional progression)
- [ ] INVOICE_FRAUD: +4 (different legal threats)
- [ ] TECH_SUPPORT: +4 (escalating technical jargon)
- [ ] All other types: +3-4 each
- [ ] Total: ~50 new inbound follow-up templates

## Task 8: Add follow-up placeholders
- [ ] Add to `resolveConversationPlaceholders()`:
  - `{deadline}` pool: "24 hours", "48 hours", "by Friday", "end of business today", "within the hour", "before midnight"
  - `{consequence}` pool: "permanent suspension", "legal action", "account closure", "service interruption", "criminal referral"
  - `{threat_count}` → random_int(3, 47)
- [ ] Update existing follow-up templates to use `{deadline}` and `{consequence}` where appropriate
- [ ] Update new templates from Task 7 to use these placeholders

## Task 9: Regenerate + validate
- [ ] Run `php-cs-fixer fix` on all modified files
- [ ] Run `scambuster:demo:generate`
- [ ] Validate outbound uniqueness ≥ 70%
- [ ] Validate inbound uniqueness maintained ≥ 90%
- [ ] Validate IOC coherence = 0 mismatches
- [ ] Validate 0 consecutive repeats
- [ ] Manual review: read 10 random conversations
  - [ ] Check context references match scam type
  - [ ] Check personas within same group produce different responses
  - [ ] Check message lengths vary
- [ ] Verify dataset < 3 MB
- [ ] `make demo-up` → all screens populated

## Task 10: Deploy
- [ ] Commit all changes
- [ ] `make test` passes (no regressions)
- [ ] Push to main
- [ ] Merge main → demo → push
- [ ] Set DEMO_FORCE_RESEED=true on Railway → redeploy → remove variable
- [ ] Verify live demo conversations are diverse and contextual
