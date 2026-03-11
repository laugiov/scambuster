# ScamBuster - Project Roadmap

**Last updated**: March 2026

---

## Vision

ScamBuster is an automated analysis and response platform for scam attempts, combining:
- Automatic scam detection (classification, IOC extraction)
- Contextual LLM-generated replies (GPT-4o-mini)
- Adaptive scambaiting with machine learning (multi-armed bandits)
- IOC centralization and enrichment

---

## Phase 1: Adaptive Scambaiting (COMPLETED - November 2025)

**Goal**: Replace random persona selection with an epsilon-greedy algorithm that automatically optimizes selection based on performance.

### Deliverables
- Database: 2 migrations (conversation metrics columns + persona_performance_stats table)
- Domain: 3 Value Objects + 1 Domain Event
- Application: 3 services (PersonaOptimizer, ConversationMetricsCollector, ConversationClosureService)
- Infrastructure: Doctrine entity, repository, event listener
- UI: 5 REST endpoints for stats, closure, and selection
- n8n: Daily CRON workflow for conversation closure
- Tests: 40+ tests, 100+ assertions, ~95% coverage

### Epsilon-Greedy Algorithm
- **Epsilon**: 0.20 (20% exploration, 80% exploitation)
- **Cold start threshold**: 3 sessions minimum before exploitation
- **Context**: 1 bandit per scam_type (13 independent bandits)
- **Reward formula**: `0.40*duration + 0.25*iocs_total + 0.25*iocs_sensitive + 0.10*completion`

---

## Phase 2: Algorithm Improvements (IN PROGRESS)

**Goal**: Improve selection with advanced multi-armed bandit techniques.

### 2.1 Thompson Sampling
- [ ] Implement Thompson Sampling (Bayesian approach)
- [ ] Compare epsilon-greedy vs Thompson Sampling performance
- [ ] A/B testing validation
- [ ] Statistical validation

### 2.2 LinUCB (Contextual Bandits)
- [ ] Add contextual features: language, time of day, country, day of week
- [ ] Implement LinUCB (Linear Upper Confidence Bound)
- [ ] Automatic feature collection via `ConversationContext`

### 2.3 Epsilon Decay
- [ ] Progressive epsilon reduction over time (0.20 to 0.05)
- [ ] Decay strategies: exponential, linear, step
- [ ] Per-scam_type configuration

---

## Phase 3: Dashboard & Monitoring (Q2 2026)

**Goal**: Real-time performance visualization and advanced monitoring.

- [ ] Grafana dashboard: reward by scam_type, exploitation/exploration ratio, top personas
- [ ] Prometheus metrics export
- [ ] Structured logging integration

---

## Phase 4: Optimizations & Scalability (Q3 2026)

**Goal**: Improve performance and handle scale.

- [ ] Async processing via Symfony Messenger (reward computation)
- [ ] Redis caching for persona_performance_stats (TTL 1h)
- [ ] Database optimization for high-volume stats

---

## Phase 5: Multi-Objective AI (Q4 2026)

**Goal**: Optimize multiple metrics simultaneously (reward, LLM cost, response time).

- [ ] Multi-objective reward: `reward_total = w1*engagement + w2*iocs - w3*cost - w4*time`
- [ ] Pareto front computation and persona selection
- [ ] Auto-tuning hyperparameters (Bayesian optimization)

---

## Phase 6: Additional Features (2027)

- [ ] Multi-language support (FR, EN, ES)
- [ ] Temporal bandits (time-of-day, day-of-week)
- [ ] Adversarial robustness (detect scammer strategy adaptation)
- [ ] Transfer learning across scam types
- [ ] Explainability (SHAP values for persona selection)
- [ ] Active learning (human feedback on ambiguous conversations)
- [ ] Full reinforcement learning (PPO/A3C)

---

## Success Metrics

| Metric | Baseline (Pre-Phase 1) | Phase 1 Target | Phase 5 Target |
|--------|------------------------|----------------|----------------|
| Average reward | 0.50 (random) | 0.60 | 0.75 |
| Average conversation duration | 4h | 8h | 16h |
| IOCs captured/conv | 5 | 8 | 15 |
| Exploitation rate | 0% | 70% | 85% |
| Cold start time | N/A | 2 weeks | 1 week |
| LLM cost/conv | $0.20 | $0.20 | $0.15 |

---

## Research & Publications

### Potential Papers

1. **"Adaptive Scambaiting: A Contextual Multi-Armed Bandit Approach"** (2026)
   - Target: ACM CCS, USENIX Security
   - Comparison: epsilon-greedy vs Thompson Sampling vs LinUCB

2. **"Multi-Objective Optimization for LLM-Based Deception Systems"** (2027)
   - Target: IEEE TDSC
   - Pareto front + multi-objective reward

3. **"Long-Term Engagement Strategies in Automated Scambaiting"** (2027)
   - Target: ACSAC
   - Temporal analysis + transfer learning

---

**Roadmap version**: 1.1
**Last updated**: March 2026
