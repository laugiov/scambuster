# Phase 3 — Solutions

> **Scope.** The blocking gaps confirmed in phase 2, **plus G-30 brought back in**.
> Six solution files.
>
> **Correction to the phase 2 reasoning.** G-30 was set aside there on the grounds
> that it depended on G-01. That reason was too strong: of the three guards that open
> up, `PaymentInstigationGuard` and the director brief are entirely independent of
> transparency, and `OperationalLeakageDetector` is only partly dependent — leaking an
> internal host name remains a problem whatever the disclosure. G-30 is therefore
> handled here.
>
> **Comparison grid** applied to each option: effort · debt introduced ·
> running cost for a team of 1 to 3 people · new attack surface created.
>
> **Sizing constraint (R5).** Real cadence: human email traffic, a few messages per
> minute at peak; caps configured at 50 active conversations/day and 200 LLM calls/hour
> (`config/packages/rate_limiter.yaml:33-41`).
> Every proposal is checked against this measurement, and over-engineering is
> flagged.
>
> **Zones** — in the sense of the zoning established in `01_scope.md` §D.1:
> **EZ** = exposed engagement zone (IMAP, n8n, SMTP) ·
> **PZ** = isolated processing zone (backend, PostgreSQL, Redis).

---

## G-01/02 — AI system transparency

**Requirement.** Regulation (EU) 2024/1689 Art. 50(1): the provider designs the system
so that the natural person is informed they are interacting with an AI system.
Exemption reserved for systems authorised by law to investigate criminal
offences.

### Option 1 — Disclose in the outgoing email

Insert a perceptible notice in every reply (first line of the body, or a footer block
that cannot be removed), and **reverse** the three mechanisms that oppose it:
remove the 3 relevant patterns from `FORBIDDEN_PATTERNS`, rewrite the CORE rule
`BasePromptRules.php:41`, remove the `automation_reveal` code from the codes watched by
the oracle.

| Criterion | Assessment |
|---|---|
| Effort | Technically low — a few dozen lines, plus a regeneration of the GUARD baseline |
| Debt introduced | **Maximal in functional terms.** [INFERRED] The scammer disengages at the first message: the value produced by the system — 85 recording slots, `attempts_avg 1.894`, conversations of 25–50 turns depending on type — rests entirely on the credibility of the exchange. Reasoning: `ConversationLifecycleConfig.php:22-55` sizes policies running up to 50 turns and 60 days; none of them makes sense against a counterpart who knows |
| Running cost, team of 1–3 | Zero in operation, but the anti-disclosure security apparatus becomes pointless and must be dismantled cleanly rather than left in place |
| New attack surface | None |

### Option 2 — Disclosure outside the body, in the technical headers

Add a message header signalling the artificial nature, without changing the body
or the safeguards.

| Criterion | Assessment |
|---|---|
| Effort | Very low — one header added in `ReplyCompositionService.php:311` |
| Debt introduced | **Doubtful compliance.** [INFERRED] The Commission guidelines of 20 July 2026 require disclosure that is perceptible within the interaction itself and explicitly rule out burying it in ancillary notices. A header that neither an email client nor a human reader displays falls under the same logic. Reasoning: the criterion retained is perceptibility by the person, not the technical presence of the information |
| Running cost | Zero |
| New attack surface | None — but the header becomes a honeypot detection marker, exploitable by any adversary who reads message sources |

### Option 3 — Build nothing: restrict the product's scope and document it

Acknowledge that **the engagement function is not deployable in S2**, and make the
product deployable in S2 **without it**.

The system has two separable functions:

| Function | Interacts with a natural person? | Art. 50(1) applicable? |
|---|---|---|
| Ingestion, IOC and TTP extraction, classification, clustering, profiling, STIX/MISP/TAXII export | **No** — processing of received emails | **No** |
| Generating and sending replies | **Yes** | **Yes** |

The measure: make engagement a function that is **disabled by default**, enabled only
by a deployer who declares that it falls under the exemption, and document it as
such in `SECURITY.md` and `DISCLAIMER.md`.

| Criterion | Assessment |
|---|---|
| Effort | **Low — the mechanism already exists.** `SCAMBUSTER_KILL_SWITCH` blocks generation (`ReplyCadenceService.php:55-77` → `ReplyHandler.php:137-139`). The work consists of inverting its default, turning it into an explicit configuration decision rather than an emergency switch, and extending its effect to `/send-email`, which it does not cover today |
| Debt introduced | Low. The product keeps most of its CTI value: the 148 routes, clustering, TTPs, exports and the financial block all remain operational |
| Running cost, team of 1–3 | Zero. An S2 deployer runs a passive honeypot platform; a deployer covered by the exemption enables engagement |
| New attack surface | None. A **reduction** of surface: with no engagement, `SMTP`, the outbound LLM guards and half the pipeline are no longer exposed |

### Recommendation — Option 3

Three reasons.

**1. It is the only option that preserves both compliance and value.**
Option 1 makes the product compliant and useless; option 2 probably does not make it
compliant. Option 3 makes it compliant **in S2** and leaves it fully operational
**in S1**, the setting for which the exemption exists precisely.

**2. It is consistent with what the project already says about itself.**
`DISCLAIMER.md:34-37` calls the security envelope "load-bearing, not
decorative". Making engagement conditional on a legal basis is the exact extension
of that position, not a concession.

**3. It costs the least and removes the most.** [INFERRED] Disabling engagement by
default takes the whole sending path out of the exposed zone and makes six of the ten
threats in the model moot for an S2 deployer — T1, T3 partly, T5, T6, T9 and
part of T2. Reasoning: all these threats have as their vector the generation or
sending of a reply, or the enrichment triggered by the reply pipeline.

**What remains to be built, and it is modest**: an explicit enablement setting distinct
from the emergency kill switch; a refusal to start if engagement is enabled without a
declared legal basis; extending the block to
`SendEmailController`; updating `SECURITY.md` and `DISCLAIMER.md`.

---

## G-21 — No separation between generating and sending

**Requirement.** Directive (EU) 2022/2555 Art. 20 (approval and supervision by the
management body) and Art. 21(2)(i) (access control policies).

### Option 1 — A separate send permission

Create a `reply:send` permission distinct from `reply:generate`; require it on
`SendEmailController` and `MarkReplySentController`; do not grant it to the n8n
principal.

| Criterion | Assessment |
|---|---|
| Effort | **Very low.** One case added to `src/Domain/User/Permission.php:19-40` (14 → 15), two `#[IsGranted]` attributes changed, one fixture set. `PermissionVoter` already works permission by permission |
| Debt introduced | None — this is the intended use of the existing permission model |
| Running cost, team of 1–3 | **Zero under normal operation.** Sending stays automated; what changes is who holds the permission, not the throughput |
| New attack surface | None. A reduction: a compromised n8n can no longer send |

### Option 2 — An analyst approval queue before sending

Every draft waits for a human verdict, on the model of the review queue for financial
IOCs.

| Criterion | Assessment |
|---|---|
| Effort | Medium — the pattern exists (`SubmitIocFeedbackController`, `IocFeedbackService`, review screen), to be transposed to replies |
| Debt introduced | **High.** Makes operation dependent on continuous human presence |
| Running cost, team of 1–3 | **Prohibitive.** [INFERRED] At 50 active conversations per day and a minimum cadence of 6 h between replies, the queue produces a stream of approvals spread across the whole day that 1 to 3 people cannot keep up with without becoming the limiting factor. Reasoning: `rate_limiter.yaml:38-41` and `ReplyCadenceService.php:27` |
| New attack surface | Low |

### Option 3 — Build nothing: document and have the risk formally accepted

