# Data Validation Guide

ScamBuster provides built-in validation commands to verify the quality
and accuracy of extracted intelligence data. These commands generate
audit reports that help operators assess data reliability.

## Why Validate?

ScamBuster extracts IOCs, classifies scam types, and clusters threat
actors using a combination of deterministic algorithms, heuristic rules,
and LLM-based analysis. Validation ensures:

- Extracted IOCs actually appear in their source messages
- Large clusters represent genuine threat actor groups, not artifacts
- Scam type classifications align with message content
- Metrics displayed in the dashboard are traceable and accurate

## Validation Commands

### IOC Source Presence Verification

Verifies that extracted IOCs are present in their source messages.

```bash
make verify-iocs
# or directly:
php bin/console app:verify:ioc-source-presence --limit=200
```

**What it checks**: For each IOC, searches the source message body for
the extracted value (exact match or common variants like defanged URLs,
spaced IBANs).

**Classifications**:
- PRESENT: exact value found in message body
- VARIANT_MATCH: normalized variant found (e.g., IBAN with spaces)
- HEADER_ONLY: IOC is from email headers (checked in headers JSON)
- ABSENT: value not found -- potential extraction error

**Threshold**: ABSENT rate > 5% indicates a systematic extraction issue.

**Options**:
- `--limit=N`: number of IOCs to check (default: 200)
- `--dry-run`: count only, no report generated

**Output**: `var/audit-results/ioc-source-verification.md`

### Cluster Anchor Quality

Assesses whether threat actor clusters are based on specific, high-value
IOCs rather than generic patterns.

```bash
make verify-clusters
# or directly:
php bin/console app:verify:cluster-quality --min-conversations=5
```

**What it checks**: For each cluster, classifies anchor IOCs as:
- HIGH_VALUE: specific IBAN (>=15 chars), full phone number (>=10 digits),
  specific crypto wallet (>=25 chars)
- GENERIC: short phone prefix, common domain pattern, generic email

**Threshold**: Clusters with > 30% generic anchors may be artifacts
(conversations grouped by coincidence, not by shared threat actor).

**Options**:
- `--min-conversations=N`: minimum cluster size to analyze (default: 5)

**Output**: `var/audit-results/cluster-quality-report.md`

### Scam Classification Spot-Check

Generates a report for manual review of scam type classification accuracy.

```bash
make verify-classification
# or directly:
php bin/console app:verify:classification --sample=30
```

**What it generates**: A markdown report with sampled conversations showing:
- Conversation ID and status
- Assigned scam type
- First scammer message (anonymized, truncated to 200 chars)
- Blank assessment field for the reviewer

**How to use**: Open the report, read each conversation excerpt, and judge
whether the assigned scam type is correct. Mark each as Correct,
Wrong, or Ambiguous. Calculate the agreement rate.

**Threshold**: Agreement rate < 85% suggests the classifier prompt needs
refinement.

**Options**:
- `--sample=N`: number of conversations to sample (default: 30)

**Output**: `var/audit-results/classification-spot-check.md`

## Metric Provenance

All metrics displayed in the ScamBuster dashboard are documented in
[metrics catalog](22_metrics_catalog.md) with their exact formula, data source,
and provenance classification:

- **Verified**: deterministic formula, database count, or external API result
- **LLM-Derived**: computed by an LLM (gpt-4o-mini) -- documented limitations
- **Heuristic**: rule-based approximation with documented assumptions

## Running All Validations

```bash
make verify-iocs
make verify-clusters
make verify-classification
```

Reports are generated in `var/audit-results/` (gitignored).

## Interpreting Results

| Check | Healthy | Warning | Action Required |
|-------|---------|---------|-----------------|
| IOC source presence | ABSENT < 2% | ABSENT 2-5% | ABSENT > 5%: review extraction pipeline |
| Cluster quality | 100% HIGH_VALUE | <30% GENERIC | >30% GENERIC: tighten clustering thresholds |
| Classification | >90% agreement | 85-90% | <85%: refine classifier prompt |
