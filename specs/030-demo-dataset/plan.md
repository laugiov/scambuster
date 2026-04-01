# 030 — Plan: Production-Quality Demo Dataset

## Overview

Generate a coherent 150-conversation English demo dataset and extend the LoadDemoDataCommand to populate every screen of ScamBuster. The dataset is a single JSON file loaded by `make demo-load`.

## Architecture decision: PHP generator script

The dataset generation is a **PHP Symfony command** (`scambuster:demo:generate`) that:
1. Reads reference data from the database (scam types, personas, valid pairs)
2. Generates 150 conversations with realistic messages, IOCs, pipeline traces
3. Writes `scambuster-dataset-sample.json`

This ensures referential integrity by construction — the generator queries the actual DB for valid scam_type/persona pairs.

The **loader** (`scambuster:demo:load`) then reads the JSON and inserts into all tables.

## Step 1: Design the data model in JSON

Extend the JSON structure to include all required data:

```json
{
  "metadata": {
    "generated_at": "2026-04-01T12:00:00Z",
    "version": "2.0",
    "conversations_count": 150,
    "messages_count": 600,
    "iocs_count": 450,
    "campaigns_count": 5,
    "date_range": { "start": "2026-02-03", "end": "2026-03-31" }
  },
  "conversations": [ ... ],
  "campaigns": [ ... ],
  "persona_performance_stats": [ ... ],
  "convergence_logs": [ ... ],
  "llm_usage": [ ... ]
}
```

Each conversation contains its messages, and each inbound message contains its IOCs — same nesting as current format but enriched with pipeline_trace and injection_analysis.

## Step 2: Write message templates

Create template arrays for each scam type (inbound) and each persona tone (outbound). Templates use placeholders for IOCs that get replaced during generation:

**Inbound templates** — 3-5 variants per scam type (12 types × 4 avg = ~48 templates)
**Outbound templates** — 2-3 variants per persona tone category (8 tone groups × 3 = ~24 templates)

Templates are embedded in the generator command as PHP arrays — no external files needed.

## Step 3: Write the generator command

`scambuster:demo:generate` — generates the JSON:

1. **Load reference data** from DB:
   - All active scam types (12, excluding unknown)
   - All active personas (27)
   - Valid (scam_type, persona) pairs from `scam_type_persona` (60 pairs)

2. **Plan conversation distribution**:
   - 150 conversations across 12 scam types (weighted distribution)
   - For each conversation: pick valid persona from the scam type's allowed list
   - Assign status: 60% closed, 25% open, 10% abandoned, 5% mistake
   - Assign timestamps: 8-week window, ramping distribution

3. **Generate messages per conversation**:
   - Turns count: 2-8 (based on scam type and status)
   - Alternating inbound/outbound
   - Inbound: pick template for scam type, inject IOCs (from IOC pool per scam type)
   - Outbound: pick template matching persona tone, add pipeline_trace in headers
   - ~15% of inbound messages get injection_analysis

4. **Generate campaign groupings**:
   - 5 campaigns with shared IOCs across conversations
   - Each campaign: profile_yaml, rules with PPV, message_campaign links

5. **Generate persona performance stats**:
   - For each (persona, scam_type) pair that has conversations: compute stats from actual conversation rewards
   - Ensure convergence story: clear winners per top scam types

6. **Generate convergence logs**:
   - 90 entries across 8 weeks
   - Show progression from exploring → converging for top 3-4 scam types

7. **Generate llm_usage records**:
   - 1-2 records per outbound message (reply_generation + policy_guard)
   - Cost aligned with pipeline_trace.total_cost

8. **Write JSON** to `scambuster-dataset-sample.json`

## Step 4: Extend LoadDemoDataCommand

Current command loads: conversations, messages, IOCs.

Add loading for:
- `llm_usage` records
- `persona_performance_stats` records (UPSERT — replace existing fixture stats)
- `bandit_convergence_log` records
- `campaign` + `campaign_rule` + `message_campaign` records
- `injection_analysis` column on inbound messages
- `pipeline_trace` in headers column on outbound messages
- Variable `status` (not all "closed")
- `reward_value` on conversations

## Step 5: Makefile integration

`make quickstart` loads demo data automatically — every user sees a fully populated platform on first launch.

```makefile
demo-load: ## Load demo dataset (150 conversations, no API key needed)
    cp scambuster-dataset-sample.json backend-symfony/scambuster-dataset-sample.json
    $(CONSOLE_DEV) scambuster:demo:load
    rm -f backend-symfony/scambuster-dataset-sample.json
```

The `quickstart` target calls `demo-load` after fixtures, before n8n setup. No separate command needed.

## Step 6: Update QUICKSTART.md

Add section:

```markdown
### Demo mode (no API key needed)

To see ScamBuster with realistic demo data (150 conversations, 450 IOCs, 5 campaigns):

    make quickstart-demo

Or load demo data into an existing installation:

    make demo-load
```

## Step 7: Verification

1. `make demo-load` — completes in < 60 seconds
2. Check every screen manually:
   - Dashboard: 150+ conversations, 450+ IOCs
   - Conversations: all 12 scam types, mix of statuses
   - IOC Explorer: 8 IOC types, filterable
   - Analytics: all 8 charts populated
   - Personas > Performance: winners per scam type
   - Personas > Convergence: 90+ snapshots
   - Monitoring > Pipeline: traces with latency/cost
   - Monitoring > Injection: ~25 detections
   - Monitoring > LLM Costs: ~$4.50 total
   - Campaigns: 5 campaigns with shared IOCs
3. `make test` — no regressions

## Execution order

```
Step 1 (JSON model)
  → Step 2 (message templates)
    → Step 3 (generator command)
      → Step 4 (extend loader)
        → Step 5 (Makefile)
          → Step 6 (docs)
            → Step 7 (verify)
```

Sequential — each step depends on the previous.

## Risk mitigation

- **Dataset too large for git**: Target < 2 MB. 150 conversations × ~4 KB each = ~600 KB. With metadata ~800 KB-1.2 MB.
- **Referential integrity failures**: Generator reads DB for valid pairs — impossible to create invalid associations.
- **Stale reference data**: Generator runs against live DB, not hardcoded IDs.
- **Timezone issues**: All timestamps in UTC.
