# Specification — what and why

> **Scope.** This document states **what must be true** of the system and **why**,
> without naming any technology. Implementation choices are in
> `audit/plan.md`; sequencing is in `audit/tasks.md`.
>
> **Traceability rule.** Every requirement carries the identifier of the gap in
> `audit/02_gap.md` from which it derives. **A requirement without a gap identifier is
> removed** — none has been kept on that basis.
>
> **Framing.** Scenario S2 — NIS2 regulated entity, essential entity, self-hosted,
> EU perimeter with no national assumption.
>
> **Vocabulary.**
> **Engagement zone**: the part of the system in contact with uncontrolled
> correspondents. **Processing zone**: the part holding the data and the business
> logic. **Engagement**: producing and sending a message to a third party.
> **Deployer**: the entity that operates the system. **Publisher**: the party that publishes the product.

---

## EX-01 — Engagement does not take place without a declared legal basis

**Derives from:** G-01, G-02
**Why.** Informing a natural person that they are interacting with an AI system
is a design obligation borne by the publisher, and the exemption from it is reserved
for systems authorised by law to detect criminal offences. A deployer
who does not fall under that exemption cannot start the conversation, and the
product must not allow it to do so inadvertently.

**Requirements.**
1. On a fresh installation, no engagement function is active.
2. Enabling engagement is an explicit decision, separate from any
   day-to-day operating switch; it cannot result from a configuration
   default.
3. Enabling requires the deployer to record the basis on which it is enabled.
4. All functions outside engagement — receiving, analysis,
   extraction, correlation, reporting, distribution — remain fully operational
   when engagement is inactive.
5. Enabling and disabling are logged events.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A1.1 | Security — no sending by default | On an installation built from the shipped configuration, a test campaign injecting at least 20 incoming messages produces **0 outgoing message** |
| A1.2 | Security — no bypass | No call path allows sending while engagement is inactive; the property is checked by an automated test covering **all** sending endpoints |
| A1.3 | Functional — value preserved | With engagement inactive, the same campaign produces a volume of extracted indicators **equal** to the volume obtained with engagement active on the same incoming messages |
| A1.4 | Auditability | Every state change produces an audit entry carrying the actor, the timestamp and the resulting state |

---

## EX-02 — Producing a message and sending it are two distinct privileges

**Derives from:** G-21
**Why.** The management body must approve and oversee the measures. It cannot
oversee an act that commits the entity towards a third party if nothing distinguishes it,
in terms of rights, from an internal operation with no outside effect.

**Requirements.**
1. The right to produce a message and the right to send it are two separate
   privileges.
2. The automated component that drives the flow does not hold the sending right.
3. The separation is effective at every endpoint that triggers a send
   or that records a send as completed.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A2.1 | Security | A principal holding only the production right is denied sending on **100 %** of the relevant endpoints, checked by an automated test |
| A2.2 | Performance | The separation introduces **no** human validation in the nominal path: sending throughput is unchanged |
| A2.3 | Auditability | Every denial linked to a missing privilege produces an audit entry |

---

## EX-03 — The absence of content leaving the perimeter is demonstrable

**Derives from:** G-03, G-04, G-05
**Why.** The obligation is not only about the lawfulness of a transfer but
about the entity's ability to establish control over its flows. A setting that
switches only part of the paths supports no demonstration at all.

**Requirements.**
1. The choice of inference engine is **single and global**: one setting determines
   the destination of every call, without exception.
2. No call path contains a destination or a model identifier hard-coded
   in the code.
3. Every function that calls a model — message production, output
   checks, classification, extraction, correlation, vectorisation, profiling,
   evaluation — is subject to that single setting.
4. The deployer has a means of checking, without reading the code, that the
   configuration in force allows no content to leave the perimeter.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A3.1 | Security — completeness | Configured on an engine inside the perimeter, the system sends **0 request** to an external destination carrying message content, measured by observing outbound traffic over a complete campaign |
