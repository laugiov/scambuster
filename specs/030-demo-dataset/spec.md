# 030 — Production-Quality Demo Dataset

## Problem

After `make quickstart`, every screen of ScamBuster is empty or nearly empty. A decision-maker evaluating the platform sees:
- Dashboard: 0 conversations, 0 IOCs
- Analytics: flat charts, no trends
- Personas: 27 personas all in "Cold Start", no convergence history
- Monitoring > Pipeline: no traces
- Monitoring > Injection: no detections
- Monitoring > LLM Costs: $0
- Campaigns: no campaigns detected

This makes it impossible to assess the platform's value. The goal is to provide a **single command** (`make demo-load`) that fills every screen with realistic, coherent data simulating 8 weeks of honeypot operation.

## Goal

Convince a SOC manager, CERT lead, or cybersecurity executive to deploy ScamBuster in production by showing immediate, tangible value on every screen.

## Design principles

1. **Referential integrity** — Every conversation uses a valid `(scam_type, persona)` pair from the 60 existing associations in `scam_type_persona`
2. **Full English** — All message bodies, subjects, IOC values in English
3. **Every screen populated** — No empty chart, no "Cold Start" everywhere, no "0 entries"
4. **Internally coherent** — IOCs in messages match IOCs in `observed_ioc`, pipeline costs match `llm_usage`, conversation timestamps match message timestamps, persona stats match actual conversation counts
5. **Realistic but obviously fake** — RFC 5737 IPs (`198.51.100.x`), `+1-555-xxxx` phones, `*-verify.com` domains, TEST IBANs

## Data to generate per table

### 1. `conversation` — 150 conversations

| Field | Value |
|-------|-------|
| `conv_id` | Generated UUIDs |
| `scam_type_id` | Looked up from `lkp_scam_type` — only valid pairs |
| `persona_id` | Looked up from `persona` — must match scam_type via `scam_type_persona` |
| `status` | 90 closed (60%), 37 open (25%), 15 abandoned (10%), 8 mistake (5%) |
| `score_risk` | 0-100, correlated with scam type (romance=30-60, phishing=50-80, CEO fraud=70-95) |
| `ts_first` / `ts_last` | Spread across 8 weeks, week 1-2 ramp-up, 3-5 steady, 6-8 peak |
| `stix_id` | `demo-{first 32 chars of conv_id}` |
| `engagement_duration_sec` | 3600-604800 (1 hour to 7 days), longer for romance |
| `turns_count` | 2-8 (matches actual message count / 2) |
| `reward_value` | 0.0-1.0, correlated with turns and IOC count |
| `delivery` | 'api' |
| `tlp` | 'AMBER' |

**Distribution by scam type:**

| Scam Type | Count | Risk Range | Avg Turns |
|-----------|-------|------------|-----------|
| PHISHING | 25 | 50-80 | 3-4 |
| PHISH_CREDENTIALS | 20 | 55-85 | 3-5 |
| ROMANCE | 18 | 30-60 | 5-8 |
| INVOICE_FRAUD | 16 | 60-90 | 3-5 |
| TECH_SUPPORT | 14 | 40-70 | 3-5 |
| INVESTMENT | 12 | 50-80 | 4-6 |
| LOTTERY | 10 | 35-65 | 3-5 |
| CEO_FRAUD | 10 | 70-95 | 3-4 |
| ADVANCE_FEE_419 | 8 | 40-70 | 4-6 |
| JOB_OFFER | 8 | 35-65 | 3-5 |
| CHARITY | 5 | 25-50 | 3-4 |
| PHISH_MALWARE | 4 | 60-90 | 2-3 |

### 2. `message` — ~600 messages (3-6 per conversation, alternating in/out)

Each conversation has a realistic email thread:
- Message 1: Inbound (scammer initial contact)
- Message 2: Outbound (honeypot reply — persona-appropriate)
- Message 3: Inbound (scammer follow-up)
- Message 4+: Alternating, fewer for abandoned conversations

