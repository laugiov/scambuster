# Evaluation Methodology

## Overview

ScamBuster employs **rigorous, reproducible evaluation methods** to measure and validate its effectiveness. This document describes our metrics, validation approach, and statistical methods.

---

## Key Performance Indicators (KPIs)

### Primary Metrics

| Metric | Definition | Target | Status |
|--------|------------|--------|--------|
| **Engagement Duration** | Time from first response to conversation end | >1 hour median | 0.3h median, 48.7h max |
| **Unique IOCs per Conversation** | Deduplicated indicators extracted | >5 per conversation | 5.34 achieved (deduplicated) |
| **High-Value IOC Rate** | % of IOCs that are actionable (IBAN, phone, crypto) | >10% | IBANs, phones, crypto captured |
| **Conversation Completion Rate** | % of conversations reaching natural end | >30% | Measuring |
| **System Uptime** | Continuous operation without incidents | >99% | 100% (60 days) |

### Secondary Metrics

| Metric | Definition | Purpose |
|--------|------------|---------|
| **LLM Approval Rate** | % of generated responses passing validation | Quality assurance |
| **Classification Accuracy** | Correct scam type identification | Pipeline effectiveness |
| **Cost per IOC** | Total operational cost / IOCs extracted | Economic viability |
| **Response Latency** | Time to generate and send response | User experience (scammer) |
| **Abandonment Rate** | % of conversations ended by scammer after N turns | Engagement quality |

---

## Observation Windows

Metrics in this documentation come from **controlled live deployment** (December 2025 - February 2026):

| Window | Duration | Purpose | Key Metrics |
|--------|----------|---------|-------------|
| **Production run** | 60 days | Stability, quality metrics, uptime | 5.34 unique IOCs/conv, 100% precision, 0 incidents |
| **Controlled validation** | Ongoing | Precision analysis, campaign attribution | Detailed cost/value analysis |

This separation ensures appropriate context for each metric type.

---

## Validation Completed (February 2026)

### Production Validation (60 Days)

**Dataset**: Real conversations with active scammers over 60 days

| Metric | Value | Notes |
|--------|-------|-------|
| **Unique IOCs per conversation** | 5.34 | Deduplicated |
| **IOC Precision** | 100% (N=107) | Audited sample |
| **Persona variance** | 5.5x | Best vs worst per scam type |
| **Scammer response rate** | 54% | In line with manual scambaiting literature |
| **Cost per IOC** | EUR 0.0002 | LLM API only |
| **System uptime** | 100% | 60 days, 0 incidents |
| **LLM approval rate** | 95% | With retry mechanism |

**Key Observations**:
- **5.5× variance** in persona performance by scam type
- Best conversation: **48.7 hours** (persona: `elderly_person`)
- High-value IOCs include IBANs, phone numbers, cryptocurrency wallets
- Campaign attribution possible through IOC clustering

### Experimental Validation (2,221 Conversations)

**Dataset**: Synthetic conversations in isolated preprod environment

| Metric | Value | Notes |
|--------|-------|-------|
| **Total conversations** | 2,221 | 20 cycles × ~110 each |
| **Conversations with reward** | 1,535 | 69.1% of total |
| **Mean reward** | 0.4859 | Scale 0-1 |
| **Reward std deviation** | 0.0827 | Consistent |
| **Coefficient of variation** | 0.1703 | Low variance = convergence |

### Statistical Validation: IOC Impact

**Hypothesis**: Conversations with IOCs yield higher rewards

**Method**: Welch's t-test (unequal variances)

| Group | N | Mean Reward | Std Dev |
|-------|---|-------------|---------|
| **WITH_IOCS** | 100 | 0.5194 | 0.0642 |
| **WITHOUT_IOCS** | 100 | 0.4988 | 0.0512 |

**Results**:

| Statistic | Value | Interpretation |
|-----------|-------|----------------|
| **t-statistic / p-value** | Available in internal statistical report | Significant difference confirmed |
| **Cohen's d** | 0.37 (small-medium) | Practically meaningful effect |
| **Mean difference** | +4.17% | IOC group advantage |

