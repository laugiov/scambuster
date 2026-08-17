# Factory setup — state

The setup runs in phases, each restartable from a fresh session. **This file is
the memory**: chat history is not. Update it at the end of every phase.

Branch: `claude/scambuster-factory-setup-5eind5`.

**Current state**: the five setup phases ran on this branch with nothing pushed
and no PR opened, as the setup required. That phase is over. The maintainer then
pushed the branch and opened **PR #61** (`Pipeline: chore`), and work has
continued on the branch since: the `chore` pipeline type itself was added *after*
the PR was open, because PR #61 could not truthfully declare any of the three
original types.

So a session reading this file should expect an open PR, a branch that is pushed,
and commits made after the PR was created. Push freely; do not merge — the
factory never merges, and that rule outlives the setup.

## Phases

| Phase | What it delivers | Status |
|---|---|---|
| 0 | Discovery: `factory/DISCOVERY.md`, `factory/found-issues.md` | done |
| 1 | Spec Kit v0.16.4 installed and committed, constitution written, `factory/speckit-inventory.md` | done |
| 2 | `docs/factory/pipelines.md`, `.claude/commands/factory-{feature,bug,security}.md`, `factory/security-findings.md` | done |
| 3 | `factory/gates.yaml`, `.claude/agents/*`, objection format, iteration rules, `docs/factory/templates/gate-report.md`, `scripts/factory/adversarial-review.sh` | done |
| 4 | `.github/workflows/factory-gates.yml`, `.semgrep/`, patch-coverage + traceability scripts, PR template fields | done |
| 5 | `factory/benchmark/`, `docs/factory/README.md` | done |

## Forward references — documents that point at files not yet created

None left. Phase 4 created `.github/workflows/factory-gates.yml` and the
`Pipeline:` line in the PR template, which were the last two.

## Decisions taken (do not relitigate without saying so)

| # | Decision | Where it came from |
|---|---|---|
| 1 | `.claude/`, `.specify/`, `specs/` are versioned; only credential-bearing and per-developer files stay ignored | maintainer, phase 1 |
| 2 | Definition of done uses **PHPStan level 8 over `backend-symfony/src`** — what CI actually runs | maintainer, phase 1 |
| 3 | Constitution says password hashing **must resolve to** Argon2id; `algorithm: auto` stays as-is | maintainer, phase 1 |
| 4 | Factory scope is **backend + frontend**; `n8n/`, `infra/`, `scripts/` are out of pipeline scope but bound by the security rules | maintainer, phase 1 |
| 5 | Spec Kit pinned to `v0.16.4`; upgrading is a deliberate, separate change | phase 1 |
| 6 | Frontend auth files are **sensitive**: `api/client.ts`, `store/authStore.ts`, `components/layout/AuthGuard.tsx`, `pages/Login.tsx` | delegated to Claude, phase 2 review |
| 7 | `n8n/workflows/**` and `n8n/n8n-init.sh` are **sensitive** — out of pipeline scope, but a change there is an escalation trigger | delegated to Claude, phase 2 review |
| 8 | **No separate "session" path.** Covered by `security.yaml`, `framework.yaml` and `src/UI/Http/Auth/**`, since the API firewalls are stateless JWT | delegated to Claude, phase 2 review |
| 9 | Security scan tool is **Semgrep** (pinned 1.173.0, CI-only, no composer dependency), with repo-specific rules in `.semgrep/constitution.yml` that encode the constitution | maintainer, phase 4 |
| 10 | `factory-gates.yml` does **not** duplicate tests/PHPStan/coverage; it waits for `ci.yml`'s jobs on the same SHA instead | maintainer, phase 4 |
| 11 | Coverage gate is **patch coverage**, computed inside `ci.yml`'s existing test job; Codecov keeps the project-level base comparison | maintainer, phase 4 |
| 12 | A fourth pipeline type, **`chore`**, for process/docs/CI changes that touch no application code. Added after PR #61 — the factory's own setup PR could not declare any of the three existing types truthfully | maintainer, PR #61 review |

Reasoning for 6–8 is in `factory/DISCOVERY.md` §5 under "Resolved". The
sensitive-path list is now closed and becomes `escalation_triggers.sensitive_paths`
in `factory/gates.yaml` in Phase 3.

## PR #61 review fixes

Applied on the branch after the PR was opened, at the maintainer's request:

| # | Fix |
|---|---|
| 1 | The hardcoded DSN from `found-issues.md` issue 8 is registered as **`SEC-001`** in `factory/security-findings.md`, status `open`. Not fixed here — it goes through its own `/factory-security` run. |
| 2 | Decision 12 now names its authority (`maintainer, PR #61 review`) like every other row. |
| 3 | The branch-state paragraph at the top of this file was stale: it still claimed nothing was pushed and no PR was open. |
| 4 | PR body confirmed: `Pipeline: chore` is on its own line at the top, and all 13 checks were green on head SHA `befb6b0` (12 success, `Traceability` correctly skipped for a chore PR). Nothing to change. |
| 5 | `factory/benchmark/score.py` crashed with `UnicodeEncodeError` on a non-UTF-8 stream, and printed `?` for a defect with no `id`. Both fixed; verified in ASCII and UTF-8. |

## Benchmark artifacts live outside the repository — including the clean spec

**Read this before starting a benchmark run. It is not obvious and it cost run 001.**

`factory/benchmark/README.md` says the *ground truth* must live outside the worktree.
That rule is necessary and it is not sufficient. **The clean, pre-seeding spec must
stay outside the worktree too**, and must never be copied in, even temporarily.

The reason is the mirror image of the ground-truth rule. If the clean spec is
committed and the maintainer then seeds defects into it, `git diff` — or `git log -p`
once the seeded version is committed — shows exactly which lines carry the defects.
The G1 reviewers are subagents with `Bash` and `Grep`. A reviewer reading the diff
scores near 100% and measures nothing. It is worse than reading the answer key:
reading the ground truth produces an absurd result someone notices, while reading the
diff produces a plausible one nobody questions.

Forbidding `git diff` in the reviewer prompts is a guarantee by instruction, given to
agents whose job is to explore. Keep it as a secondary defence — it is free — but do
not rely on it. Make the file unreachable instead.

**This environment makes the mistake easy to walk into.** A stop hook
(`~/.claude/stop-hook-git-check.sh`) requires every untracked file in the worktree to
be committed and pushed, and it fires at the end of the turn. A benchmark artifact
left untracked in the worktree *will* be committed, whatever the session intended.
The hook cannot see what is not in the worktree, so the only reliable place for a
clean spec is outside it:

```
~/factory-benchmark/spec-002-clean.md     good — hook cannot see it, git cannot see it
specs/002-.../spec.md   (untracked)       will be committed by the hook, run invalidated
```

What still belongs in the repository, and must: the gate report, the run README, the
score output. Those are produced *after* the reviewers have read the artifact, so
committing them corrupts nothing.

The seeded spec stays outside the worktree as well. At review time the maintainer
gives its path and the session reads it where it is.

| Run | Artifact | Status |
|---|---|---|
| 001 | `specs/001-cluster-stix-export/spec.md` | **abandoned, never scored** — clean spec committed as `9ca9b59` before seeding, and the feature turned out to be already implemented. Both reasons in `factory/benchmark/runs/001/README.md`. Kept deliberately; history not rewritten. |
| 002 | `~/factory-benchmark/spec-002-clean.md` | **scored: 60%** (6/10 detected, 1 partial, 3 missed, 0 true false positives). Clean spec outside the worktree, feature verified absent, defects seeded by a subagent so no reviewer and no orchestrator knew their location. `factory/benchmark/runs/002/`. **Measured under the pre-2026-08-17 detection rule** (DETECTED iff BLOCKING, at every severity). Deliberately not re-scored — 60% stands for this run and is not comparable to any rate produced after the rule change. |

Run 002's diagnosis, in one line each, so a future session does not re-derive it:
`contradiction`, `unjustified-assumption` and `missing-authorization` scored 100%;
`missing-failure-mode` and `untestable-criterion` scored 0%; of 24 objections exactly
one cited an `SC-###`, and both seeded SC defects were missed. Three profile additions
are set out in the run README and were **applied after the run was scored and committed**,
at the maintainer's instruction: two to `adversarial-critic` (read the spec against
itself, attack the `SC-###` section) and one to `security-reviewer` (check the success
criteria). Both profiles are therefore **untested as they now stand** — the 60% measured
the previous text. Run 003 must use a fresh artifact: re-running against
`spec-002-seeded.md` would measure the tuning and score high for the wrong reason.

One caveat travels with that number: a subagent chose the defects, not the maintainer.
The protocol asks for hand-seeding, and a hand-seeded run is still worth doing.

## Changes made after PR #62 merged (chore PR, CI integrity + triage)