Document that generation and sending share a permission, and provide a
risk acceptance template signed by the management body.

| Criterion | Assessment |
|---|---|
| Effort | Minimal |
| Debt introduced | **Pushes the gap onto every deployer**, indefinitely |
| Running cost | Zero |
| New attack surface | None |

### Recommendation — Option 1

It satisfies the separation of roles requirement at almost no cost, without
touching throughput. [INFERRED] Option 2 conflates two distinct needs: separation
of privileges — what Art. 21(2)(i) requires — and human validation of every
act, which no cited source imposes in S2. Reasoning: Art. 20 requires that
the management body approve and supervise **the measures**, not each message.

Option 1 combines naturally with the G-01 recommendation: when engagement
is disabled, the `reply:send` permission is granted to nobody.

---

## G-03/04/05 — Inference sovereignty

**Requirement.** Regulation (EU) 2016/679 Art. 32(1) and 28(1); Directive (EU) 2022/2555
Art. 21(2)(d).

### Option 1 — Full switchover to local inference

Refactor the 7 sites that hard-code a model, give `EmbeddingService` a
provider abstraction, remove the second `LLMServiceInterface` interface, and
make `LLM_PROVIDER=ollama` a genuinely total switchover.

| Criterion | Assessment |
|---|---|
| Effort | **Medium and bounded.** 7 sites (`ReplyValidator.php:103`, `OperationalLeakageDetector.php:28`, `PaymentInstigationGuard.php:50`, `ConversationAnalyzer.php:27`, `ConversationHistoryService.php:229`, `ConversationQualityAuditor.php:77`, `EmbeddingService.php:18`) + one embeddings interface + removing one DI alias |
| Debt introduced | **A quality regression to measure** — dealt with below. The `LLMClientInterface` port and the `OllamaClient` adapter already exist |
| Running cost, team of 1–3 | **Moderate.** An inference server to administer. [INFERRED] At a few messages per minute at peak, a single instance on an entry-level GPU is enough; there is **no need at all** for a scaling service, an inference queue or a cluster. Reasoning: 200 LLM calls/hour at the configured cap, i.e. ~3/min, against a throughput of several requests per second for a single instance |
| New attack surface | **Low and internal.** An inference service inside the PZ. In exchange, three Internet destinations are removed from the PZ |

### Option 2 — An inference gateway with a single egress point

Keep the external provider, but require that **every** call go through a single
internal component, the only one allowed to egress, with an allowlist and logging.

| Criterion | Assessment |
|---|---|
| Effort | Medium — one component to write, plus the same refactor of the 7 sites (without which the gateway is bypassed) |
| Debt introduced | One more in-house component to maintain |
| Running cost | Low |
| New attack surface | **Real**: the gateway sees all traffic in the clear and holds the API key |
| Fundamental limitation | [INFERRED] **It makes the flow demonstrable but does not stop it.** Third-party content still leaves. Gap G-03 remains; only G-04 is addressed |

### Option 3 — Build nothing: contract and document instead

Provide a model processing agreement, a no-training clause, and an exhaustive
matrix of outbound flows with their triggers.

| Criterion | Assessment |
|---|---|
| Effort | Low — the flow matrix already exists: `00_inventory.md` §2 lays it out (17 backend egress points, 11 n8n nodes) |
| Debt introduced | Pushes the burden onto every deployer |
| Running cost | Zero |
| New attack surface | None |
| Fundamental limitation | Addresses neither G-04 nor G-05: even under contract, the deployer cannot **demonstrate** that no data leaves, since the 7 hard-coded sites remain |

### Recommendation — Option 1

Option 3 leaves the entity unable to demonstrate control, which is precisely
the requirement of Art. 32(1)(d). Option 2 requires the same refactor as option 1 while
still letting content leave: it costs as much and delivers less. Option 1 is the
only one that closes all three gaps.

**Sizing note (R5).** The refactor of the 7 sites is required **in all
three options** — it conditions any claim of control over the flow. That is the
structuring work; the choice of provider comes afterwards.

### Protocol for measuring the quality regression

Explicitly required by the brief. The remarkable point is that **the measurement
harness already exists** and does not have to be built.

**Corpus available** [VERIFIED]: 99 `.eml` fixtures — 65 in
`tests/Smoke/ReplyObjectiveFixtures/`, 34 in `tests/Smoke/CialdiniMirrorFixtures/`
(covering EN/FR/DE/ES); frozen baseline `tests/Smoke/guard-baseline.json` with
`recording_slots 85`, `out_texts_scored 85`, `errors 0`, oracle fingerprint
`374f95367add`, and an associated `.sha256` checksum.

**Existing instruments**: `scambuster:smoke:reply-objective` →
`CanaryAggregate` → `scambuster:guard:check` with comparison against the baseline and
a tolerance of 0.05; `app:eval:ioc-extraction-metrics` (precision/recall/F1 on an
annotated set); `app:evaluate:reply-quality`; `app:eval:run-judge`.

**A five-step protocol.**

| Step | Action | Expected output |
|---|---|---|
| 1 | Re-freeze a reference baseline on `gpt-4o-mini` with the current corpus, checking that the oracle fingerprint is unchanged | A control baseline |
| 2 | **Freeze the judge model.** Run the candidate campaign with the local model **for generation only**, with `ReplyValidator` and `OperationalLeakageDetector` staying on the reference model | Isolates the generator's regression |
| 3 | Run `scambuster:smoke:reply-objective` then `guard:check` on the candidate | Deltas per violation code + `approved_rate`, `fallback_rate`, `attempts_avg` |
| 4 | Run `app:eval:ioc-extraction-metrics` on the same corpus, before and after | Extraction precision/recall/F1 |
| 5 | Replay steps 2 to 4 with the local model **as judge as well** | Measures the combined generator + judges effect |

**Why step 2 is the critical point.** [INFERRED] Switching `LLM_PROVIDER` changes
the generator's model **and** that of the two LLM judges at the same time, since they
all inherit the same provider. A naive campaign would therefore measure a compound of two
regressions and could produce a misleadingly good result: a weakened judge
approves more, which pushes `approved_rate` *up* even as quality
falls. Reasoning: `approved_rate` is computed from the validator's decisions
(`CanaryAggregate.php:29-83`), and the validator is itself an LLM call
(`ReplyValidator.php:109`).

**Expected regression, and the indicators that will reveal it.**

| Indicator | Reference value | Expected direction | Why |
|---|---|---|---|
| `attempts_avg` | **1.894** | **Up** | A weaker model passes PolicyGuard and the `iocScore` threshold first time less often; capped at 3 attempts |
| `fallback_rate` | **0.0** | **Up** | A direct consequence of exhausting the 3 attempts |
| `language_mismatch` | **0.0353** | **Sharply up** | The most exposed point: the corpus is multilingual and quantised local models lose language consistency first |
| `word_band` | 0.0 | Up | Less reliable compliance with the 12–150 word bands of `PolicyGuardConfig` |
| `payment_token` | **0.294** | Unstable | Reminder: an **informational** code, non-blocking — it will not fail the gate whatever the result |
| IOC extraction recall | to be measured at step 4 | **Down** | A structured JSON extraction task, sensitive to model size |

**Proposed acceptance criterion.** Since `fallback_rate` is two-sided in the
comparator with a tolerance of 0.05 (`CanaryBaselineComparator.php:107-109`), and
violation codes with a zero baseline are flagged on any non-zero value, the
decision threshold is already defined by the tool. **There is no new criterion to
invent**: the switchover is acceptable if `guard:check` exits zero against the
reference baseline, with the oracle fingerprint unchanged.

**Over-engineering flag.** [INFERRED] No model serving infrastructure —
cluster, load balancing, dynamic quantisation, prefix caching —
is justified at 3 calls/minute. The only sizing choice is that of the model size,
and it is settled by the measurement above, not by architecture.