| Field | Value |
|-------|-------|
| `msg_id` | Generated UUIDs |
| `conv_id` | Parent conversation |
| `direction` | Alternating: 1 (in) → 2 (out) → 1 → 2... |
| `lang_detect` | 'en' |
| `subject` | Realistic per scam type (e.g., "URGENT: Verify your account") |
| `body_text` | English scam content / persona replies (see templates below) |
| `headers` | JSON with email headers + `pipeline_trace` for outbound messages |
| `injection_analysis` | JSON for ~15% of inbound messages (prompt injection attempts) |
| `composite_hash` | SHA256(conv_id + msg_index) |
| `ts_msg` | Within conversation's ts_first..ts_last range |

**Outbound message `headers.pipeline_trace`** (for pipeline monitoring):
```json
{
  "conversation_id": "uuid",
  "persona": "persona_code",
  "scam_type": "SCAM_TYPE_CODE",
  "detected_language": "en",
  "total_duration_ms": 1200-4500,
  "total_cost": 0.0008-0.0035,
  "attempts": 1-3,
  "approved": true/false,
  "fallback_used": false,
  "component_count": 4,
  "has_alerts": false,
  "components": [
    {"name": "prompt_builder", "status": "ran", "durationMs": 50-150, "cost": 0},
    {"name": "llm_generator", "status": "ran", "durationMs": 800-3000, "cost": 0.0005-0.003},
    {"name": "policy_guard", "status": "ran", "durationMs": 100-300, "cost": 0.0001-0.0003},
    {"name": "reply_validator", "status": "ran", "durationMs": 200-500, "cost": 0.0002-0.0005}
  ]
}
```

**Inbound message `injection_analysis`** (~25 messages, 15% of inbound):
```json
{
  "risk_score": 5-95,
  "detected_techniques": [
    {"technique": "jailbreak_attempt|role_override|instruction_leak", "evidence": "...", "severity": "low|medium|high"}
  ],
  "confidence": 0.6-0.98,
  "summary": "...",
  "pattern_matches": ["DAN_pattern", "ignore_previous", "system_prompt_extract"],
  "model_version": "gpt-4o-mini",
  "analyzed_at": "ISO timestamp"
}
```

### 3. `observed_ioc` — ~450 IOCs (2-5 per conversation, from inbound messages only)

| Field | Value |
|-------|-------|
| `obs_id` | Generated UUIDs |
| `msg_id` | Parent inbound message |
| `indicator_id` | MD5(type:value) |
| `context_observation` | JSON: `{type, value, value_norm, category, source: "demo-dataset"}` |
| `confidence_score` | 0.7-1.0 |
| `ts_observed` | Same as parent message ts_msg |

**IOC distribution:**

| Type | ~Count | Examples |
|------|--------|----------|
| email | 100 | `support@secure-paypal-verify.com`, `hr@globaltech-careers.net` |
| url | 120 | `https://secure-paypal-verify.com/login`, `http://198.51.100.42/invoice.pdf` |
| domain | 80 | `secure-paypal-verify.com`, `microsoft-support-help.com` |
| phone | 50 | `+1-555-0142`, `+44-20-7946-0958` |
| ipv4 | 40 | `198.51.100.x`, `203.0.113.x` (RFC 5737) |
| iban | 30 | `GB82TEST60161331926819` |
| wallet_btc | 20 | `1DemoXXX...` |
| sha256 | 10 | Fake hashes for malware samples |

**Campaign clustering**: 5 groups of conversations share the same domains/IPs/emails to enable campaign detection:
- Campaign A (PayPal phishing): 8 conversations share `secure-paypal-verify.com` + `198.51.100.10`
- Campaign B (Microsoft tech support): 6 conversations share `microsoft-support-help.com` + `+1-555-0199`
- Campaign C (Invoice fraud ring): 5 conversations share `GB82TEST60161331926819` + `invoices@payment-portal-uk.com`
- Campaign D (Romance scam network): 4 conversations share `203.0.113.50` + `lonely-hearts-connect.com`
- Campaign E (Crypto investment): 3 conversations share `1DemoWalletBTC...` + `crypto-yield-farm.io`

### 4. `llm_usage` — ~300 records (1-2 per outbound message)

| Field | Value |
|-------|-------|
| `conversation_id` | Parent conversation UUID |
| `provider` | 'openai' |
| `model` | 'gpt-4o-mini' |
| `purpose` | 'reply_generation', 'policy_guard', 'reply_validation', 'ioc_scoring' |
| `prompt_tokens` | 800-2500 |
| `completion_tokens` | 100-500 |
| `estimated_cost_usd` | 0.0003-0.003 per call |
| `created_at` | Same as message ts_msg |

**Total cost target**: ~$4.50 over 8 weeks (credible for gpt-4o-mini with 150 conversations).

**Weekly cost progression**: $0.20 (week 1) → $0.35 → $0.50 → $0.60 → $0.65 → $0.70 → $0.75 → $0.60 (week 8)

### 5. `persona_performance_stats` — ~80 records

One record per valid `(persona_id, scam_type_id)` pair that has conversations in the demo.

| Field | Value |
|-------|-------|
| `persona_id` | FK to persona |
| `scam_type_id` | FK to lkp_scam_type |
| `sessions_count` | Matches actual conversation count for this pair |
| `reward_sum` | sessions_count × reward_avg |
| `reward_avg` | 0.30-0.90, with clear winners per scam type |
| `last_updated` | Recent timestamp |

**Bandit convergence story** (visible on Performance page):
- PHISHING: `worried_customer` dominates (0.82 avg, 12 sessions) > `bank_customer` (0.65, 8 sessions)
- ROMANCE: `lonely_divorcee` dominates (0.85 avg, 9 sessions) > `hopeless_romantic` (0.72, 5 sessions)
- INVOICE_FRAUD: `accountant_meticulous` dominates (0.78 avg, 8 sessions) > `admin_assistant` (0.60, 5 sessions)
- TECH_SUPPORT: `confused_user` dominates (0.80 avg, 7 sessions) > `tech_newbie` (0.68, 4 sessions)
- CEO_FRAUD: `admin_assistant` dominates (0.75 avg, 5 sessions) — still exploring
- All other pairs: 1-4 sessions each, diverse rewards showing exploration

### 6. `bandit_convergence_log` — ~90 records (8 weeks × ~12 scam types, sampled)

Daily snapshots showing convergence emerging over time.

| Field | Value |
|-------|-------|
| `scam_type_code` | Active scam type code |
| `dominant_persona_code` | Best persona for that day |
| `dominant_pct` | 30-85%, growing over weeks |
| `sessions_count` | Cumulative sessions that day |
| `converged` | false for weeks 1-4, true for top 3-4 scam types by week 6-8 |
| `logged_at` | One entry per scam type per 2-3 days |

**Convergence story**: PHISHING converges first (week 5), then ROMANCE (week 6), then INVOICE_FRAUD (week 7). Others still exploring.

### 7. `campaign` + `campaign_rule` + `message_campaign` — 5 campaigns

| Campaign | Status | Severity | Rules | Conversations |
|----------|--------|----------|-------|---------------|
| PayPal Credential Harvesting | promoted | 4 | 2 rules (PPV 0.92, 0.88) | 8 conversations |
| Microsoft Tech Support Fraud | promoted | 3 | 1 rule (PPV 0.90) | 6 conversations |
| UK Invoice Payment Redirect | shadow | 5 | 1 rule (PPV 0.85, 12 hits) | 5 conversations |
| Romance Scam Ring (West Africa) | shadow | 3 | 1 rule (PPV 0.78, 6 hits) | 4 conversations |
| Crypto Yield Farming Scam | shadow | 4 | 1 rule (PPV 0.82, 4 hits) | 3 conversations |

Each campaign has:
- `profile_yaml` with IOC summary, infrastructure details, TTP description
- `campaign_rule` entries with DSL, PPV, hit counts
- `message_campaign` links connecting scam messages to campaigns

## Message content templates (English)

### Inbound (scammer) — per scam type

**PHISHING / PHISH_CREDENTIALS:**
```
Subject: URGENT: Unusual activity detected on your account
Dear valued customer, We have detected unauthorized access to your account from IP address {ip}. To prevent suspension, please verify your identity immediately: {url}. Failure to respond within 24 hours will result in permanent account closure. Customer Security Team, {domain}
```

**ROMANCE:**
```
Subject: Hello from {city}
Hi there, I came across your profile and felt an instant connection. My name is {name} and I'm a {profession} currently working overseas. I know this might seem forward, but life is short and I believe in following my heart. Would you like to get to know each other? I'd love to hear about your day.
```