| # | Change | Why it is here and not in a pipeline run |
|---|---|---|
| 1 | `ci.yml` authenticates Composer's GitHub downloads with the job token, installs through `.github/scripts/composer-install.sh` (bounded retry), and caches the Composer cache keyed on `composer.lock`. Every Dockerfile's `composer install` gained the same bounded retry, and the build stages take the token as a build arg. | PR #62 was merged past five red runs read as one transient GitHub 504. The run on `main` at `3abdb7c` then failed **the same way**, in four jobs at once — so the transient reading was wrong. Cause: unauthenticated dist downloads from `api.github.com` / `codeload.github.com` under a 60-request/hour per-IP ceiling, reached by this workflow's own concurrency (HTTP 429 interleaved with 504), inside the **image build** (`Dockerfile:23`) rather than in any step named "Install dependencies". |
| 1b | **Know this before tuning the CI cache.** The six containerised `composer install` steps are *not* the hot path: they run in 6-12 s off the Composer cache baked into the image (COMPOSER_HOME is outside `/app`, so the bind mount does not shadow it). All the time, and every failure, is in the image build. A host-side `actions/cache` cannot reach a build — a build cannot write back to the host — so the lock-file-keyed cache is a floor and a lock-delta cover, not a speed-up. | Measured on run 31988125536, not assumed. The first draft of this change redirected `COMPOSER_CACHE_DIR` at an empty host directory, which would have made cold runs *slower* and added ~200 requests per job to the endpoint that rate-limits us; the script now seeds that directory from the image cache so it can never be colder than doing nothing. |
| 1c | **The fix that actually holds: the backend image is built ONCE per run** (`build-backend-image`) and shipped to the other jobs as an artifact, which `docker load` and start with `docker compose up -d` — no `--build`. `docker-compose.yml` names the image (`scambuster-backend:ci`) so Compose uses it when present and still builds it locally when absent. The scanning matrix reuses the same artifact for `dev` and runs `max-parallel: 1`; a `concurrency` group cancels superseded PR runs. | Runs 748 and 749 proved tuning was not enough. The token raised the `api.github.com` ceiling and removed the 504s, but the remaining 429s came from **codeload**.github.com, where Composer cannot carry the Authorization header across the redirect — no token lifts that per-IP limit, so only making fewer requests helps. The run went from **four concurrent full dependency resolutions to one**, and from seven image builds to three, none concurrent. Do not reintroduce `--build` in a consumer job: that is the regression this row exists to prevent. |
| 1d | **Correction, from run 750 — the earlier causal story in rows 1 and 1c was wrong.** Those rows said this workflow's own concurrency was rate-limiting us. Run 750 failed with the runner unable to download a **40 KB first-party action tarball** (`actions/download-artifact`) from codeload.github.com: 429, 429, then 502, *before any workflow step executed*. Our Composer volume cannot cause that — the runner fetches actions before our steps exist. **codeload is throttling or degraded for these runners independently of what we ask of it.** The build-once and layer-cache changes stand on their own merits (fewer requests is right regardless of cause) but they were not, and are not, a guarantee of green. | Written down because it is the second time a confident causal story here turned out to be a symptom: first "transient 504", then "self-inflicted concurrency". The pattern to distrust is a diagnosis that explains the failures you looked at and was never tested against one you did not choose. When codeload refuses action tarballs, no change in this repository makes CI pass, and the gate report must say so **with the log lines**, never as an assumption. |
| 1e | The per-job Composer `actions/cache` was **removed** and replaced by a single BuildKit **layer** cache on the one remaining build, keyed on `composer.lock` + the Dockerfile. | Same lock-file key, but at the level that actually removes the ~200 codeload requests. A host-side Composer cache cannot reach inside a `docker build`, so the old one was a floor at best — and four copies of it meant four more action tarballs to fetch from the host that is throttling us. |
| 1f | **Measured on the first green runs (754 cold, 755 warm), so the next session does not re-derive it.** Cold build 2m36s; **warm build 48s** on a layer-cache hit, which means the `composer install` layer is reused and **zero requests reach codeload**. Artifact 281 MB: ~4s to upload once, 12s download + 19s `docker load` per consumer. Whole run 12m13s cold against 10m04s before — building once costs about **two minutes of wall clock**, and an earlier comment in `ci.yml` claiming it was cheaper has been withdrawn. | The cache key is `hashFiles(composer.lock, infra/docker/backend/Dockerfile)`. Touch either and the next run pays the cold price once, then returns to 48s. This is the only change in the PR that makes the failure *impossible* rather than less likely: no request, no 429. |
| 1g | **Still unproven, do not record as working:** the `--prefer-source` fallback has never completed end to end in CI. Run 753 proved its build stage succeeds while codeload refuses archives, then died in the dev-dependency prune; that prune is fixed (`COMPOSER_DISCARD_CHANGES`) but the fixed path has not been exercised, because codeload recovered and every run since took the fast dist path. | Written down because a green CI makes it tempting to file this as done. It is a last-resort path that has only ever been tested in halves — and the half that is untested is the half that runs during the next outage. |
| 1h | **The demo image must NOT be given a GitHub token.** It pins Composer 2.6.6 (for `autoload_runtime.php`), and that version rejects any `github-oauth` value containing an underscore — which every modern token has (`ghs_`, `ghp_`, `github_pat_`). It does not fall back to anonymous: it aborts in 0.2s with `BaseIO.php line 139: Your github oauth token ... contains invalid characters`. `container-security` therefore branches and builds `demo` without the build arg. | Caught on `caa3fe4`, and it was **self-inflicted**: the token that fixes the other two images is fatal to this one, so a change that helped globally broke one target. The demo image resolves one tree per run and is nowhere near a rate limit, so it loses nothing. Undo the Composer pin and this row can go — until then, do not "unify" the three builds. |
| 2 | `docs/factory/README.md` gains **"Trusting the gates"**: a red run is never merged on the assumption that it is infrastructure. | The rule that would have caught #1 at the time. |
| 3 | `SEC-002` registered: Redis runs with no `requirepass` on a network assumed trusted. Triaged as a **hardening gap, not an exposure** — no compose file publishes the port. Not fixed here. | One vulnerability, one PR. |
| 4 | `unjustified-assumption` added to the benchmark taxonomy, which is now declared **append-only**. | Run 002 used a term the table lacked and left the call to the maintainer. Adding beats renaming: renaming rewrites the per-type history of every earlier run. |
| 5 | Benchmark detection rule now compares the objection's severity against the **seeded** severity (`blocker`/`major` need BLOCKING, `minor` is caught by ADVISORY). `score.py` rejects a ground truth with no valid severity, and reports minor defects that drew BLOCKING. | The old rule scored a `minor` correctly raised as ADVISORY as a miss, so the only way to score well was to block on everything — the exact loudness the unseeded-blocking count warns about. |

## What in the CI fix is still inference, and what would disprove it

Several commits fix behaviour that only appears under CI load, diagnosed from run
logs rather than a controlled test. The numbers in row 1f are measured. These are
not — each is a reading that fitted the evidence, with the observation that would
break it. The next person to see a red install step should know which of these
was a guess.

| Assumption | Confidence | What would falsify it |
|---|---|---|
| codeload throttling is the remaining cause, independent of our volume | **high** — run 750 got 429/429/502 fetching a 40 KB first-party action tarball, before any step ran | A run where dependency downloads fail with 429 while action tarballs and unrelated GitHub endpoints are healthy. That would put the cause back inside our own traffic. |
| `COMPOSER_MAX_PARALLEL_HTTP=6` is a useful ceiling | **low — never tuned.** 6 was picked as half of Composer's default | 429s persisting with a single serialised build at 6. Then the burst was never the variable, and the number is cargo. Nobody has compared 6 against 12 or 3 on this repo. |
| 3 attempts at 60-149s jittered backoff is enough | **low — never observed to help.** No dist attempt has recovered on attempt 2 or 3; the successes came from a healthy codeload or the source fallback | A failure log showing attempt 2 or 3 succeeding on dist would confirm it. Its continued absence means the retry is buying nothing and only the fallback matters. |
| `--prefer-source` completes end to end | **half-proved.** Run 753 got its build stage through while codeload refused archives, then died in the prune. The prune is fixed and the fixed path has not run | Anything after the build stage failing on a source-installed vendor. See row 1g. |
| The layer cache holds across runs | **proved once** — 2m36s cold, 48s warm, exact-key hit | A build that pays the cold price with composer.lock and the Dockerfile both unchanged. |

## Setup complete

All five phases are done. The factory has never been exercised on a real change:
every pipeline, gate and reviewer profile is untested against actual work, and no
benchmark has been run — that is deliberate, since the benchmark must be run by
the maintainer with defects they seeded themselves.

Nothing in this setup ran the repository's own gates either: this environment has
no Docker daemon, so `make test`, `make stan` and the style check could not
execute here. Semgrep is the one gate that was actually run, and its rules are
verified against the real codebase (27 findings across 11 sites).

## Still open — not blocking

- Whether `composer.json`'s `>=8.2` floor should be raised to match the 8.3
  runtime (`DISCOVERY.md` §6.1). An application change, out of scope for this
  setup.
- **Two thresholds are uncalibrated and will need a first pass of real PRs**:
  the patch-coverage minimum (80%, in `ci.yml`) and whether Semgrep's registry
  rulesets (`p/php`, `p/security-audit`) can be made blocking. Neither could be
  set from a checkout alone — the registry rulesets have never been run against
  this codebase, so their finding count is unknown. The registry step is
  `continue-on-error: true` until that triage happens.
- The constitution's layering rules are enforced against the **diff**, not the
  whole tree: 11 pre-existing violations are recorded in `factory/found-issues.md`
  (issues 5, 6, 8). Fixing them is separate work, each through its own pipeline.
