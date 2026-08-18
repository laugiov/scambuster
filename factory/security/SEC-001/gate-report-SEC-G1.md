# Gate report — `SEC-G1`

| | |
|---|---|
| **Pipeline** | `security` |
| **Gate** | SEC-G1 |
| **Branch** | `claude/ci-integrity-security-triage-ynda74` |
| **Spec** | none — the root-cause analysis is the artifact under review (`factory/security/SEC-001/root-cause.md`) |
| **Date** | 2026-08-18 |
| **Decision requested** | Approve the root cause for SEC-001 **as scoped below** — specifically, that the finding covers two locations rather than the one in the register, and that the class includes credential-in-query-text (CWE-532) alongside hardcoded credentials (CWE-798). Approval authorises stage (c), the failing exploit test. No fix is proposed in this report. |

## What changed since the last gate

First gate of this run. What the run has produced so far:

- `root-cause.md` — three sections, no fix.
- Two facts that change the scope of the finding as registered:
  - **A second, unregistered instance of the same credential** at
    `docker-compose.yml:145`, same commit as the reported one. Outside the
    Semgrep rule's `paths.include` (`.semgrep/constitution.yml:160-162`), so it
    was never going to be flagged.
  - **A host-published port** at `docker-compose.yml:29-30` (`"5433:5432"`, no
    bind address) that turns the disclosed credential into an accepted login.
    Not mentioned in the register row.
- One fact that changes what a fix has to do: the credential is concatenated
  into SQL at `PreprodCopyService.php:62` and `:95`, so moving it to an
  environment variable removes it from git and keeps it flowing into the dev
  database's error log and the console command's output. `PiiMaskingProcessor`
  does not redact it (its email pattern requires a dotted TLD).
- `friction.md` — running note, per this run's brief.

## Objections raised

```
BLOCKING ; SEC-G1 entry condition "every entry point cited as file:line" ; the register row for SEC-001 cites one location; docker-compose.yml:145 holds the same credential and was omitted. A fix scoped to the register row leaves the finding half-open.
ADVISORY ; factory/security-findings.md SEC-001 row ; the row's impact field ("a preprod host with a throwaway password, so impact is low") was written without reference to docker-compose.yml:29-30, which publishes that host on 0.0.0.0:5433. The severity assessment stands only under preconditions the row does not state.
ADVISORY ; root-cause.md, Entry points #7 ; whether DBAL 3.10.5 puts SQL text into DriverException::getMessage() is unverified — backend-symfony/vendor/ is not installed in this environment. Marked unverified in the document rather than asserted.
ADVISORY ; PreprodCopyService.php:37-42 ; clearDevData() TRUNCATEs message, conversation and persona_performance_stats CASCADE with no guard on APP_ENV and no confirmation prompt. Out of scope for SEC-001 — not this vulnerability class — and carried forward below.
ADVISORY ; docs/factory/templates/gate-report.md:10-11 ; no storage path is defined for a security run's artifacts. This run chose factory/security/<SEC-###>/ and recorded the choice.
```

## How each was resolved

| Objection (field 2) | Raised by | Resolution | Evidence |
|---|---|---|---|
| SEC-G1 entry condition "every entry point cited as file:line" | this run, stage (a) | fixed — the second instance is in the analysis as entry point #2 and is inside the scope this gate is being asked to approve | `root-cause.md`, Entry points, "Where it can be read" |
| `factory/security-findings.md` SEC-001 row | this run, stage (a) | escalated to the maintainer — amending the register row is a decision, and the register is append-only on rows, not on assessments | `docker-compose.yml:29-30`; `root-cause.md`, Impact, preconditions 1–3 |
| `root-cause.md`, Entry points #7 | this run, stage (a) | downgraded to advisory — no failing test and nothing in the worktree settles it; the claim is marked unverified in the document | `composer.lock:156-157` (dbal 3.10.5); `backend-symfony/vendor/` absent |
| `PreprodCopyService.php:37-42` | this run, stage (a) | carried forward — different class, and the pipeline's one-vulnerability-one-PR rule forbids handling it here | `PreprodCopyService.php:37-42` |
| `docs/factory/templates/gate-report.md:10-11` | this run, stage (a) | carried forward — recorded in `friction.md` and in this run's README | `factory/security/SEC-001/README.md` |