---

## G-07/08 — Zoning and egress filtering

**Requirement.** Directive (EU) 2022/2555 Art. 21(2)(a).

### Option 1 — Two Docker networks and an explicit flow matrix

Separate `net-engagement` (n8n, IMAP/SMTP transports) and `net-processing`
(backend, PostgreSQL, Redis, local inference); mark `net-processing` as
`internal: true`; expose from the backend to the EZ only the routes n8n needs.

| Criterion | Assessment |
|---|---|
| Effort | **Low.** A change to `docker-compose.prod.yml`; no application code |
| Debt introduced | None — this is the standard move |
| Running cost, team of 1–3 | Low. One flow matrix to keep up to date |
| New attack surface | None. A **major reduction**: a compromised n8n loses direct access to PostgreSQL and Redis (threat T4) |
| Limitation | `internal: true` on the PZ is compatible with the G-03 recommendation (local inference) but **incompatible with keeping an external provider** — the two files must be settled together |

### Option 2 — Segmentation by separate hosts and a filtering egress proxy

EZ and PZ on two separate machines, with an explicit allowlist proxy.

| Criterion | Assessment |
|---|---|
| Effort | High |
| Debt introduced | Two hosts to administer, one proxy to maintain |
| Running cost, team of 1–3 | **High, and over-engineered.** [INFERRED] At a few messages per minute, two hosts double the administration load with no proportionate benefit for a team of this size. Reasoning: the security gain over option 1 is marginal — two separate Docker networks already cover lateral pivoting, which is the identified threat |
| New attack surface | The proxy itself |

### Option 3 — Build nothing: ship the flow matrix and let deployers segment

Document the 17 backend egress points and 11 n8n nodes with their triggers, and provide
an example segmentation without imposing it.

| Criterion | Assessment |
|---|---|
| Effort | Very low — the matrix exists (`00_inventory.md` §2) |
| Debt introduced | Every deployer redoes the work |
| Running cost | Zero |
| New attack surface | None |
| Limitation | With `docker-compose.prod.yml` still presented as the production target, a deployer will take it as it stands |

### Recommendation — Option 1, completed by the option 3 deliverable

Option 1 addresses the main threat — pivoting from the EZ to the data
store — for the cost of a few lines of configuration. Option 2 is
over-engineered at this cadence. The documentation deliverable from option 3 remains
necessary: without a published flow matrix, the deployer cannot build its
own egress allowlist, whatever segmentation is shipped.

---

## G-24/25 — Identifiable version and distributed SBOM

**Requirement.** Regulation (EU) 2024/2847, reporting per product and per version,
first milestone on **11 September 2026**; Directive (EU) 2022/2555 Art. 21(2)(d).

### Option 1 — Versioned releases with an attached SBOM

Add SemVer tags, add a release workflow that attaches the CycloneDX SBOM
already produced, and replace "main | Yes" in `SECURITY.md:5-7` with a
supported-versions policy.

| Criterion | Assessment |
|---|---|
| Effort | **Low.** The SBOM is already generated (`ci.yml:332-337`); it is just not distributed. The job is to attach it to a release, not to produce it |
| Debt introduced | A release discipline to keep up. `CHANGELOG.md` is already in Keep a Changelog format |
| Running cost, team of 1–3 | **Low and recurring.** This is the only item that adds a permanent load, of the order of a few minutes per release |
| New attack surface | None |

### Option 2 — A full supply chain

Add artefact signing, provenance attestation, reproducible builds
and pinning by digest.

| Criterion | Assessment |
|---|---|
| Effort | High |
| Debt introduced | High — signing keys to manage and rotate |
| Running cost, team of 1–3 | **Over-engineered.** [INFERRED] No cited source requires artefact signing or provenance attestation for this scenario; Art. 21(2)(d) asks that supply chain risks be taken into account, which versioned releases with an SBOM satisfy. Reasoning: the requirement is about knowing and controlling the components, not about attesting them cryptographically |
| New attack surface | Key management |