| A3.2 | Security — no regression | An automated check fails if a model identifier or an inference destination is reintroduced hard-coded in the code |
| A3.3 | Auditability | A diagnostic command reports, for every function that calls a model, the effective resolved destination |

---

## EX-04 — Quality regression is measured before any engine change

**Derives from:** G-03, G-04, G-05
**Why.** Bringing inference back inside the perimeter shifts quality. Without a
prior measurement on the existing corpus, the deployer can neither accept the regression nor
refuse it with full knowledge of the facts.

**Requirements.**
1. The comparison is carried out on the existing reference corpus, without modifying it.
2. The protocol separates the regression of the production function from that of the
   checking functions: **the checking functions are kept on a fixed reference
   engine** while production is measured.
3. A second campaign measures the combined effect when the checking functions
   also switch over.
4. The decision criterion is the one already in force for non-regression; no
   new criterion is introduced.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A4.1 | Reproducibility | Two runs of the protocol on the same configuration produce differences below the tolerance already in force |
| A4.2 | Validity | The safety oracle fingerprint is identical between the reference and the candidate; any divergence invalidates the comparison |
| A4.3 | Completeness | The report covers, as a minimum: approval rate, fallback rate, average number of attempts, rate by violation code, and indicator extraction precision/recall |
| A4.4 | Decision | The switchover is declared only if the non-regression comparison against the reference is conclusive |

---

## EX-05 — The engagement zone cannot reach the data store

**Derives from:** G-07, G-08
**Why.** The component that talks to uncontrolled correspondents and
processes their attachments is the most exposed. If it shares the network domain of the
data store, its compromise gives direct access to all of the data.

**Requirements.**
1. The engagement zone and the processing zone are two distinct domains.
2. From the engagement zone, only the application endpoints strictly
   needed for the flow are reachable.
3. The data store and the volatile state store are reachable only from the
   processing zone.
4. The processing zone has no outbound route to the outside beyond the
   destinations explicitly listed.
5. The access credentials for the inbound and outbound mailboxes are not held
   by a component that has access to the data store.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A5.1 | Security | From the engagement zone, any attempt to connect directly to the data store or the state store fails, checked by test |
| A5.2 | Security | The list of endpoints reachable from the engagement zone is explicit and any breach of it is detected |
| A5.3 | Functional | The nominal flow, from receiving to reporting, works without degradation after segmentation |

---

## EX-06 — Outbound flows are listed and published

**Derives from:** G-07, G-08
**Why.** The deployer must build its own filtering policy. It can only
do so if the publisher provides the exhaustive list of legitimate destinations,
their triggers and whether or not they are optional.

**Requirements.**
1. Every outbound flow is described by its destination, its protocol, its trigger,
   the nature of the data transmitted and whether it is mandatory or optional.
2. The inventory covers all components, including orchestration ones.
3. The inventory can be checked automatically against the state of the code.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A6.1 | Completeness | An automated check fails if an outbound flow exists in the code without appearing in the inventory |
| A6.2 | Usability | The inventory is enough to build a filtering policy without reading the code |
| A6.3 | Security | Any flow transmitting adversary-originated data to a third party is flagged as such and can be disabled independently |

---

## EX-07 — What is running can be identified

**Derives from:** G-24, G-25
**Why.** Without a version, the publisher can designate neither what is affected by a
vulnerability nor what fixes it, and the deployer cannot declare what it operates.

**Requirements.**
1. Every release carries a stable, ordered version identifier.
2. The system reports at runtime the version it is running.
3. A supported-versions policy states what receives fixes and for
   how long.
4. The change log links every change to a published version.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A7.1 | Auditability | The version reported at runtime matches exactly the deployed release |
| A7.2 | Usability | From a version identifier, it is possible to establish the list of changes and security fixes included |
| A7.3 | Lead time | A reported vulnerability receives an identified version fixing or documenting the point, within the lead time announced by the policy |

---

## EX-08 — Software composition is published with every version