## Advisory notes carried forward

- **`clearDevData()` truncates three tables unguarded.** Goes to
  `factory/found-issues.md` (not `security-findings.md` — it is a destructive
  operation with no environment guard, not a vulnerability class). **Not yet
  written**: found-issues entries for this run are added at stage (e) with the
  variants, so it is recorded here first and this line is the commitment.
- **The SEC-001 register row's impact field understates the preconditions.**
  Needs the maintainer's decision, since re-writing an assessment in an
  append-only register is not a thing this run should do unilaterally.
- **No storage convention for security-run artifacts.** `factory/security/SEC-001/`
  used; see `friction.md`.
- **`dblink` is created nowhere in the repository.** The command therefore fails
  on any stock stack, which is why the credential-in-error-log path is the
  normal path rather than the exceptional one. Not a security finding on its
  own; relevant if anyone ever expects this command to work.

## Escalation triggers

| Trigger | Fired | Detail |
|---|---|---|
| sensitive_paths | **no** | Nothing is changed yet. This run has written three markdown files under `factory/security/SEC-001/` and touched no code. The trigger will fire at stage (d) — `backend-symfony/src/Application/**` and `docker-compose.yml` are both in scope for the fix. |
| migration_present | no | none; checked `backend-symfony/migrations/` is untouched by this run |
| public_api_changed | no | no route, controller or DTO touched; the affected caller is a console command with no HTTP surface |
| new_dependency | no | `composer.json` and `composer.lock` untouched |
| large_diff | no | 3 files added, documentation only |
| adversarial_not_converged | no | the adversarial loop runs on the fix at SEC-G2, not on the root-cause document |

## Gate criteria

SEC-G1's entry conditions from `factory/gates.yaml:60-64`, plus the standard
rows. The build criteria do not apply to a documentation-only stage and are
marked **not run** rather than passed.

| Criterion | Result | How verified |
|---|---|---|
| vulnerability class named precisely | **pass** | CWE-798 primary, CWE-532 secondary, with an explicit statement that this is *not* SQL injection and why that matters to stage (e) |
| every entry point cited as `file:line`, with the authentication level required | **pass** | 7 entry points in three tables, each with a `file:line` and an authentication column |
| impact stated with its preconditions | **pass** | three named preconditions, all of which must hold; plus four things the run could not establish and how each would move the severity |
| no fix proposed, sketched or implemented yet | **pass** | `git diff` for this run adds only `factory/security/SEC-001/*.md`; no code file is modified |
| `make test` | **not run** | no code changed; the exploit test is stage (c), after this gate |
| `make stan` | **not run** | no PHP changed |
| style (`--dry-run`) | **not run** | no PHP changed |
| frontend gates | n/a | `frontend-react/` untouched |
| coverage vs base | n/a | no code changed |
| patch coverage | n/a | no code changed |
| mutation score (Infection) | n/a | no code changed |
| TDD order | n/a | security pipeline; the exploit test at stage (c) is committed before the fix by pipeline rule, and that is checked at SEC-G2 |
| documentation impact | n/a | this stage produces documentation only |
| `/speckit-analyze` | n/a | no spec, plan or tasks in this pipeline |
| tasks cite requirement IDs | n/a | no task list in this pipeline |

## How this document expects to be read

Per this run's brief, the maintainer will read the analysis asking **what is
missing from it**. Three places to aim that at first, because they are where the
analysis is weakest rather than where it is strongest:

1. **Entry point #7** is unverified (DBAL exception message content) and the
   document says so. If it is false, nothing else changes; if it is true, the
   console output is a second leak channel.
2. **The four unestablished facts** in Impact — real data in preprod, who ran
   the profile, credential reuse, clones before rotation — are all outside the
   repository, and three of the four would raise the severity. None can be
   closed by more reading of the code.
3. **The class boundary** is drawn at the worktree and its history. A published
   Docker image, a screenshot, or an issue comment carrying the same pair would
   be inside the class and outside everything this run searched.

## Maintainer decision

- [ ] Approved
- [ ] Approved with the advisories above accepted
- [ ] Changes requested

**Notes**:
