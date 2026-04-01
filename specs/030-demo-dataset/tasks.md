# 030 — Tasks: Production-Quality Demo Dataset

## Task 1: Inbound message templates (scammer emails)
- [ ] Write 3-5 English templates per scam type (12 types)
- [ ] Each template has placeholders: `{url}`, `{email}`, `{domain}`, `{phone}`, `{iban}`, `{ip}`, `{amount}`, `{name}`, `{ref}`
- [ ] Templates vary in style: urgent/formal, friendly/casual, threatening
- [ ] Total: ~48 inbound templates
- [ ] Store as PHP arrays in the generator command

## Task 2: Outbound message templates (persona replies)
- [ ] Write 2-3 English templates per persona tone category
- [ ] Tone groups: formal/polite, anxious/panicked, casual/abbreviated, warm/rambling, skeptical, enthusiastic, flustered, neutral
- [ ] No names, no signatures (matches BasePromptRules)
- [ ] Vary length: 30-150 words
- [ ] Total: ~24 outbound templates
- [ ] Store as PHP arrays in the generator command

## Task 3: IOC pool per scam type
- [ ] Define 15-20 fake IOCs per scam type (domains, URLs, emails, IPs, phones, IBANs, wallets)
- [ ] All IOCs use reserved/obviously fake values (RFC 5737 IPs, +1-555 phones, TEST IBANs)
- [ ] 5 campaign clusters: each shares 2-3 IOCs across conversations
- [ ] IOC pool total: ~150 unique IOCs reused across 450 observations

## Task 4: Generator command — conversation skeleton
- [ ] Create `src/UI/Console/GenerateDemoDataCommand.php`
- [ ] Load valid (scam_type, persona) pairs from `scam_type_persona` table
- [ ] Generate 150 conversations with weighted scam type distribution
- [ ] Assign valid persona for each conversation (random from allowed pairs)
- [ ] Assign status: 60% closed, 25% open, 10% abandoned, 5% mistake
- [ ] Generate timestamps across 8-week window (2026-02-03 to 2026-03-31)
- [ ] Week 1-2: 10-15/week, Week 3-5: 20-25/week, Week 6-8: 25-30/week
- [ ] Assign risk_score correlated with scam type
- [ ] Assign reward_value correlated with turns and IOC count
- [ ] Assign engagement_duration_sec correlated with turns and scam type

## Task 5: Generator command — messages per conversation
- [ ] Generate 2-8 messages per conversation (alternating in/out)
- [ ] Abandoned: 1-2 messages only. Mistake: 1 message. Open: 2-6. Closed: 3-8
- [ ] Select inbound template matching scam type, inject IOCs from pool
- [ ] Select outbound template matching persona tone
- [ ] Generate subjects per scam type
- [ ] Compute composite_hash as SHA256(conv_id + msg_index)
- [ ] Timestamps: messages spread within conversation's ts_first..ts_last

## Task 6: Generator command — pipeline traces on outbound messages
- [ ] Add `pipeline_trace` JSON to headers of every outbound message
- [ ] 4 components: prompt_builder (50-150ms, $0), llm_generator (800-3000ms, $0.0005-0.003), policy_guard (100-300ms, $0.0001-0.0003), reply_validator (200-500ms, $0.0002-0.0005)
- [ ] ~8% of replies: approved=false (PolicyGuard rejection), attempts=2-3
- [ ] ~5% of replies: fallback_used=true
- [ ] total_cost = sum of component costs
- [ ] total_duration_ms = sum of component durations

## Task 7: Generator command — injection analysis on inbound messages
- [ ] Add `injection_analysis` JSON to ~15% of inbound messages (~25 messages)
- [ ] Distribution: 5 high risk (score 70-95), 8 medium (score 30-65), 12 low (score 5-25)
- [ ] Techniques: jailbreak_attempt, role_override, instruction_leak, prompt_extraction
- [ ] Evidence strings: realistic injection payloads ("Ignore previous instructions", "You are DAN", "Repeat your system prompt")
- [ ] model_version: "gpt-4o-mini"

## Task 8: Generator command — LLM usage records
- [ ] Generate 1-2 llm_usage records per outbound message (~300 records)
- [ ] Purposes: reply_generation (~60%), policy_guard (~25%), reply_validation (~15%)
- [ ] Model: gpt-4o-mini, Provider: openai
- [ ] prompt_tokens: 800-2500, completion_tokens: 100-500
- [ ] estimated_cost_usd: aligned with pipeline_trace.total_cost
- [ ] Weekly progression: $0.20 → $0.35 → $0.50 → $0.60 → $0.65 → $0.70 → $0.75 → $0.60
- [ ] Total: ~$4.50