### Option 3 — Build nothing: document the deployed commit

Tell the deployer to record the commit hash it is running.

| Criterion | Assessment |
|---|---|
| Effort | Zero |
| Debt introduced | The publisher remains unable to designate a version affected by a vulnerability |
| Running cost | Zero |
| New attack surface | None |
| Limitation | Does not satisfy the CRA reporting obligation, which is expressed per product and per version |

### Recommendation — Option 1

The lowest cost in the whole set, the highest leverage: with no version, none
of the five other recommendations can be delivered in an identifiable way. Option 2 is
ruled out as over-engineered given the cited sources and the size of
the team. Pinning images by digest (G-26), which is part of it, remains an
open trade-off that I place among the opinions rather than the recommendations: it also
freezes the base image's fixes, and the Dockerfiles already run `apt-get upgrade`
at build time.

---

## G-30 — Correlated fail opens on a single cause

**Requirement.** Directive (EU) 2022/2555 Art. 21(2)(c).

**Recap of the finding.** Three controls open up when the inference provider is
unavailable: `OperationalLeakageDetector.php:59-89` (returns "no leak"),
`PaymentInstigationGuard.php:162-179` (approves outside the 12 fallback tokens),
`ReplyHandler.php:104-110` (the director brief returns `null`). The residual
deterministic fallback is limited to 12 payment vocabulary patterns.

### Option 1 — A circuit breaker on provider health

Instrument inference availability and, when it is unavailable,
**suspend generation** instead of generating with degraded guards.

| Criterion | Assessment |
|---|---|
| Effort | **Low.** The stop mechanism exists: the kill switch cache layer (`ReplyCadenceService.php:30`, key `llm.killswitch.active`). The job is to drive it from a provider failure counter rather than from the administration toggle alone |
| Debt introduced | Low. One more state to monitor — already exposed by the `scambuster_kill_switch` gauge (`MetricsController.php:97-100`) |
| Running cost, team of 1–3 | Low. A Prometheus alert already exists for the kill switch (`ScamBusterKillSwitchActive`) |
| New attack surface | **One, to be addressed**: an adversary able to cause inference failures — budget exhaustion, pathological payloads — gets a denial of service on generation. [INFERRED] An acceptable consequence: stopping generation is the safe degraded mode, the opposite of the current risk. Reasoning: not replying exposes nobody; replying with three guards open exposes the entity |

### Option 2 — Widen the deterministic fallback

Extend the 12 patterns of `PAYMENT_INFRA_TOKEN_PATTERNS` and add a deterministic
fallback to `OperationalLeakageDetector`.

| Criterion | Assessment |
|---|---|
| Effort | Medium, and **endless**. The GUARD oracle already carries 16 patterns against 12 in the guard, and its own docblock acknowledges that "Residual free-paraphrase … is an inherent limit" (`SafetyInvariantOracle.php:84-85`) |
| Debt introduced | **High.** Every pattern added has to be replicated in the oracle or the drift tests fail (`SafetyInvariantOracleTest.php:219-246`) |
| Running cost | Low |
| New attack surface | None |
| Fundamental limitation | Treats the symptom. The class of content targeted by the leak detector is precisely paraphrase, which by construction no pattern list covers |

### Option 3 — Build nothing: document the degraded mode

State explicitly in the operations documentation that unavailability of
inference degrades three controls, and let the deployer decide whether to cut off.

| Criterion | Assessment |
|---|---|
| Effort | Minimal |
| Debt introduced | The gap remains, and it is not even observable: nothing tells the operator that the guards are open |
| Running cost | Zero |
| New attack surface | None |

### Recommendation — Option 1