**Conclusion**: IOCs significantly increase conversation rewards. Full statistical report available upon request (NDA).

---

## A/B Testing Protocol (Completed January 2026)

### A/B Testing Protocol

**Objective**: Validate adaptive algorithm superiority over random selection

#### Experimental Design

| Group | Strategy | Size Target |
|-------|----------|-------------|
| **A (Control)** | Random persona selection | 200+ conversations |
| **B (Test)** | Adaptive bandit (epsilon-greedy + UCB1) | 200+ conversations |

#### Hypotheses

| ID | Hypothesis | Test | Success Criterion |
|----|------------|------|-------------------|
| **H1** | Adaptive increases engagement duration | Mann-Whitney U | +100% median, p < 0.05 |
| **H2** | Adaptive increases IOCs per conversation | t-test | +30%, p < 0.05 |
| **H3** | Adaptive reduces early abandonment | χ² | -20% at 2nd turn, p < 0.05 |
| **H4** | Bandit converges to optimal personas | Visual + CV | CV < 0.15 within 100 sessions |

#### Statistical Methods

| Test | When Used | Assumptions |
|------|-----------|-------------|
| **Welch's t-test** | Continuous data, unequal variances | Approximate normality |
| **Mann-Whitney U** | Non-normal distributions (duration) | Independence |
| **Chi-squared (χ²)** | Categorical data (completion) | Expected counts > 5 |
| **Cohen's d** | Effect size for continuous | None |

#### Power Analysis

Target sample size based on:
- Effect size: d = 0.4 (medium)
- α = 0.05
- Power = 0.80
- **Required N**: ~100 per group (minimum)
- **Target N**: 200+ per group (robustness)

---

## Adaptive Algorithm Evaluation

### ε-Greedy (V1, December 2025)

**Parameters**:
- ε = 0.20 (20% exploration)
- Exploitation: Best-performing persona for scam type
- Exploration: Random persona selection

**Validation Results**:

| Metric | Value | Interpretation |
|--------|-------|----------------|
| **Convergence CV** | 0.1703 | Good convergence |
| **Sessions to stability** | ~100-200 | Acceptable |
| **Exploration waste** | ~20% | Fixed, not adaptive |

**Identified Limitations**:
1. Fixed ε regardless of confidence level
2. "Blind" exploration (may test known-bad personas)
3. No uncertainty quantification

### Thompson Sampling (Planned -- v2 Roadmap)

Thompson Sampling is planned as a v2 upgrade. It is **not implemented** in the current codebase. The current production algorithm (epsilon-greedy + UCB1) already demonstrates strong convergence properties (9/12 scam types converged in <100 sessions).

**Expected Advantages** (if implemented):

| Aspect | ε-Greedy + UCB1 (current) | Thompson Sampling (planned) |
|--------|---------------------------|----------------------------|
| **Exploration** | UCB1 bonus + 20% random | Probability-weighted by uncertainty |
| **Hyperparameters** | ε, C, convergence threshold | None (auto-adaptive) |
| **Uncertainty** | UCB1 approximation | Explicit in Beta distribution |
| **Bad arms** | UCB1 reduces over time | Natural elimination |
| **Convergence** | Good (60% threshold detection) | Expected: faster |

---

## Reward Function

### Formula

```
reward = 0.40 × duration_score
       + 0.25 × ioc_total_score
       + 0.25 × ioc_sensitive_score
       + 0.10 × completion_score
```

### Component Definitions

| Component | Calculation | Range | Rationale |
|-----------|-------------|-------|-----------|
| **duration_score** | min(duration_sec / 172800, 1.0) | 0-1 | Up to 48h normalized |
| **ioc_total_score** | min(total_iocs / 20, 1.0) | 0-1 | Up to 20 IOCs |
| **ioc_sensitive_score** | min(sensitive_iocs / 5, 1.0) | 0-1 | Up to 5 high-value |
| **completion_score** | 1.0 if completed, 0.0 if abandoned | 0/1 | Binary completion |

### Weight Justification

| Weight | Component | Rationale |
|--------|-----------|-----------|
| **40%** | Duration | Primary goal: maximize scammer time |
| **25%** | Total IOCs | Volume matters for intelligence |
| **25%** | Sensitive IOCs | Quality matters for action |
| **10%** | Completion | Bonus for natural conversation end |

### Validation of Reward Function

| Test | Result |
|------|--------|
| **Distribution shape** | Approximately normal (Shapiro-Wilk p > 0.05) |
| **Correlation with manual assessment** | High (r > 0.8) |
| **Sensitivity to improvements** | Detects persona differences |

> **Note**: These are preliminary internal validation results. Full statistical protocol and detailed analysis will be published with the academic paper.

---

## Data Quality Assurance

### IOC Extraction Accuracy

**Canonical definition of precision**:

> **Precision = TP / (TP + FP)** where:
> - **TP (True Positives)**: Extracted items that are genuine IOCs
> - **FP (False Positives)**: Extracted items that are not genuine IOCs
>
> "100% precision" means: **no false positives were observed in the audited sample**.

| Metric | Value | Method | Sample |
|--------|-------|--------|--------|
| **Precision** | 100% | Manual review | N=107 messages |
| **Recall** | >95% (estimate) | Comparison with manual extraction | N=100 samples |
| **Type accuracy** | 100% | Classification verification | All audited IOCs |

> **Note**: Precision and recall are reported separately. Precision is based on audited sample; recall is an estimate based on comparison with manual extraction on a subset.

### Conversation Integrity

| Check | Frequency | Method |
|-------|-----------|--------|
| **Deduplication** | Every ingestion | Message-ID / composite hash |
| **Threading** | Every message | Reply-to header validation |
| **Timestamp** | Every message | Server + client time sync |

### Database Constraints

| Constraint | Purpose |
|------------|---------|
| **Foreign keys** | Referential integrity |
| **Check constraints** | Valid ranges (reward 0-1, etc.) |
| **Unique indexes** | Prevent duplicates |
| **RLS policies** | Multi-tenant isolation |

---

## Reproducibility

### Code and Configuration

| Artifact | Availability |
|----------|--------------|
| **Source code** | Private (available under NDA) |
| **Configuration** | Documented in specs |
| **Dependencies** | Pinned versions (composer.lock) |
| **LLM version** | Pinned (gpt-4o-mini-2024-07-18) |

### Dataset

| Dataset | Size | Availability | Format |
|---------|------|--------------|--------|
| **Production conversations** | +1K | On request (NDA) | JSON |
| **Validation synthetic** | 2,221 | On request (NDA) | JSON |
| **Anonymized corpus** | 100+ published, expansion planned | On request (NDA) | JSON + CSV |

### Statistical Analysis

| Component | Tool | Version |
|-----------|------|---------|
| **t-tests** | Python scipy | 1.11+ |
| **Effect sizes** | Manual + library | N/A |
| **Visualizations** | Matplotlib/Grafana | Latest |

---

## Limitations and Caveats

### Known Limitations

| Limitation | Impact | Mitigation |
|------------|--------|------------|
| **Sample size (production)** | 60-day deployment, growing | Synthetic validation + ongoing scale |
| **Selection bias** | Public scam sites may not represent all scams | Diversify sources |
| **LLM dependency** | Proprietary model may change | Version pinning, logging |
| **Synthetic ≠ real** | Preprod validation lacks real scammer behavior | Validate with production data |

### External Validity

| Dimension | Concern | Approach |
|-----------|---------|----------|
| **Scam types** | Focused on email | Documented scope limitation |
| **Languages** | English primarily | Note in results |
| **Time period** | December 2025 - February 2026 | Temporal caveat in publication |

---

## Automated Benchmark Suite (v1.8.0)

### Overview

ScamBuster v1.8.0 introduces an **automated quality benchmark suite** consisting of 3 Symfony console commands that measure reply quality, persona effectiveness, and bandit convergence through reproducible, machine-readable metrics.

### Commands

