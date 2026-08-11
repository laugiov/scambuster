# Evaluation Methodology

## Overview

This document describes **how** ScamBuster is evaluated: the metric definitions, the reproducible benchmark suite, the reward function, and the statistical methods used. It defines the measurement approach rather than reporting measured results, so any deployment can reproduce the evaluation on its own data.

---

## Key Performance Indicators (KPIs)

### Primary Metrics

| Metric | Definition |
|--------|------------|
| **Engagement Duration** | Time from first response to conversation end |
| **Unique IOCs per Conversation** | Deduplicated indicators extracted |
| **High-Value IOC Rate** | % of IOCs that are actionable (IBAN, phone, crypto) |
| **Conversation Completion Rate** | % of conversations reaching natural end |

### Secondary Metrics

| Metric | Definition | Purpose |
|--------|------------|---------|
| **LLM Approval Rate** | % of generated responses passing validation | Quality assurance |
| **Classification Accuracy** | Correct scam type identification | Pipeline effectiveness |
| **Cost per IOC** | Total operational cost / IOCs extracted | Economic viability |
| **Response Latency** | Time to generate and send response | User experience (scammer) |
| **Abandonment Rate** | % of conversations ended by scammer after N turns | Engagement quality |

---

## A/B Testing Protocol

**Objective**: Validate adaptive algorithm superiority over random selection.

### Experimental Design

| Group | Strategy | Size Target |
|-------|----------|-------------|
| **A (Control)** | Random persona selection | 200+ conversations |
| **B (Test)** | Adaptive bandit (epsilon-greedy + UCB1) | 200+ conversations |

### Hypotheses

| ID | Hypothesis | Test |
|----|------------|------|
| **H1** | Adaptive increases engagement duration | Mann-Whitney U |
| **H2** | Adaptive increases IOCs per conversation | t-test |
| **H3** | Adaptive reduces early abandonment | χ² |
| **H4** | Bandit converges to optimal personas | Visual + CV |

### Statistical Methods

| Test | When Used | Assumptions |
|------|-----------|-------------|
| **Welch's t-test** | Continuous data, unequal variances | Approximate normality |
| **Mann-Whitney U** | Non-normal distributions (duration) | Independence |
| **Chi-squared (χ²)** | Categorical data (completion) | Expected counts > 5 |
| **Cohen's d** | Effect size for continuous | None |

### Power Analysis

Target sample size based on:
- Effect size: d = 0.4 (medium)
- α = 0.05
- Power = 0.80
- **Required N**: ~100 per group (minimum)
- **Target N**: 200+ per group (robustness)

---

## Adaptive Algorithm Evaluation

### ε-Greedy (V1)

**Parameters**:
- ε = 0.20 (20% exploration)
- Exploitation: Best-performing persona for scam type
- Exploration: Random persona selection
- Convergence detection: 60% dominance threshold with >=10 sessions
- UCB1 exploration bonus: `C * sqrt(ln(total_sessions) / persona_sessions)`, C = 0.5

**Convergence measurement**: computed per scam type by the `app:evaluate:bandit-analysis` command (see the Automated Benchmark Suite below), which reports the coefficient of variation and dominant-persona share directly from the database.

**Identified Limitations**:
1. Fixed ε regardless of confidence level
2. "Blind" exploration (may test known-bad personas)
3. No uncertainty quantification

### Thompson Sampling (Planned -- v2 Roadmap)

Thompson Sampling is planned as a v2 upgrade. It is **not implemented** in the current codebase. The current production algorithm is epsilon-greedy + UCB1, with convergence detection built in (60% dominance threshold).

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

The value the persona-selection bandit actually optimizes is a **hybrid reward**: an LLM judgement of
the conversation's real outcome, blended with a deterministic mechanical score. The mechanical formula
is only the smaller component — it is **not** the objective on its own.

### Hybrid formula (what is actually optimized)

```
reward = 0.70 × outcome_llm  +  0.30 × mechanical_reward
```

- **`outcome_llm`** — an LLM judge (`RewardJudge`) scores the *actual* outcome of the finished
  conversation (did we obtain a payment / cash-out channel, fresh infrastructure, attribution?) on a
  `0–1` scale. The weight is configurable via `scambuster.reward.llm_weight` (**default 0.7**); see
  [docs/25](25_prompt_customization.md) and the "Reward Signal (Hybrid)" section of
  [docs/03](03_high_level_architecture.md).
- **Fault-tolerant** — if the LLM judgement is unavailable, the reward falls back to the mechanical
  score alone (no crash, no zero). Source of truth: `RewardJudge.php`.
- **Honest caveat** — `outcome_llm` is an LLM self-assessment with **no human ground truth**, produced
  by the same model family that generates the replies. Treat it as a heuristic signal, not a measured
  one. The mechanical 30% is the deterministic, auditable anchor.

### Mechanical reward (the 0.30 component)

```
mechanical_reward = 0.40 × duration_score
                  + 0.25 × ioc_total_score
                  + 0.25 × ioc_sensitive_score
                  + 0.10 × completion_score
```

| Component | Calculation | Range | Rationale |
|-----------|-------------|-------|-----------|
| **duration_score** | min(duration_sec / 86400, 1.0) | 0-1 | Up to 24h normalized |
| **ioc_total_score** | min(total_iocs / 50, 1.0) | 0-1 | Up to 50 IOCs |
| **ioc_sensitive_score** | min(sensitive_iocs / 10, 1.0) | 0-1 | Up to 10 high-value |
| **completion_score** | 1.0 if completed, 0.0 if abandoned | 0/1 | Binary completion |

Constants are the source-of-truth values in `ConversationMetrics.php`.

### Weight Justification (mechanical component)

| Weight | Component | Rationale |
|--------|-----------|-----------|
| **40%** | Duration | Primary goal: maximize scammer time |
| **25%** | Total IOCs | Volume matters for intelligence |
| **25%** | Sensitive IOCs | Quality matters for action |
| **10%** | Completion | Bonus for natural conversation end |

---

## Data Quality Assurance

### IOC Extraction Accuracy

**Canonical definition of precision**:

> **Precision = TP / (TP + FP)** where:
> - **TP (True Positives)**: Extracted items that are genuine IOCs
> - **FP (False Positives)**: Extracted items that are not genuine IOCs

**How it is measured**: precision and recall are evaluated separately on an audited sample. Precision is computed by manually reviewing extracted items against ground truth; recall is estimated by comparing extraction against manual extraction on a subset. Type accuracy is verified against classification labels. These evaluations are reproducible on any deployment's own audited sample.

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
| **Source code** | Open source (MIT License) on GitHub |
| **Configuration** | Documented in the runbooks and `.env.dist` |
| **Dependencies** | Pinned versions (composer.lock) |
| **LLM version** | Configurable per environment (default: GPT-4o for generation, GPT-4o-mini for validation) |

### Dataset

| Dataset | Availability | Format |
|---------|--------------|--------|
| **Production conversations** | On request (NDA) | JSON |
| **Validation synthetic** | On request (NDA) | JSON |
| **Anonymized corpus** | Planned release (see Roadmap) | JSON + CSV |

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
| **Sample size (production)** | Limited deployment window, growing | Synthetic validation + ongoing scale |
| **Selection bias** | Public scam sites may not represent all scams | Diversify sources |
| **LLM dependency** | Proprietary model may change | Version pinning, logging |
| **Synthetic ≠ real** | Preprod validation lacks real scammer behavior | Validate with production data |

### External Validity

| Dimension | Concern | Approach |
|-----------|---------|----------|
| **Scam types** | Focused on email | Documented scope limitation |
| **Languages** | English primarily | Note in results |
| **Time period** | Limited observation window | Temporal caveat in publication |

---

## Automated Benchmark Suite

### Overview

ScamBuster includes an **automated quality benchmark suite** consisting of 3 Symfony console commands that measure reply quality, persona effectiveness, and bandit convergence through reproducible, machine-readable metrics.

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

- Comprehensive automated test suite (unit + integration + E2E)
- Overall code coverage tracked via Codecov
- Extensive evaluation-specific tests covering all metric calculators, analyzers, report writers, and commands
- PHPStan level 8 with zero errors on full codebase

### System Integrity Audit

Platform features are periodically verified against their implementation through an internal verification-by-proof audit to confirm that documented capabilities are actually wired into the running system.

---

## Reporting

### Metrics Dashboard

**Grafana dashboards** will display:

1. **Operational**: Volume, latency, errors, costs
2. **Adaptive**: Convergence, persona performance, strategy distribution
3. **Intelligence**: IOC types, campaigns, trends

### Publication Plan

| Deliverable | Target | Content |
|-------------|--------|---------|
| **Internal report** | Monthly | All metrics + analysis |
| **Academic paper** | In progress | Methodology + key results |
| **Dataset** | In progress | Anonymized, CC BY-NC-SA |

---

## Next Steps

- [Roadmap](06_roadmap.md): Development timeline
- [FAQ](07_faq.md): Common questions
- [Back to Main](../README.md)