It reuses an existing mechanism, turns a silent degradation into a state that is
observable and alerted on, and keeps the only safe degraded mode for a system whose
function is to write to third parties. Option 2 pursues a completeness that the code
itself declares unreachable. Option 3 leaves a degradation invisible, which
is the precise point Art. 21(2)(c) targets.

**Interaction with the G-03 recommendation.** [INFERRED] Local inference moves the
failure mode without removing it: the outage becomes internal and better controlled,
but remains a single cause for all three guards. The circuit breaker therefore remains
necessary after the switchover. Reasoning: the three `catch (\Throwable)` blocks are
indifferent to the identity of the provider.

---

## Summary of recommendations

| Gap | Recommendation | Effort | Added operational load | Zone concerned |
|---|---|---|---|---|
| **G-01/02** | Option 3 — engagement disabled by default, enabled on declaration of a legal basis | Low | None | EZ — removes the sending path |
| **G-21** | Option 1 — separate `reply:send` permission | Very low | None | PZ |
| **G-03/04/05** | Option 1 — full switchover to local inference, after measurement | Medium | Moderate — one service to administer | PZ |
| **G-07/08** | Option 1 + the documentation deliverable from option 3 | Low | Low | EZ/PZ boundary |
| **G-24/25** | Option 1 — versioned releases with an attached SBOM | Low | **Low and recurring** | Outside the zones — build chain |
| **G-30** | Option 1 — circuit breaker on provider health | Low | Low | PZ |

**Two observations on the whole set.**

**The total cost is modest, and concentrated on a single item.** Five of the six
recommendations are low to very low effort and reuse mechanisms already
present — kill switch, permission model, GUARD harness, SBOM generator.
Only inference sovereignty represents real design work, and its
sizing item is the regression measurement, not the infrastructure.

**No new technology is introduced.** [INFERRED] The six files are
resolved with the existing `LLMClientInterface` port, the existing `OllamaClient`
adapter, Docker networks, the Symfony permission model and the SBOM already
generated. In line with R5, no measured need justifies a message queue, a
service mesh, a signing infrastructure or a distributed inference
service — a cadence of a few messages per minute rules them all out.

---

## What I could not verify — phase 3

1. Is disabling engagement enough to escape Art. 50(1), or would ingesting an
   email and producing an automatic acknowledgement of receipt already constitute
   a "direct interaction"?
2. Must a deployer covered by the exemption provide proof of it to the publisher, and
   in what form, for enabling engagement to be defensible on the provider's side?
3. What inference hardware is the target deployer prepared to provision — which
   bounds the model size and therefore the scale of the measured regression?
4. Is the corpus of 99 fixtures representative of real traffic in its distribution of
   languages and scam types, or does it over-represent certain cases?
5. Is the annotated reference set used by `app:eval:ioc-extraction-metrics`
   present in the repository, and how large is it?
6. Has `OllamaClient` ever been exercised against a real server, or only in unit
   tests — is the adapter battle-tested?
7. Is the structured JSON response format required by several calls
   (`response_format: json_object`) supported by the Ollama API in the same
   way, or is a tolerance layer needed?
8. How long does a full campaign of 85 slots take with a local model,
   given that it takes ~35 min with `gpt-4o-mini`?
9. Is there an operational constraint preventing `net-processing` from being
   `internal: true` — an egress need I have not listed?
10. Does removing `LLMServiceInterface` break the preproduction
    generator, and is that generator still used?
11. Does the SBOM produced by Trivy cover the PHP and npm dependencies, or only
    the system packages — which would determine whether the G-24/25 option is sufficient?
12. Is a supported-versions policy limited to a single branch acceptable under
    the CRA, or must a patch branch be maintained?
13. Should the circuit breaker also suspend IOC extraction and classification,
    which also call the LLM, or only reply generation?
14. What threshold of consecutive failures should trip the circuit breaker without making
    it hypersensitive to transient provider errors?

---

*End of phase 3.*