| Command | Purpose | Output |
|---------|---------|--------|
| `app:evaluate:generate-corpus` | Generate 500+ LLM replies with full metadata | JSON corpus + Markdown summary |
| `app:evaluate:reply-quality` | Compute 9 quality metrics across 6 dimensions | JSON + Markdown report with pass/fail |
| `app:evaluate:bandit-analysis` | Analyze epsilon-greedy convergence per scam type | JSON + Markdown report |

### Quality Metrics (9 metrics, 6 dimensions)

| Metric | Dimension | Target | Method |
|--------|-----------|--------|--------|
| Non-repetitiveness | Diversity | Jaccard < 0.30 | Character trigram similarity between consecutive replies |
| Opening diversity | Diversity | Ratio > 0.80 | Unique first sentences / total replies |
| Persona distinctness | Persona | Variance > 0.15 | TF-IDF cosine similarity across persona pairs |
| First-attempt approval | Naturalness | Rate > 60% | % of replies approved on first PolicyGuard pass |
| Average naturalness | Naturalness | Score > 3.0/5 | Multi-criteria validator naturalness dimension |
| Language compliance | Language | Rate > 95% | Detected language vs reply language match |
| IOC elicitation | Intelligence | Score > 2.5/5 | Multi-criteria validator ti_value dimension |
| Security pass rate | Safety | Rate > 99% | Explicit security gate in validator |
| Fallback rate | Safety | Rate < 10% | % of replies using fallback instead of LLM |

### Corpus Generation

Each corpus entry captures:
- **Reply text** with word count and detected language
- **Persona and scam type** context
- **Multi-criteria validation scores**: naturalness (1-5), persona_fit (1-5), ti_value (1-5), security_pass (bool)
- **Pipeline metadata**: attempt count, fallback flag, PolicyGuard flags, cost estimate
- **Conversation context**: message count, conversation ID

### Bandit Convergence Analysis

Per scam type:
- Dominant persona % and convergence status (>60% with >=10 sessions)
- Reward distribution (mean, stddev, quartiles) per persona
- Cumulative regret vs oracle and random baseline
- Cold start analysis (first 3 sessions exploration ratio)

### Reproducibility

```bash
# Full pipeline (Makefile targets)
make evaluate-corpus COUNT=500    # ~$1.50, ~15 minutes
make evaluate-quality             # Uses latest corpus
make evaluate-bandit              # Reads database directly
make evaluate-all                 # Runs all 3 in sequence

# Dry-run (no LLM cost)
make evaluate-corpus COUNT=500 DRY_RUN=1
```

### Test Coverage

- 1,306+ automated tests (unit + integration + E2E)
- 81.75% overall code coverage (Codecov)
- 83 evaluation-specific tests covering all metric calculators, analyzers, report writers, and commands
- PHPStan level 6 with zero errors on full codebase

### System Integrity Audit (v1.8.0+)

99 features inventoried across 11 domains. Verification-by-proof audit results:

| Category | PASS | PARTIAL | DEAD | Total |
|----------|------|---------|------|-------|
| After audit fixes | 20 | 3 | 0 | 25 |

All 4 previously DEAD features (IOC confidence, message vectors, URL analysis, actor profiles) have been activated. All critical PARTIAL features (prompt injection, audit trail) have been fixed.

---

## Reporting

### Metrics Dashboard (Planned)

**Grafana dashboards** will display:

1. **Operational**: Volume, latency, errors, costs
2. **Adaptive**: Convergence, persona performance, strategy distribution
3. **Intelligence**: IOC types, campaigns, trends

### Publication Plan

| Deliverable | Target | Content |
|-------------|--------|---------|
| **Internal report** | Monthly | All metrics + analysis |
| **Academic paper** | Q1 2026 (in progress) | Methodology + key results |
| **Dataset** | Q1 2026 (in progress) | Anonymized, CC BY-NC-SA |

---

## Next Steps

- [Roadmap](06_roadmap.md): Development timeline
- [FAQ](07_faq.md): Common questions
- [Back to Main](../README.md)
