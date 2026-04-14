# Spec 070e — CI Security Hardening

> **Parent**: `specs/070-code-quality-hardening/`
> **Effort**: 30 minutes
> **Branch**: `070e-ci-security-hardening`

## 1. Context

Audit found 3 critical CI security issues:
- 6 GitHub Actions using version tags instead of SHA digests (supply chain risk)
- `trivy-action@master` uses a branch reference (worst case)
- Trivy exit-code 0 means container vulnerabilities never fail the build
- 7 instances of `|| true` masking real test/build failures

## 2. Changes

### 2.1 Pin all GitHub Actions to SHA digests

| Action | Current | Target SHA |
|--------|---------|------------|
| `actions/checkout` | `@v4` | Pin to latest v4 SHA |
| `actions/setup-node` | `@v4` | Pin to latest v4 SHA |
| `actions/upload-artifact` | `@v4` | Pin to latest v4 SHA |
| `codecov/codecov-action` | `@v4` | Pin to latest v4 SHA |
| `gitleaks/gitleaks-action` | `@v2` | Pin to latest v2 SHA |
| `aquasecurity/trivy-action` | `@master` | Pin to latest release SHA |

### 2.2 Make Trivy blocking

Change `exit-code: '0'` to `exit-code: '1'` for CRITICAL and HIGH severity.

### 2.3 Remove error-masking `|| true`

Review each `|| true` instance:
- Coverage XML extraction: replace with conditional check
- CompilerPass test: make failures visible
- E2E test execution: remove `|| true` (failures should fail CI)
- `chown` commands: keep `|| true` (legitimate permission fallback)

## 3. Acceptance criteria

- [ ] All 6 GitHub Actions pinned to SHA digests
- [ ] Trivy fails CI on CRITICAL/HIGH vulnerabilities
- [ ] `|| true` removed from test execution steps
- [ ] CI passes on push

## 4. Tasks

- [ ] **T1** Look up current SHA for each Action version tag
- [ ] **T2** Replace version tags with SHA in ci.yml
- [ ] **T3** Change Trivy exit-code to 1
- [ ] **T4** Audit and remove/keep each `|| true`
- [ ] **T5** Push and verify CI passes
