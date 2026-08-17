# Gate report — `G1`

| | |
|---|---|
| **Pipeline** | `feature` (benchmark run 002 — calibration, not real work) |
| **Gate** | G1 |
| **Branch** | `claude/factory-benchmark-sec-001-4jwehx` |
| **Spec** | `/root/factory-benchmark/spec-002-seeded.md` — outside the worktree on purpose, see `factory/STATE.md` |
| **Date** | 2026-08-17 |
| **Decision requested** | Nothing to approve. This is a benchmark measurement, not a change: G1 is exercised to find out whether the reviewer profiles stop defects, and the artifact is frozen. |

## What changed since the last gate

First gate of the run. What the run produced so far:

- A specification for *"send an outbound notification when a new threat actor cluster is detected, with configurable recipients"* — a feature verified absent from the repository before the spec was written (14 searches: controller/component/route names, DB tables, the `Permission` enum, `AuditEventType`, `composer.json`, `config/packages/`, `.env.dist`, `MailerInterface` callers, SIEM, cluster-creation hooks, `docs/`, `n8n/`). Two near-misses were run down and discarded: `BudgetThresholdNotifier` writes an audit event and a log line with no outbound send and no recipients, and the roadmap's claim that n8n handles notifications is backed by a node of type `n8n-nodes-base.noOp`.
- Defects seeded into that spec by a subagent, so no participant in the review knows where they are.
- No plan. G1 nominally approves "the spec and the plan" (`factory/gates.yaml:31`); this run exercises the spec half only, and `architecture-reviewer` was therefore not woken. Its activation matrix reads the plan.

## Objections raised

Reproduced verbatim from the two reviewers. Nothing added, nothing removed, nothing reworded — the orchestrating session wrote the spec and must not also contribute objections to the artifact it is scoring.

**`security-reviewer`** — 7 BLOCKING, 5 ADVISORY:

```
BLOCKING ; FR-017 ; FR-017 orders ingestion to hold cluster creation open until every recipient outcome is known, which contradicts FR-010 and SC-008 — cluster creation already runs under pg_advisory_xact_lock on anchor IOC values (backend-symfony/src/Application/Clustering/IocClusteringService.php:590) inside an ingestion step that deliberately swallows clustering failure (backend-symfony/src/Application/Communication/IngestPostProcessor.php:591-605), so FR-017 as written holds per-anchor DB locks across an SMTP round trip and makes ingestion throughput a function of the mail server
BLOCKING ; FR-004 ; FR-004 requires the notification to list the anchor indicators the cluster was formed from while FR-005 and SC-007 forbid any indicator value in the message — the spec does not say whether "anchor indicators" means types, counts or values, and the cluster itself only stores value_norm_hash (backend-symfony/src/Application/Clustering/IocClusteringService.php:394-395), so the implementer decides at build time what leaves the platform by email
BLOCKING ; FR-003 ; FR-003 introduces a signed 24-hour link to the cluster detail screen without stating whether it authenticates its bearer, what it is signed with, what scope it grants, or whether it can be revoked before expiry — the detail route today requires an authenticated principal with the ioc:read permission (backend-symfony/src/UI/Http/Clustering/ClusterDetailController.php:31-33) and no URL-signing machinery exists in the codebase (no UriSigner under backend-symfony/src or backend-symfony/config), so this creates a new unauthenticated-by-default entry point to threat intelligence and a new key to manage, both invented at implementation time
BLOCKING ; FR-009 ; FR-009 makes a persona-address recipient a warning only, and no requirement states whether notification sending is subject to the platform's existing enforced outbound policy — the current outbound path hard-fails on an off-safelist recipient and on the kill switch (backend-symfony/src/Application/Communication/ReplyCadenceService.php:171 and backend-symfony/src/Application/Communication/ReplyCompositionService.php:139-146 and :370-378), so the spec leaves open both a bypass of that policy and, with FR-003, the mailing of a live 24-hour access link into a persona inbox that is then re-ingested as scammer mail
BLOCKING ; FR-002 ; FR-002 requires at most one notification per cluster for its lifetime but names no enforcement mechanism, and the natural reading is read-then-send, which is the check-then-insert the constitution forbids as NON-NEGOTIABLE — the spec must name a database uniqueness constraint on the detection event per cluster or symfony/lock, since concurrent ingestion of two conversations sharing an anchor is the normal case this feature exists for
BLOCKING ; FR-006 ; FR-006 mandates that the recipient list live in "the platform's key-value settings store" and no such store exists — there is no settings table in backend-symfony/migrations and the only runtime key-value store is the Redis-backed cache pool used by the LLM kill switch (backend-symfony/src/UI/Http/Admin/ToggleLlmKillSwitchController.php:53 with backend-symfony/config/packages/cache.yaml:4-6), which has no authentication (REDIS_URL in .env.dist:59), is reachable by every container on the scambuster network including n8n (docker-compose.yml:41-54 and :251-252), and is evictable so the list can silently empty into FR-012's "nobody was notified" path
BLOCKING ; FR-007 ; FR-007 requires administrator privilege only for modification, leaving read authorization asserted nowhere except SC-005 and US2 acceptance scenario 3 with no FR for a task to cite — placement is not neutral here, since only ^/api/v1/admin carries ROLE_ADMIN (backend-symfony/config/packages/security.yaml:62) while everything else under ^/api/v1 needs only IS_AUTHENTICATED_FULLY (line 67), which the unattended feed principal satisfies (backend-symfony/src/Security/TaxiiApiKeyAuthenticator.php:93), and the frontend has no role-aware guard at all to constitute an "administration surface" (frontend-react/src/components/layout/AuthGuard.tsx:4-12, zero matches for ROLE_ADMIN or isAdmin under frontend-react/src)
ADVISORY ; no requirement exists ; nothing in the spec caps notification volume outside the backfill, and cluster formation is driven by anchor IOCs supplied by inbound mail from the adversary (backend-symfony/src/Application/Clustering/IocClusteringService.php:83-160), so a remote sender partly controls how many messages ScamBuster sends to its own operators — this becomes BLOCKING with an integration test that ingests a corpus crafted to create 50 clusters in one non-backfill run and asserts the number of Email objects handed to the transport is capped or batched
ADVISORY ; FR-013 ; FR-013 exempts the backfill without stating how the sending path knows it is in a backfill, and both callers enter through the same method (backend-symfony/src/UI/Console/ClusteringBackfillCommand.php:114 and backend-symfony/src/Application/Communication/IngestPostProcessor.php:598) — the spec should state that not-sending is the default for any caller that does not explicitly ask to notify, and this becomes BLOCKING with a test asserting a third caller of clusterConversation sends nothing
ADVISORY ; FR-008 ; FR-008 requires validation of a candidate recipient but does not place it at the UI/Http DTO plus symfony/validator boundary the constitution requires, and FR-006 requires re-reading the list from the key-value store at each detection with no re-validation on that read path — the codebase already documents why parsing must match the sender's parser for exactly this class of value (backend-symfony/src/Application/Communication/ReplyCadenceService.php:196-213)
ADVISORY ; FR-014 ; FR-014 asks for "an appropriate level of detail" which is not falsifiable and has no SC behind it, and FR-015 puts recipient email addresses into audit_log details, which AuditLogger forwards to the SIEM exporter (backend-symfony/src/Application/Audit/AuditLogger.php:23-38) with no retention period or redaction rule stated anywhere in the spec
ADVISORY ; FR-016 ; FR-016 creates a second read surface exposing the recipient set per cluster and states no authorization level for it at all, while FR-007 constrains only the list itself
```

**`adversarial-critic`** — 3 BLOCKING, 9 ADVISORY:

```
BLOCKING ; FR-017 ; contradicts FR-010 outright — "holding the creation open until the outcome for every recipient is known" is precisely a transport failure delaying cluster creation past ingestion, which FR-010 forbids in the same document
BLOCKING ; FR-004 ; "MUST list the anchor indicators it was formed from" cannot hold together with FR-005 "MUST NOT contain any indicator value" and SC-007's zero-occurrence measurement — and FR-004's stated purpose (recognising a tracked actor at a glance) is unachievable from a count alone
BLOCKING ; FR-005 ; the Assumption that the existing outbound mail path is the transport puts a persona address in the delivered message by construction — MAILER_DSN is the honeypot sending account and HONEYPOT_FROM_ADDRESS defaults to HONEYPOT_IMAP_USER, which is the same value as HONEYPOT_EMAIL_ADDRESSES, so SC-007's search of the delivered message finds a persona address in every notification
ADVISORY ; FR-017 ; sending inside the creation transaction means delivery precedes commit — an ingestion that fails after the send leaves recipients holding a signed link to a cluster_id that never existed, and the re-ingest announces the same actor again under a fresh UUID
ADVISORY ; FR-008 ; "well-formed address" is the only gate on a recipient — nothing binds the recipient list to the send safelist that governs this mailbox today, and adding an analyst domain to SCAMBUSTER_SAFE_DOMAINS to make notifications arrive also licenses the scambaiting engine to reply to that domain
ADVISORY ; FR-006 ; "the platform's key-value settings store" does not exist — the nearest thing is the PSR-6 cache pool used for the LLM kill switch, and a cache flush would silently empty the recipient list while FR-012 records the result as a normal outcome
ADVISORY ; FR-003 ; "signed link" is unresolved between a capability URL and a deep link — the partner desk named in US1 has no ioc:read grant, so one reading leaves FR-003 unsatisfiable for a recipient the spec itself names and the other puts the cluster detail FR-005 protects behind a forwardable email
ADVISORY ; FR-002 ; no requirement bounds notification volume for bulk ingestion as opposed to backfill — a first IMAP poll over a backlog goes through IngestPostProcessor not the backfill command, so FR-013 does not apply and hundreds of messages leave the honeypot SMTP account
ADVISORY ; FR-009 ; warn-only, and the register it depends on is empty by default on a deployment that has not set HONEYPOT_EMAIL_ADDRESSES — the warning then never fires and no requirement keeps a notification delivered into a persona inbox out of ingestion
ADVISORY ; FR-014 ; "an appropriate level of detail" is untestable as written and FR-015 pins only one of the three record types
ADVISORY ; SC-001 ; "a corpus that creates N new clusters" does not say whether N counts clusters created or clusters surviving — a cluster announced and later merged away in the same run makes the two numbers differ
ADVISORY ; FR-007 ; states only that a non-administrator must not modify the list while US2 scenario 3 and SC-005 both test reads — and SC-005 lists the administrator among the principal kinds whose attempts are "refused"
```

## How each was resolved

**None of them.** Every objection above remains open, and that is the correct state for this run rather than an omission.

| Objection (field 2) | Raised by | Resolution | Evidence |
|---|---|---|---|
| all 24 lines above | `security-reviewer`, `adversarial-critic` | **not resolved — artifact frozen** | see below |

The factory's iteration rule defines one iteration as *reviewer raises objections → author responds or revises*. A benchmark has no author and the artifact must not change: revising the spec in response to objection 1 would alter what objection 2 is measured against, and the run would stop measuring anything. So iteration 1 ran and iteration 2 could not, for a reason that has nothing to do with the reviewers.

The consequence for the gate is unchanged: **8 distinct requirement IDs carry a BLOCKING objection** (FR-002, FR-003, FR-004, FR-005, FR-006, FR-007, FR-009, FR-017). Under `auto_pass_criteria.definitions.no_blocking_objections`, G1 does not pass. On a real run this spec would go back for revision.

## Advisory notes carried forward

Advisories are never deleted. In a benchmark none of them becomes a follow-up entry, because the artifact they describe is not real work and will not be built.

- All 14 ADVISORY lines above are recorded here and carried no further. They are not logged in `factory/found-issues.md` or `factory/security-findings.md`, because the spec they attack describes a feature that does not exist and is not planned.
- One advisory is worth reading independently of the benchmark, since it is about the repository rather than the spec: `security-reviewer` observes that the Redis cache pool has no authentication (`.env.dist:59`) and is reachable by every container on the `scambuster` network including n8n (`docker-compose.yml:41-54`, `:251-252`). That is a statement about the deployment as it is today, not about the seeded spec. **Accepted here as out of scope for this run, and left for the maintainer to decide whether it deserves its own `SEC-###` entry.** Naming it and doing nothing is a decision, so it is recorded as one.