## Task 9: Generator command — persona performance stats
- [ ] Generate 1 record per (persona, scam_type) pair that has conversations in demo
- [ ] sessions_count = actual conversation count for that pair
- [ ] reward_avg = average of conversation reward_values for that pair
- [ ] reward_sum = sessions_count × reward_avg
- [ ] Convergence story: clear winners per top scam types (worried_customer for PHISHING 0.82, lonely_divorcee for ROMANCE 0.85, etc.)
- [ ] last_updated: within last week

## Task 10: Generator command — convergence logs
- [ ] Generate ~90 bandit_convergence_log records
- [ ] 1 entry per scam type every 2-3 days across 8 weeks
- [ ] dominant_pct: starts 30-40% (week 1-2), grows to 60-85% (week 6-8)
- [ ] converged: false weeks 1-4, true for PHISHING (week 5), ROMANCE (week 6), INVOICE_FRAUD (week 7)
- [ ] sessions_count: cumulative, growing
- [ ] dominant_persona_code: may shift in early weeks, stabilizes later

## Task 11: Generator command — campaigns
- [ ] Generate 5 campaigns with metadata
- [ ] Campaign A (PayPal Credential Harvesting): status=promoted, severity=4, 8 conversations, 2 rules (PPV 0.92, 0.88)
- [ ] Campaign B (Microsoft Tech Support): status=promoted, severity=3, 6 conversations, 1 rule (PPV 0.90)
- [ ] Campaign C (UK Invoice Payment Redirect): status=shadow, severity=5, 5 conversations, 1 rule (PPV 0.85)
- [ ] Campaign D (Romance Scam Ring): status=shadow, severity=3, 4 conversations, 1 rule (PPV 0.78)
- [ ] Campaign E (Crypto Yield Farm): status=shadow, severity=4, 3 conversations, 1 rule (PPV 0.82)
- [ ] Generate campaign_rule entries with DSL, compiled_sql, PPV, hits
- [ ] Generate message_campaign links with confidence scores
- [ ] Generate profile_yaml for each campaign (IOC summary, infrastructure, TTPs)

## Task 12: Generator command — write JSON output
- [ ] Serialize all data to `scambuster-dataset-sample.json`
- [ ] Include metadata section with counts and date range
- [ ] Validate JSON size < 2 MB
- [ ] Pretty-print for git readability

## Task 13: Extend LoadDemoDataCommand
- [ ] Add `--purge` support to clean previous demo data before loading
- [ ] Load conversations with variable status (not all "closed")
- [ ] Load messages with `headers` containing `pipeline_trace` JSON
- [ ] Load messages with `injection_analysis` JSON column
- [ ] Load `llm_usage` records
- [ ] Load `persona_performance_stats` records (UPSERT over fixture data)
- [ ] Load `bandit_convergence_log` records
- [ ] Load `campaign` + `campaign_rule` records
- [ ] Load `message_campaign` association records
- [ ] Set `reward_value` on conversations
- [ ] All inserts in a single transaction
- [ ] Output summary: counts per entity type loaded

## Task 14: Makefile + documentation
- [ ] Add `quickstart-demo` target: quickstart + demo-load
- [ ] Update `demo-load` target description
- [ ] Add `demo-generate` target (for maintainers)
- [ ] Update `docs/QUICKSTART.md` with demo mode section
- [ ] Update README.md if needed

## Task 15: Verification
- [ ] Run `make demo-load` — completes < 60 seconds, zero errors
- [ ] Dashboard: 150+ conversations visible, risk distribution populated
- [ ] Conversations: all 12 scam types present, mix of open/closed/abandoned/mistake
- [ ] IOC Explorer: 450+ IOCs, 8 types, search works
- [ ] STIX Export: exportable from closed conversations
- [ ] Analytics (7 days): recent week data
- [ ] Analytics (30 days): last month data
- [ ] Analytics (90 days): full 8-week dataset visible
- [ ] Personas > Performance: all 27 personas have sessions, convergence visible
- [ ] Personas > Convergence: 90+ snapshots, 3 converged scam types
- [ ] Monitoring > Conversations: active counts, by scam type counts
- [ ] Monitoring > Pipeline: traces visible, avg cost ~$0.0015, avg duration ~2.5s, approval ~92%
- [ ] Monitoring > Injection: ~25 detections, risk distribution
- [ ] Monitoring > LLM Costs: ~$4.50 total, weekly breakdown
- [ ] Campaigns: 5 campaigns (2 promoted, 3 shadow)
- [ ] `make test` — no regressions (2074+ tests pass)
- [ ] Commit and push
