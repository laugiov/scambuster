# Benchmark run 002 — G1 reviewer calibration

**Detection rate: 60.0%** — detected 6/10, partial 1, missed 3.
**Blocking objections on unseeded requirements: 2** — both verified as genuine defects, see below.

| | |
|---|---|
| Artifact | `/root/factory-benchmark/spec-002-seeded.md` — outside the worktree, never committed |
| Ground truth | `/root/factory-benchmark/gt-002.yaml` — outside the worktree, never committed |
| Stage | G1, spec only. No plan, so `architecture-reviewer` was not woken |
| Reviewers | `adversarial-critic`, `security-reviewer` |
| Gate report | [`gate-report-G1.md`](gate-report-G1.md) — 24 objections, 10 BLOCKING across 8 distinct IDs |
| Feature | outbound notification on new cluster detection, with configurable recipients |

## How this run was built, and what that is worth

Run 001 was abandoned because the clean spec reached git history and the feature turned
out to already exist. Both are fixed here, and the fixes are structural rather than
promises:

- **The feature was verified absent** before the spec was written — 14 searches across
  controller and component names, routes, DB tables, the `Permission` enum,
  `AuditEventType`, `composer.json`, `config/packages/`, `.env.dist`, `MailerInterface`
  callers, SIEM, cluster-creation hooks, `docs/`, and `n8n/`. Two plausible-looking
  matches were run down and discarded: `BudgetThresholdNotifier` writes an audit event
  and a log line with no recipient and no send, and the roadmap's claim that n8n handles
  notifications resolves to a node of type `n8n-nodes-base.noOp`.
- **Neither spec entered git.** `git log --all -- '*spec-002*'` is empty. The stop hook
  that committed run 001's spec cannot see a file outside the worktree.
- **The reviewers had clean context.** Subagents, no knowledge of the benchmark, no
  access to the clean version, no mention that anything was seeded.
- **The orchestrating session did not know where the defects were.** It wrote the clean
  spec, so it knew that document — but the seeding was delegated to a subagent that
  returned counts only, and neither the seeded spec nor the ground truth was read until
  after `score.py` had run against a gate report that was already written.
- **No objection in the gate report was written by the orchestrator.** All 24 are
  verbatim from the two reviewers.

### The weakness that remains, stated plainly

**A model chose the defects, not the maintainer.** The protocol in
`factory/benchmark/README.md` says to seed by hand, and this run did not. What a model
considers a plausible defect is not what a human considers one, and a seeder drawn from
the same family as the reviewers may favour defects that family finds — or, just as
plausibly, defects it finds interesting rather than defects that are realistic.

This bounds every number below. The run is evidence about the reviewer profiles; it is
not the calibration the protocol describes. A hand-seeded run remains worth doing, and
would be the thing to compare this against.

Secondary: N=10 on one artifact in one pass. The benchmark README is right that three
runs over the same artifact say far more than one, particularly about which defect
*types* are consistently missed.

## Results by defect type

| Type | Seeded | Detected | Partial | Missed | Rate |
|---|---|---|---|---|---|
| `contradiction` | 2 | 2 | 0 | 0 | **100%** |
| `unjustified-assumption` | 2 | 2 | 0 | 0 | **100%** |
| `missing-authorization` | 2 | 2 | 0 | 0 | **100%** |
| `missing-failure-mode` | 2 | 0 | 0 | 2 | **0%** |
| `untestable-criterion` | 2 | 0 | 1 | 1 | **0%** |

| Defect | Requirement | Severity | Verdict |
|---|---|---|---|
| D-001 `unjustified-assumption` | FR-003 | minor | DETECTED |
| D-002 `contradiction` | FR-004 | major | DETECTED |
| D-003 `unjustified-assumption` | FR-006 | minor | DETECTED |
| D-004 `missing-authorization` | FR-007 | blocker | DETECTED |
| D-005 `missing-authorization` | FR-009 | major | DETECTED |
| D-006 `missing-failure-mode` | FR-011 | **blocker** | **MISSED** |
| D-007 `untestable-criterion` | FR-014 | minor | PARTIAL |
| D-008 `contradiction` | FR-017 | blocker | DETECTED |
| D-009 `missing-failure-mode` | SC-006 | major | **MISSED** |
| D-010 `untestable-criterion` | SC-008 | major | **MISSED** |

## What the pattern says

The split is unusually clean, and it is not random.

### 1. The reviewers find what is present and wrong. They do not find what is absent or vague.

Six for six on defects where a requirement *says something false* — a contradiction, an
unjustified mechanism, an authorization downgraded to a warning. Zero for four on
defects where a requirement *stops saying something* or says it in words that cannot be
checked.

That is the harder half of spec review, and it is the half the constitution most needs
covered: an unstated failure mode is exactly what gets invented at implementation time.
Both profiles nominally cover it — `adversarial-critic` is told to "find the happy path
with no stated failure mode", `security-reviewer` to treat "an unstated failure mode on
an auth path" as a missing requirement — so the instruction exists and did not fire.

### 2. The Success Criteria section is a blind spot

**Of 24 objections, exactly one cites an `SC-###`,** and it is advisory. Both seeded SC
defects were missed. Every blocking objection in the run cites an `FR-###`.

`docs/factory/pipelines.md` defines "requirement ID" as `FR-###` **or** `SC-###`, and
`score.py` joins on both. But neither reviewer profile mentions success criteria
anywhere: `adversarial-critic`'s spec-attack list is written entirely in the vocabulary
of *requirements*, and `security-reviewer`'s G1 question is about security behaviour.
The section that defines how anyone will know the feature works is being skimmed.

This is the single most actionable finding in the run.

### 3. Two of the three real misses were visible as internal contradictions

The seeded spec disagrees with itself in plain sight, and nobody traversed it:

- **US3 acceptance scenario 3** (line 94): *"delivery to one of three recipients fails …
  the other two are still delivered and the one failure is recorded individually."*
- **FR-011** (line 154): *"When the notification transport reports an error **for a
  cluster**, the system MUST record the failure with the cluster identifier, the reason,
  and the time."* — no per-recipient behaviour at all.

The acceptance scenario demands a behaviour no functional requirement carries. The same
holds for SC-006, narrowed to *"with the transport available"* while US3 describes three
failure scenarios.

Both reviewers read the requirements against the codebase — thoroughly, and to good
effect. Neither read the requirements against the **rest of the same document**.

## The false-positive line, and why it reads better than it looks

`score.py` reports blocking objections on two unseeded requirements: **FR-002** and
**FR-005**. Both were checked against the repository, and both are real defects — in the
*clean* spec, introduced accidentally when it was written, not seeded:

- **FR-002** required at most one notification per cluster and named no enforcement
  mechanism. `security-reviewer` read that as check-then-insert, which
  `.specify/memory/constitution.md:95` forbids as NON-NEGOTIABLE. Correct.
- **FR-005** forbade persona addresses in the notification, while the spec's own
  Assumption made the existing outbound mail path the transport. `adversarial-critic`
  traced `.env.dist:234` — *"From address for outgoing replies (defaults to
  `HONEYPOT_IMAP_USER` if not set)"* — against `.env.dist:201`,
  `HONEYPOT_EMAIL_ADDRESSES=${HONEYPOT_IMAP_USER}`. The From header carries a persona
  address in every notification. Correct, and it required reading two files nobody
  pointed at.

**So the true false-positive count for this run is 0.** The reviewers were not loud; they
found two defects the answer key did not know about. Read against the benchmark README's
warning — *"a reviewer that blocks everything detects everything"* — these gates are
discriminating. That is the strongest single result here, and it is worth more than the
60%.

It also means the detection rate is a floor rather than a point estimate: 6 of 10 seeded
defects, plus 2 unseeded ones nobody had counted.

### One miss is arguably the scorer's, not the reviewer's

D-007 (FR-014, *"an appropriate level of detail"*) was caught by **both** reviewers,
citing an ID that exists in the spec — and both graded it ADVISORY. The ground truth
rates it `minor`. The reviewers' severity judgement and the seeder's agree; the scoring
rule counts the result as not-caught, because it counts only BLOCKING.

That is the rule working as designed — an advisory would not have stopped the merge — but
it means the detection rate silently penalises correct severity judgement on minor
defects. Worth knowing before reading 60% as "four failures". Three of the four are real.

## Verdict against the go/no-go grid

60% lands in the middle band, where the instruction is to look at *which* types are
missed rather than at the number.

They are concentrated, in the way the grid anticipated: two categories at 0%, three at
100%, and the failures cluster further into one document section and one review
technique. **This is a targeted fix to two profiles, not a recalibration.** Nothing here
suggests the profiles are broadly miscalibrated — the false-positive analysis argues the
opposite.

## Smallest change that would address the misses

**Diagnosed first, then applied at the maintainer's instruction** — after this run was
scored and committed, so the diagnosis stands on its own and can be re-read against the
profiles as they were. Three additions to `.claude/agents/adversarial-critic.md` and
`.claude/agents/security-reviewer.md`: no rewriting, no change to any activation matrix,
no change to any iteration rule.

**These profiles are now untested.** The 60% above was measured against the previous
text and says nothing about the new one. Whether the additions help is an open question
until a run with a fresh artifact answers it.

**1. `adversarial-critic`, under "How to attack → A spec"** — one bullet:

> Attack the Success Criteria with the same rigour as the requirements. An `SC-###` with
> no baseline, no threshold or no stated procedure is untestable, and it is the cheapest
> defect in a spec to miss because the section reads like a summary of decisions already
> taken.

**2. `adversarial-critic`, same list** — one bullet:

> Read the acceptance scenarios and success criteria against the functional requirements.
> A behaviour a scenario demands that no `FR-###` carries is a missing requirement, and a
> requirement that handles a failure at a coarser granularity than the scenarios describe
> is a missing failure mode wearing the costume of a present one.

**3. `security-reviewer`, in the G1 paragraph** — extend the existing sentence:

> …each is a requirement missing from the spec. Check the success criteria too: a
> security property with no `SC-###` that can verify it will not be tested, and an
> `SC-###` that measures only the path where nothing fails leaves every failure path
> unverified.

Addition 2 alone would have caught D-006 (blocker) and D-009. Addition 1 targets D-010.
Together they address all three real misses without touching what already works at 100%.

**Do not re-run against this same artifact now that they are applied.** The profiles are
tuned to a spec whose defects are known, so a second score on `spec-002-seeded.md` would
measure the tuning and would come out high for the wrong reason. Run 003 needs a fresh
spec — hand-seeded, ideally — and only then is the comparison with 60% meaningful.

## Vocabulary

The benchmark README fixes a defect vocabulary so runs compare across time. This run used
one term not in that table: **`unjustified-assumption`** — a technical or architectural
choice asserted with no reason given. The maintainer's five seeding categories had no
existing term for it, and the nearest candidates (`unsafe-default`, `scope-creep`) mean
something else.

It scored 100%, so nothing about the result hinges on it. But the table in
`factory/benchmark/README.md` should either gain the term or the category should be
renamed to an existing one before run 003 — otherwise run 003 is not comparable to this
one. **Left for the maintainer**; changing the shared vocabulary is not a decision this
run should take on its own.

> **Resolved, 2026-08-17.** The term was **added** to the taxonomy rather than renamed
> into an existing category, and the taxonomy is now declared append-only in
> `factory/benchmark/README.md`. This run's per-type rates therefore stand as measured
> and stay comparable with run 003.
>
> The **detection rule changed in the same pass** and this run is deliberately **not**
> re-scored: **60% stands as the number for run 002**. A defect is now caught by an
> objection at or above the severity it deserved, so a `minor` correctly raised as
> ADVISORY counts as detected instead of as a miss. Both `minor` defects here (D-001,
> D-003) drew BLOCKING objections, so re-scoring would leave the figure unchanged — but
> the rate above means "measured under the old rule", and the first rate produced under
> the new one is a fresh baseline, not a comparison with this number.

## Reusable output

`adversarial-critic` volunteered three executable tests that would settle its top three
objections. They outlive the benchmark and are the most durable thing this run produced:

1. **FR-017 vs FR-010** — block the transport, then assert from a second connection that
   the cluster row is visible before delivery returns. Creation is held inside the
   transaction holding `pg_advisory_xact_lock(hashtext(anchor))`
   (`backend-symfony/src/Application/Clustering/IocClusteringService.php:590`), so a hung
   SMTP server blocks every concurrent ingestion sharing that anchor. Settles SC-008 too.
2. **FR-004 vs FR-005** — build a notification for a cluster whose anchors are a known
   IBAN and wallet, then run SC-007's own search over the rendered message. One of the two
   IDs has to change.
3. **FR-005 vs the transport assumption** — send one notification through the existing
   mail path against a capturing transport, and grep the full delivered message including
   headers for each entry of `HONEYPOT_EMAIL_ADDRESSES`.