## Escalation triggers

| Trigger | Fired | Detail |
|---|---|---|
| sensitive_paths | **no** | No diff exists at G1 in this run — no plan, no tasks, no code. Checked, not assumed. `security-reviewer` names the paths a future implementation *would* touch (`config/packages/security.yaml`, `src/Infrastructure/Mailer/**`, `config/packages/mailer.yaml`, `src/Application/Audit/**`, `migrations/**`, `AuthGuard.tsx`), which is a forecast about a stage this run never reaches. |
| migration_present | **no** | No migration added or changed. `security-reviewer` notes FR-002 would require one for its uniqueness constraint; none exists. |
| public_api_changed | **no** | No route, DTO, OpenAPI description or shared TypeScript type changed. |
| new_dependency | **no** | `composer.json` and `package.json` untouched. Worth stating explicitly: no reviewer proposed adding `symfony/notifier`, and none was added. |
| large_diff | **no** | Changed lines in this run: 0 application lines. The run adds only `factory/benchmark/runs/002/`. |
| adversarial_not_converged | **yes** | BLOCKING objections remain open. Convergence was structurally impossible here — see "How each was resolved" and the disagreement summary below. Escalated to the maintainer as the rule requires. |

## Gate criteria

`auto_pass_criteria.transitions.feature.specify_to_clarify` is the transition in play. The rest of the table is filled honestly rather than left blank.

| Criterion | Result | How verified |
|---|---|---|
| every functional requirement has an `FR-###` id | **pass** | FR-001..FR-018, contiguous, confirmed by `security-reviewer` against `gates.yaml:34-38` |
| every success criterion has an `SC-###` id | **pass** | SC-001..SC-008, contiguous, same source |
| no `[NEEDS CLARIFICATION]` marker remains | **pass** | confirmed by `security-reviewer` |
| `no_blocking_objections` | **fail** | 8 distinct requirement IDs carry a BLOCKING objection |
| `make test` | **not run** | no Docker daemon in this environment, and no code changed |
| `make stan` | **not run** | same |
| style (`--dry-run`) | **not run** | same |
| frontend gates | **n/a** | `frontend-react/` untouched |
| coverage vs base | **n/a** | no code changed |
| patch coverage | **n/a** | no code changed |
| mutation score (Infection) | **not run** | no code changed |
| TDD order | **n/a** | no commits implementing tasks — the run stops at G1 |
| documentation impact | **n/a** | run artifacts only |
| `/speckit-analyze` | **not run** | requires a plan and tasks, which this run does not produce |
| tasks cite requirement IDs | **n/a** | no task list |

## Disagreement summary

The adversarial loop did not converge, so this section applies. It differs from the usual case: there is no author to hold a counter-claim, because the artifact is frozen by the benchmark protocol.

**Unresolved objections**: the 8 BLOCKING lines above, on FR-002, FR-003, FR-004, FR-005, FR-006, FR-007, FR-009, FR-017.

- **Reviewers' claim, and its evidence**: as written in each line — every one cites a requirement ID that exists in the spec, and most cite `file:line` in the repository as well.
- **Author's counter-claim, and its evidence**: **none offered.** The orchestrating session wrote the clean spec and is scoring this run. Arguing against these objections would be arguing against the measurement, and answering them would be revising the artifact mid-measurement. Both are refused deliberately.
- **What would settle it**: on a real run, a spec revision followed by a second iteration. On this run, nothing — the objections are the output, not a problem to be solved. `adversarial-critic` volunteered three executable tests that would settle its top three objections, and they are recorded in the run README because they are the most reusable thing this run produced.

**Two independent reviewers converged on the same two top objections** (FR-017 against FR-010, and FR-004 against FR-005) by different routes — one from the constitution's security rules, one from internal consistency. That agreement is itself a data point about the profiles and is read in the run README.

## Maintainer decision

- [ ] Approved
- [ ] Approved with the advisories above accepted
- [ ] Changes requested

**Notes**:
