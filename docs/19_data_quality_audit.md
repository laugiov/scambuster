# Data Quality Audit Guide

This guide describes the complete data quality audit workflow for
ScamBuster, from automated screening to manual deep audit, including
the independent LLM auditor that cross-validates pipeline outputs.

---

## 1. Automated Screening

Automated screening commands verify structural properties of extracted
data without requiring LLM calls. They are fast, deterministic, and
should be run regularly.

### IOC Source Presence

```bash
make verify-iocs
# or directly:
php bin/console app:verify:ioc-source-presence --limit=200
```

Checks that extracted IOC values actually appear in their source message
body (or headers for header-type IOCs). Classifications: PRESENT,
VARIANT_MATCH, HEADER_ONLY, ABSENT. Threshold: ABSENT > 5% indicates
a systematic extraction issue.

### Cluster Anchor Quality

```bash
make verify-clusters
# or directly:
php bin/console app:verify:cluster-quality --min-conversations=5
```

Assesses whether threat actor clusters are based on specific, high-value
IOCs (long IBANs, full phone numbers, crypto wallets) rather than
generic short patterns. Threshold: > 30% generic anchors suggests
false merges.

### Classification Spot-Check

```bash
make verify-classification
# or directly:
php bin/console app:verify:classification --sample=30
```

Generates a report with sampled conversations showing the assigned scam
type and a truncated first message, for manual review by an analyst.

---

## 2. LLM Quality Audit

### Running the Auditor

```bash
make audit-quality
# or with options:
php bin/console app:audit:conversation-quality --sample=50 --scam-type=PHISHING
php bin/console app:audit:conversation-quality --dry-run
```

### What It Does

Uses **gpt-4o** as an independent auditor (different model and prompt
than the enrichment pipeline, which uses gpt-4o-mini) to cross-validate
5 dimensions of extracted data:

| Dimension | What It Checks |
|-----------|---------------|
| Classification | Is the assigned scam type correct? |
| IOC Completeness | Are there IOCs visible in the message that were not extracted? |
| Urgency Score | Is the urgency assessment consistent with the message tone? |
| Semantic Roles | Are IOC roles (payment destination, contact channel, etc.) correct? |
| Risk Score | Is the conversation risk score proportionate to the threat? |

### How It Works

For each sampled conversation, the auditor:

1. Reads the raw scammer message (first 500 characters)
2. Reads all extracted IOCs with their semantic roles and urgency scores
3. Sends everything to gpt-4o with a **contradictory prompt** designed
   to find errors (the system message instructs the LLM to be "critical
   and objective" and to "explain WHY with specific evidence")
4. Parses the structured JSON response with per-dimension verdicts
5. Aggregates results into a markdown report with agreement rates

### Why a Different Model

Using the same model (gpt-4o-mini) to judge its own output creates
**circular validation** -- the LLM is unlikely to disagree with its own
reasoning patterns. The auditor uses:

- **gpt-4o** (not gpt-4o-mini) -- a different model with different
  failure modes
- A **contradictory prompt** -- explicitly instructs the model to find
  errors and provide evidence for disagreements
- **Low temperature (0.2)** -- reduces randomness for consistent auditing

This is not ground truth, but provides meaningful independent
cross-validation.

### How to Interpret Results

The report shows agreement rate per dimension:

| Agreement Rate | Interpretation |
|---------------|----------------|
| >= 90% | Excellent -- pipeline outputs are consistent |
| 85-90% | Good -- review specific disagreements |
| 75-85% | Warning -- investigate systematic patterns |
| < 75% | Action required -- pipeline may have issues |

**Below 85% on any dimension**: investigate the disagreements in the
report. Look for patterns (e.g., all INVESTMENT conversations
misclassified, urgency consistently overestimated).

### Limitations

- Still LLM-based -- not ground truth. The auditor can be wrong.
- Cost: each conversation audit uses ~1000 tokens of gpt-4o
- Only audits the first inbound message (not the full thread)
- Agreement between two LLMs does not guarantee correctness

**Output**: `var/audit-results/llm-quality-audit.md`

---

## 3. Manual Deep Audit

### When to Do It

- Before any public presentation or publication
- After major pipeline changes (new extraction patterns, prompt updates)
- When LLM audit agreement drops below 85%
- Before submitting the thesis evaluation

### SQL Queries to Extract Data

**Sample conversations with rich data**:

```sql
SELECT c.conv_id, st.code AS scam_type, c.score_risk, c.status,
       COUNT(DISTINCT oi.obs_id) AS ioc_count,
       MIN(m.ts_received) AS first_message
FROM conversation c
LEFT JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
LEFT JOIN message m ON c.conv_id = m.conv_id AND m.direction = 'in'
LEFT JOIN observed_ioc oi ON m.msg_id = oi.msg_id
WHERE c.deleted_at IS NULL
GROUP BY c.conv_id, st.code, c.score_risk, c.status
HAVING COUNT(DISTINCT oi.obs_id) > 0
ORDER BY RANDOM()
LIMIT 15;
```

**Get messages and IOCs for a specific conversation**:

```sql
SELECT m.direction, LEFT(m.body_text, 300) AS body_excerpt,
       i.type, i.value_norm, ic.semantic_role, ic.urgency_score
FROM message m
LEFT JOIN observed_ioc oi ON m.msg_id = oi.msg_id
LEFT JOIN indicator i ON oi.indicator_id = i.indicator_id
LEFT JOIN ioc_context ic ON oi.obs_id = ic.obs_id
WHERE m.conv_id = :conv_id
ORDER BY m.ts_received;
```

### Per-Conversation Checklist

For each conversation in the sample:

- [ ] **Classification**: does the scam type match the message content?
- [ ] **IOC presence**: are all IOCs visible in the message extracted?
- [ ] **IOC types**: are types correct (email vs domain, URL vs domain)?
- [ ] **Semantic roles**: do roles match the IOC's function in the scam?
- [ ] **Urgency score**: is urgency consistent with the message tone?
- [ ] **Risk score**: is risk proportionate to IOC severity and scam type?
- [ ] **No false IOCs**: are there extracted IOCs that are not in the message?

### Documenting Findings

Record findings in a spreadsheet or markdown table:

```markdown
| conv_id | classification | iocs_ok | roles_ok | urgency_ok | risk_ok | notes |
|---------|---------------|---------|----------|------------|---------|-------|
| abc-123 | correct       | yes     | yes      | yes        | yes     |       |
| def-456 | wrong (PHISHING not INVOICE_FRAUD) | yes | no (email=PAYMENT) | high | yes | |
```

---

## 4. Remediation

When audit findings reveal systematic issues:

### Classification Wrong

- Review the classifier prompt in the ScamClassifier service
- Check if the scam type enum covers the observed pattern
- Add few-shot examples for the misclassified category
- Re-run classification on affected conversations

### IOCs Missed

- Check extraction regex patterns in IocExtractor
- Review LLM extraction prompt for the missed IOC types
- Verify that the IOC type is in the supported types list
- Check if defanging/encoding prevents extraction

### Urgency Inaccurate

- Review the enrichment prompt calibration in ContextualEnricher
- Check if the 3-message window provides enough context
- Adjust score range guidance in the prompt

### Risk Score Disproportionate

- Review the formula in IngestPostProcessor
- Check if the risk scorer weights match operational priorities
- Verify that IOC severity feeds into risk calculation correctly

---

## 5. Recommended Schedule

| Trigger | Action |
|---------|--------|
| After each batch of 50+ new conversations | `make audit-deep` |
| Before any presentation or publication | Manual deep audit on 15+ conversations |
| Monthly | `make audit-quality --sample=100` |
| After pipeline changes (prompts, extraction) | `make audit-deep` + manual spot-check |
| Before thesis evaluation | Full manual audit (30+ conversations) |

### Quick Reference

```bash
# Automated screening (fast, no LLM cost)
make verify-iocs
make verify-clusters
make verify-classification

# LLM quality audit (uses gpt-4o, ~$0.50 for 50 conversations)
make audit-quality

# Complete audit suite (screening + LLM)
make audit-deep
```

---

## 6. TTP extraction audit

The workflow above covers classification, IOCs and enrichment. Scammer-side TTP
extraction has its own method, because measuring it needs two independent scorers
rather than a second LLM:

- [TTP extraction quality](standards/ttp-extraction-quality.md) — the frozen method,
  the provenance a figure must carry, and the limits that travel with it.
- [Scoring codebook v1](standards/ttp-codebook-v1.md) — the rules a scorer applies,
  including the code pairs where verdicts most often diverge.

```bash
make ttp-audit-sample SEED=4242 LIMIT=100   # draw a reproducible sheet
make ttp-audit-score SHEET=<scored.csv>     # agreement, kappa, precision, per-code
```

**No measured figure exists yet.** Until one does, no metric applies to the TTP
module, and the notices saying so in the README and the roadmap stay as they are.