**INVOICE_FRAUD:**
```
Subject: Updated Payment Details — Invoice INV-{number}
Dear Accounts Payable, Please note that our banking details have changed effective immediately. All outstanding payments should be redirected to the following account: IBAN: {iban}. Please process invoice INV-{number} (${amount}) to the updated account. Regards, Finance Department
```

**TECH_SUPPORT:**
```
Subject: CRITICAL SECURITY ALERT — Your Computer Is At Risk
WARNING: Our Microsoft Certified Security Team has detected {count} malware threats on your device. Your personal data including passwords and banking information may be compromised. Call our emergency hotline immediately: {phone}. Reference: MSFT-{ticket}
```

**CEO_FRAUD:**
```
Subject: Confidential — Urgent wire transfer needed
Hi {name}, I need you to process an urgent wire transfer today. This is for a confidential acquisition and cannot wait until Monday. Amount: ${amount} to account {iban}. Please handle this personally and keep it between us for now. I'll explain when I'm back in the office. — {ceo_name}, CEO
```

**INVESTMENT:**
```
Subject: Exclusive Investment Opportunity — 300% Returns Guaranteed
Dear Investor, You have been selected for our exclusive AI-powered trading platform. Our algorithms have generated {pct}% returns for our members this quarter alone. Minimum investment: ${amount}. Visit {url} to claim your spot before it closes. Limited to 50 new accounts this month.
```

**LOTTERY:**
```
Subject: CONGRATULATIONS! You've Won £{amount}
Official Notification: Your email address was selected in the {lottery_name} International Lottery draw held on {date}. Prize: £{amount}. To claim your winnings, contact our claims agent at {email} with your full name and phone number. Processing fee: £{fee}. Ref: {ref}
```

### Outbound (honeypot) — persona-appropriate, short examples

Responses vary by persona tone (30-150 words each). No names, no signatures (per new BasePromptRules). Examples:

**worried_customer** responding to phishing:
> Oh no, this is really concerning! I did notice some strange charges on my statement last week. What exactly do I need to do? Should I click that link you sent? I'm worried about my savings — I have my children's college fund in that account. Please help me fix this as quickly as possible!!

**accountant_meticulous** responding to invoice fraud:
> Thank you for the notification. Before processing any payment, I need the following documentation: (1) Original purchase order reference number, (2) Signed change of bank details form on company letterhead, (3) Updated W-9 or equivalent tax form. Our internal policy requires dual authorization for any bank detail changes. I've flagged this to my manager who will need to countersign. Expected processing time is 5-7 business days once all documentation is received.

## Files to modify

| File | Change |
|------|--------|
| `scambuster-dataset-sample.json` | Complete rewrite — 150 English conversations with all metadata |
| `src/UI/Console/LoadDemoDataCommand.php` | Extend to load: pipeline traces, injection analysis, llm_usage, persona stats, convergence logs, campaigns |
| `Makefile` | Add `quickstart-demo` target |
| `docs/QUICKSTART.md` | Document demo mode |

## Acceptance criteria

1. `make demo-load` loads all data in < 60 seconds with zero errors
2. **Dashboard**: 150 conversations, ~450 IOCs, risk distribution chart populated
3. **Conversations**: mix of open/closed/abandoned/mistake, all 12 scam types, all personas
4. **IOC Explorer**: 450+ IOCs, all 8 types, filterable
5. **Analytics**: all 8 charts populated with 8-week trends, cost timeline showing ~$4.50 total
6. **Personas > Performance**: all 27 personas have sessions, clear winners per scam type, no "Cold Start" everywhere
7. **Personas > Convergence**: 90+ snapshots, PHISHING/ROMANCE/INVOICE converged, others exploring
8. **Monitoring > Pipeline**: pipeline traces on outbound messages showing latency/cost/components
9. **Monitoring > Injection**: ~25 inbound messages with injection analysis (mix of low/medium/high risk)
10. **Monitoring > LLM Costs**: cost data growing over 8 weeks, ~$4.50 total
11. **Campaigns**: 5 campaigns (2 promoted, 3 shadow) with shared IOCs and rules
12. Every `(scam_type, persona)` pair in conversations exists in `scam_type_persona` table
13. All IOCs use fake/reserved values (RFC 5737 IPs, +1-555 phones, TEST IBANs)
14. `make test` passes — demo data doesn't break existing tests
15. Dataset JSON < 2 MB
