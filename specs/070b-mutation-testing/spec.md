# Spec 070b — Mutation Testing with Infection

> **Parent**: `specs/070-code-quality-hardening/`
> **Effort**: 1.5 days
> **Branch**: `070b-mutation-testing`

## 1. Context

90%+ code coverage means "the code is executed by tests". Mutation testing asks: "if I break a line, does a test fail?" This is the true measure of test quality.

Infection PHP mutates the source code (flips conditions, removes lines, changes operators) and runs tests against each mutant. If tests still pass → the test suite has a gap.

Current state:
- 3247 backend tests, 90% coverage
- 181 unit tests (Infection works best on unit tests)
- 294 final classes (reduces mutation surface → faster execution)
- pcov available as coverage driver
- Estimated run time: 10-15 minutes

## 2. Approach

### Phase 1: Install + baseline
1. Install Infection
2. Configure `infection.json5`
3. Run on unit tests only → get baseline MSI (Mutation Score Indicator)
4. Expected baseline: 68-75% MSI

### Phase 2: Kill surviving mutants
Focus on mutants in critical code:
- `src/Application/LLM/` (PolicyGuard, RetryCoordinator, IOCLikelihoodScorer)
- `src/Application/Communication/` (IocHandler, RiskScorer, IocConfidenceCalculator)
- `src/Domain/` (entities, value objects)

For each surviving mutant:
- Is it a real gap? → Add/strengthen test assertion
- Is it equivalent mutant? → Add `@infection-ignore-all` with justification

### Phase 3: CI integration
Add Infection to CI pipeline:
- Run after unit tests
- Fail CI if MSI drops below threshold (e.g., 75%)
- Report in text + badge

## 3. Configuration

```json5
// infection.json5
{
    "$schema": "vendor/infection/infection/resources/schema.json",
    "source": {
        "directories": ["src"],
        "excludes": [
            "DataFixtures",
            "DependencyInjection",
            "Kernel.php",
            "UI/Console"
        ]
    },
    "logs": {
        "text": "var/infection.log",
        "summary": "var/infection-summary.log"
    },
    "tmpDir": "var/infection",
    "phpUnit": {
        "configDir": ".",
        "customPath": "vendor/bin/phpunit"
    },
    "testFramework": "phpunit",
    "testFrameworkOptions": "--testsuite=unit",
    "mutators": {
        "@default": true
    },
    "minMsi": 75,
    "minCoveredMsi": 85
}
```

## 4. Acceptance criteria

- [ ] Infection installed and configured
- [ ] Baseline MSI measured and documented
- [ ] MSI ≥ 75% (or documented plan to reach it)
- [ ] Covered MSI ≥ 85%
- [ ] Critical services (PolicyGuard, RiskScorer, IocConfidence) at ≥ 85% MSI
- [ ] CI step added (optional, can be manual initially)
- [ ] `make test` + `make endToEndTest` still green (Infection doesn't change source)

## 5. Tasks

- [ ] **T1** Install: `composer require --dev infection/infection`
- [ ] **T2** Create `infection.json5` config
- [ ] **T3** Run baseline: `vendor/bin/infection --threads=4 --only-covered --show-mutations`
- [ ] **T4** Document baseline MSI score
- [ ] **T5** Kill mutants batch 1: Domain layer (entities, VOs)
- [ ] **T6** Kill mutants batch 2: Application/LLM/ (PolicyGuard, RetryCoordinator)
- [ ] **T7** Kill mutants batch 3: Application/Communication/ (IocHandler, RiskScorer)
- [ ] **T8** Add `@infection-ignore-all` for equivalent mutants with justification
- [ ] **T9** Add Infection to Makefile: `make mutation`
- [ ] **T10** Optional: add to CI workflow
- [ ] **T11** Final MSI measurement + documentation

### Integration process per commit:
```
1. Strengthen test assertions to kill mutants
2. phpstan analyse src/ --level {current}
3. make test
4. vendor/bin/infection --threads=4 --only-covered --filter=src/path/changed
5. git commit
```
