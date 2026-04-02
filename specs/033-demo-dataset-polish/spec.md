# 033 — Demo Dataset Polish: Outbound Diversity & Scam Context

## Problem

The v2 dataset (spec 032) solved inbound diversity (92% unique) and IOC coherence (0 mismatches). But outbound messages remain weak:

1. **Outbound uniqueness at 31%** — 8 persona groups share the same templates. A `generic_user`, `tech_intermediate`, and `freelance_cautious` (all group "neutral") produce identical responses.

2. **Responses ignore scam context** — A `worried_customer` says "Oh no this sounds serious!!" whether it's a phishing email, an invoice fraud, or a tech support scam. A real person would reference the specific situation ("my account??", "this invoice??", "my computer??").

3. **Follow-up inbound messages repeat across conversations** — Only 3-4 follow-up templates per stage per scam type. With 25 PHISHING conversations, the same follow-ups appear 6-8 times.

4. **Persona flairs are superficial** — Adding "lol" or "!!" to the same base text doesn't fool anyone clicking more than 2 conversations.

5. **No message length variation** — All messages at the same stage are ~150 words. Real exchanges have short replies ("ok what do I need to do") and long ones.

6. **No scam-context words in responses** — Templates say "this situation", "your request" generically. A decision-maker reading a PHISHING conversation expects to see "my account", "the suspicious activity", not generic language.

## Goal

Push outbound uniqueness above 70% and make every conversation feel like a real interaction between a specific persona and a specific scam type.

## Target metrics

| Metric | Current (v2) | Target (v3) |
|--------|-------------|-------------|
| Outbound full body unique | 31% (175/570) | 70%+ |
| Inbound full body unique | 92% | 92%+ (maintain) |
| IOC coherence | 0 mismatches | 0 (maintain) |
| Consecutive repeats | 0 | 0 (maintain) |

## Solution

### A. Scam-context injection in outbound templates

Instead of fully generic templates, outbound templates receive a **context fragment** that references the scam type. The generator injects scam-appropriate phrases into the response body.

**Context fragment pools per scam type:**

| Scam Type | Context phrases |
|-----------|----------------|
| PHISHING | "my account", "this suspicious activity", "the security alert", "the verification link", "my account details" |
| PHISH_CREDENTIALS | "my password", "this login issue", "my email access", "the authentication problem", "my credentials" |
| ROMANCE | "your message", "our connection", "getting to know you", "your story", "hearing from you" |
| INVOICE_FRAUD | "this invoice", "the payment details", "the bank change", "the transfer", "our accounts department" |
| TECH_SUPPORT | "my computer", "this virus warning", "the security alert", "my device", "the malware issue" |
| CEO_FRAUD | "this transfer request", "the wire payment", "your instructions", "this urgent matter", "the confidential deal" |
| INVESTMENT | "this investment", "the trading platform", "the returns you mentioned", "the deposit", "this opportunity" |
| LOTTERY | "this prize", "my winnings", "the lottery notification", "the claim process", "the processing fee" |
| ADVANCE_FEE_419 | "this proposal", "the fund transfer", "the estate", "the partnership", "the legal documents" |
| JOB_OFFER | "this job offer", "the position", "the onboarding process", "the remote work opportunity", "the equipment" |
| CHARITY | "your cause", "the donation", "the children you mentioned", "the relief effort", "the organization" |
| PHISH_MALWARE | "this file", "the attachment", "the document you shared", "the download", "the report" |

Templates use a `{context}` placeholder that gets replaced with a randomly picked context phrase matching the conversation's scam type.

Example transformation:
- **Before**: "Thank you for your correspondence. I have noted the details provided. Before proceeding, I need to verify **this situation**."
- **After (PHISHING)**: "Thank you for your correspondence. I have noted the details about **the security alert**. Before proceeding, I need to verify **my account status**."
- **After (INVOICE_FRAUD)**: "Thank you for your correspondence. I have noted the details about **this invoice**. Before proceeding, I need to verify **the payment change** with our accounts department."

### B. Per-persona template variants (not just per-group)

Instead of 8 groups × 5 templates = 40 unique outbound templates, add **persona-specific variations** that modify the base template more substantially than just flair.

For each persona within a group, create 2-3 **signature phrases** that get injected into the base template:

| Persona | Signature phrases |
|---------|------------------|
| `bank_customer` | "I have banked with them for 30 years", "I always review my statements carefully", "My account has never had an issue before" |
| `accountant_meticulous` | "Our fiscal year-end is approaching", "I need the invoice number cross-referenced", "My manager Mr. Lefèvre will need to approve" |
| `admin_assistant` | "I need to check with my manager first", "My inbox is overflowing today", "I apologize for the delay — three managers need me at once" |
| `worried_customer` | "I have children to think about!!", "My friend lost everything to hackers last year!!", "I checked my balance and something looks wrong!!" |
| `tech_newbie` | "My daughter set up my computer", "I call the browser 'the internet button'", "I am terrified of breaking something" |
| `debtor_desperate` | "I lost my job three months ago", "Every bill feels like a countdown", "My children are depending on me" |
| `senior_isolated` | "My cat Minou was sitting on my lap when I read this", "My neighbor Jacqueline mentioned something like this", "My late husband Raymond used to handle these things" |
| `lonely_divorcee` | "Since my divorce I have been more careful", "My two teenagers keep me busy", "I have started hiking again to clear my head" |
| `lonely_person` | "I live alone and order delivery most nights", "My plants are my only company", "Working from home gets lonely" |
| `charity_donor` | "I volunteer at the food bank every Thursday", "I sponsor two children through an NGO", "My late pharmacist career taught me to care" |
| `senior_suspicious` | "My son-in-law in IT warned me about this", "Two years ago someone stole 800 euros from me", "I always check reference numbers" |
| `lottery_skeptic` | "How can I win something I never entered?", "The probability of this being real is negligible", "I will need independently verifiable proof" |
| `small_business_owner` | "I wake at 3 AM to run my bakery", "I have four employees depending on me", "Business is business — get to the point" |
| `entrepreneur_rushed` | "swamped w/ the Q2 pipeline review rn", "my asst will handle the details", "between meetings and cant call" |
| `student_busy` | "i have a shift at the coffee shop in 20 min", "my roommate thinks this is sus", "im literally between lectures rn" |
| `buyer_eager` | "I track every flash sale!", "Is there a promo code?", "What is the return policy?" |
| `hopeless_romantic` | "Your words are poetry to my soul", "I believe in love at first message", "My heart tells me to trust you" |
| `widow_grieving` | "My spouse passed eight months ago", "The empty chair at dinner reminds me every day", "I am still learning to manage alone" |
| Other neutrals | Generic signature phrases about being careful, needing time, asking for details |

The generator picks 1-2 signature phrases per message and injects them into the response.

### C. More follow-up inbound templates

Double the follow-up templates per scam type from ~11 to ~20:
- `follow_early`: 4 → 8 templates
- `follow_mid`: 4 → 7 templates
- `follow_late`: 3 → 5 templates

This reduces repetition across conversations of the same type.

### D. Message length variation

Templates come in 3 length variants:
- **Short** (30-60 words): Quick reaction, one-liner with a question
- **Medium** (80-120 words): Standard reply with details
- **Long** (130-200 words): Detailed response with personal context

The generator randomly picks a length variant per message, with a bias toward medium.

### E. Inbound follow-up diversity boost

Add placeholders in follow-up templates that vary per conversation:
- `{deadline}` → "24 hours", "48 hours", "by Friday", "end of business today"
- `{consequence}` → "permanent suspension", "legal action", "account closure", "service interruption"
- `{threat_count}` → random int (3-47)

This makes even same-template follow-ups look different when read.

## Files to modify

| File | Change |
|------|--------|
| `src/UI/Console/GenerateDemoDataCommand.php` | Add context injection, persona signatures, length variants, more follow-ups, follow-up placeholders |
| `scambuster-dataset-sample.json` | Regenerated |

## Acceptance criteria

1. Outbound full body uniqueness ≥ 70%
2. Inbound full body uniqueness maintained ≥ 90%
3. IOC coherence maintained at 0 mismatches
4. No consecutive repeats within any conversation
5. Outbound messages reference the specific scam context (not generic "this situation")
6. Each persona produces recognizably different responses from other personas in the same group
7. Message lengths vary visibly within a conversation (some short, some long)
8. `make demo-up` works, all screens populated
9. Dataset < 3 MB

## Estimated effort

- Context injection system + pools: 1-2 hours
- Persona signature phrases (27 personas): 2-3 hours
- Additional follow-up templates (~50 new): 2-3 hours
- Length variants + follow-up placeholders: 1-2 hours
- Testing + validation: 1 hour
- **Total: ~7-10 hours**

## Out of scope

- Completely rewriting outbound templates (we keep the group structure, just enhance it)
- Adding new persona groups
- Changing conversation count or scam type distribution
- Real-time scam content