**Derives from:** G-24, G-25
**Why.** The deployer must take into account the risks coming from its
suppliers and from the integrated components. It can only do so if it obtains the
exact composition of what it runs, tied to the deployed version.

**Requirements.**
1. Every release comes with its SBOM.
2. The SBOM covers the application components and those of the runtime base.
3. The SBOM is obtained without rebuilding the product.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A8.1 | Completeness | The SBOM lists the direct and transitive dependencies of both application chains as well as the base packages |
| A8.2 | Correspondence | The published SBOM matches the artefact published under the same version |
| A8.3 | Usability | The deployer can compare the SBOM against a vulnerability repository without the publisher's involvement |

---

## EX-09 — Inference engine failure leads to a safe and visible state

**Derives from:** G-30
**Why.** Several security checks delegate their decision to an inference
engine and adopt permissive behaviour when it is unavailable. A single
failure therefore degrades several checks at once, without the service
stopping and without the operator being informed.

**Requirements.**
1. Unavailability of the inference engine suspends engagement, rather than
   continuing it with degraded checks.
2. The suspended state is observable by the operator and raises an alert.
3. Resumption after recovery is explicit and logged.
4. The trigger threshold distinguishes a transient error from unavailability.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A9.1 | Security | With the engine unavailability simulated, the system produces **0 outgoing message** |
| A9.2 | Observability | The suspended state is exposed in monitoring within less than one collection period, and raises an alert |
| A9.3 | Availability | An isolated error does not trigger suspension; the threshold is configurable and documented |
| A9.4 | Auditability | Suspension and resumption each produce an audit entry |

---

## EX-10 — The documentation describes the system that exists

**Derives from:** G-40, G-41
**Why.** A risk acceptance is signed by a management body on the
strength of a documentation file. Twenty-four proven contradictions between the
documentation and the code make that file unusable: a deployer relying on it
describes a system that does not exist.

**Requirements.**
1. Every statement about an implemented control can be checked in the code.
2. Controls announced but absent are withdrawn or reclassified as planned.
3. The durations, counts and thresholds announced match the values in the code.
4. Every operating procedure referenced exists.
5. The documentation intended for the deployer distinguishes what is shipped active, shipped
   inactive, and not shipped.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A10.1 | Accuracy | The 25 listed contradictions are handled, each either by correcting the documentation or by implementing the announced control |
| A10.2 | Non-regression | An automated check verifies that the numeric values quoted in the documentation match those in the code |
| A10.3 | Completeness | No referenced operating procedure is missing |

---

## Cross-cutting requirements

### EX-11 — The default mode is the safe mode

**Derives from:** G-15, G-22, G-34
**Why.** Three gaps share the same cause: the shipped configuration keeps
the permissive setting — no export of security events, limited secret
scanning, retention not applied. For a third-party deployer, the shipped default is what counts.

**Requirements.**
1. A setting whose permissive value weakens a security property or a
   retention obligation is not kept as the default value.
2. Where a safe default is not possible, startup in production is refused as long
   as the deployer has not made an explicit choice.

**Acceptance criteria.**

| # | Criterion | Measure |
|---|---|---|
| A11.1 | Security | On an installation built from the shipped configuration, no security property depends on an action the deployer would have to guess |
| A11.2 | Usability | The startup refusal states precisely the missing setting and the expected action |

---

## What this specification does not cover

Stated so as to avoid any broad reading.

| Out of scope | Reason |
|---|---|
| Evidential value of the artefacts before a court | Set aside with scenario S1; the requirement kept is reliability and auditability, not evidence admissible against a party |
| Civil liability towards an injured third party | Beyond technical reach |
| Legal qualification of modus operandi profiles | A decision for the data controller, not a product gap |
| Anonymisation of free-text content | A real gap, but the non-processing argument kept in phase 2 holds: deletion is a safer substitute than poorly implemented anonymisation |
| Signing and provenance attestation of artefacts | Over-engineered given the sources cited and the size of the team |
| Multi-node federation | Belongs to scenario S3, which was not selected |
