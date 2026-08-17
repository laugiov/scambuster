# Feature Specification: Export a threat actor cluster as a STIX 2.1 bundle

**Feature Branch**: `claude/factory-benchmark-sec-001-4jwehx`

**Created**: 2026-08-17

**Status**: Draft

**Input**: User description: "Export a threat actor cluster as a STIX 2.1 bundle, on demand, from the UI"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - An analyst hands one actor's intelligence to another platform (Priority: P1)

An analyst is looking at a single threat actor cluster — one durable identity built
from several conversations — and needs to give that actor's intelligence to a
platform ScamBuster does not feed automatically: a partner's CTI instance, an
incident ticket, a takedown request annex. Today the only way out is to wait for a
scheduled feed poll or to reconstruct the actor by hand from per-conversation
exports. The analyst wants a single action, on the screen they are already on, that
produces one self-contained STIX 2.1 bundle for that actor.

**Why this priority**: This is the feature. Everything else here supports it. Without
it the analyst has no on-demand path from an actor to a shareable artifact, which is
the exact moment — during an investigation, not on a feed's schedule — when the
intelligence is worth sharing.

**Independent Test**: Open a cluster that has at least one exportable indicator,
activate the export, and confirm a STIX 2.1 bundle file is received that contains
that actor and its indicators. Delivers value on its own: the file can be handed to
a consumer immediately.

**Acceptance Scenarios**:

1. **Given** an analyst holding the intelligence-export permission is viewing a
   cluster with three exportable indicators, **When** they activate the export,
   **Then** they receive one file containing a STIX 2.1 bundle with one threat actor
   object, three indicator objects, and three relationships attributing those
   indicators to that actor.
2. **Given** the same analyst and the same cluster, **When** they activate the export
   twice, **Then** both files carry the same actor identifier and the same indicator
   set, and each bundle carries its own distinct bundle identifier.
3. **Given** a cluster for which a psychological profile has been generated, **When**
   the analyst exports it, **Then** the actor object in the bundle carries that
   profile.
4. **Given** a cluster for which no psychological profile has been generated,
   **When** the analyst exports it, **Then** the export succeeds and the actor object
   carries no profile rather than an empty or placeholder one.

---

### User Story 2 - The analyst is never left guessing why an export produced nothing (Priority: P2)

Exports fail, and they fail in ways that look identical from the outside: the actor
does not exist any more, the analyst is not allowed to export, every indicator was
withheld by policy, the server erred. An analyst who sees nothing happen cannot tell
which of those occurred, and will either retry pointlessly or report a bug that is
not one.

**Why this priority**: The capability in US1 is unusable in an investigation if a
silent failure is indistinguishable from an empty result. It is P2 and not P1 only
because US1 delivers value the moment it works at all.

**Independent Test**: Trigger each failure mode in turn against a cluster and confirm
the analyst is shown a distinct, accurate message and no file is written.

**Acceptance Scenarios**:

1. **Given** an analyst without the intelligence-export permission, **When** they
   attempt the export, **Then** the attempt is refused, no bundle content reaches the
   browser, and they are told they lack the permission.
2. **Given** a cluster whose indicators are all excluded by the export policy,
   **When** the analyst exports it, **Then** they receive a bundle containing the
   actor and no indicators, together with a message stating that indicators were
   withheld and on what grounds.
3. **Given** a cluster identifier that no longer resolves to a cluster, **When** the
   analyst activates the export, **Then** they are told the cluster was not found and
   no file is written.
4. **Given** the export fails with an unexpected server error, **When** the analyst
   activates the export, **Then** they are shown an error, they remain on the cluster
   screen, and no partial or truncated file is written.

---

### User Story 3 - Someone can answer, later, who took this actor's intelligence out (Priority: P3)

This bundle carries first-party intelligence about a real actor, including indicators
that route to abuse desks. When a partner asks where their copy came from, or when an
account is compromised, someone has to be able to say which principal exported which
cluster and when.

**Why this priority**: It changes nothing for the analyst doing the export, and the
feature is shippable without it — but it is cheap at build time and expensive to
retrofit, since the events it records cannot be reconstructed afterwards.

**Independent Test**: Perform a set of successful and refused exports, then read the
audit log and confirm one entry per attempt with the acting principal, the cluster,
and the outcome.

**Acceptance Scenarios**:

1. **Given** an analyst exports a cluster successfully, **When** an administrator
   reads the audit log, **Then** exactly one entry records the acting principal, the
   cluster identifier, the outcome, and the time.
2. **Given** an export is refused for lack of permission, **When** an administrator
   reads the audit log, **Then** exactly one entry records the refusal, with the same
   fields.

---

### Edge Cases

- A cluster built from a single conversation, with no cross-conversation
  corroboration: the export must still produce a well-formed bundle for that actor.
- A cluster with no indicators at all, as opposed to one whose indicators were all
  withheld by policy: these are different situations and the analyst is told which.
- A cluster large enough that the bundle is measured in megabytes: the analyst must
  still get a complete file or an explicit failure, never a truncated one.
- The cluster is re-clustered, merged or removed between the moment the screen was
  loaded and the moment the export is activated.
- The analyst's session expires between loading the screen and activating the export.
- Two analysts export the same cluster at the same moment.
- The same analyst activates the export twice in quick succession before the first
  has completed.
- An indicator's analyst verdict changes between two exports of the same cluster: the
  two bundles legitimately differ, and neither is wrong.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: An authenticated analyst MUST be able to request a STIX 2.1 bundle for
  one threat actor cluster, identified by that cluster's identifier, from the
  cluster's own detail screen, in a single action and without waiting for any
  scheduled or batch process.
- **FR-002**: The system MUST require the intelligence-export permission for this
  action. A principal holding only read access to intelligence MUST be refused, and
  MUST receive no part of the bundle.
- **FR-003**: The unattended feed principal — the static credential used by
  automated consumers — MUST NOT be able to obtain a bundle through this on-demand
  action, whatever its other privileges.
- **FR-004**: The system MUST reject a cluster identifier that is not well formed at
  the request boundary, before any cluster is looked up.
- **FR-005**: The produced artifact MUST be a valid STIX 2.1 bundle: a single bundle
  object carrying a bundle identifier and a list of objects, each object carrying a
  type and an identifier conforming to STIX 2.1.
- **FR-006**: The bundle MUST contain exactly one threat actor object, representing
  the exported cluster and carrying that cluster's identifier as its stable
  reference across exports.
- **FR-007**: The bundle MUST contain one indicator object for each exportable
  indicator attributed to the cluster, and one relationship object stating, for each
  of those indicators, that it indicates the exported actor's activity.
- **FR-008**: The bundle MUST carry the cluster's psychological profile when one has
  been generated, and MUST omit the profile entirely — rather than emit an empty or
  placeholder one — when none has.
- **FR-009**: The system MUST apply the platform's existing indicator export policy
  when selecting indicators for the bundle: indicators an analyst has marked as false
  positives are excluded, and financial indicators are excluded until an analyst has
  confirmed them. This feature MUST NOT introduce a second, different policy.
- **FR-010**: When the export policy excludes every indicator, the system MUST still
  produce a bundle containing the threat actor object, and MUST inform the analyst
  that indicators were withheld and on what grounds.
- **FR-011**: When the identifier is well formed but resolves to no cluster, the
  system MUST refuse the export with a not-found outcome, and no file MUST be
  written.
- **FR-012**: When the export fails for any reason — refusal, not found, malformed
  identifier, or an unexpected server error — the interface MUST show the analyst a
  message that distinguishes that reason from the others, MUST leave them on the
  cluster screen, and MUST NOT write a partial file.
- **FR-013**: The interface MUST show that an export is running, and MUST prevent a
  second export of the same cluster being started from the same screen while one is
  running.
- **FR-014**: The delivered file MUST be named deterministically from the cluster's
  identifier, so that two exports of two different clusters never collide in a
  download folder, and MUST be delivered with the STIX 2.1 media type.
- **FR-015**: The bundle MUST NOT contain raw conversation message text, the
  addresses or identities of the personas ScamBuster operates, or any credential or
  secret. It carries indicator values, the actor's profile and the attribution
  relationships between them, and nothing else.
- **FR-016**: Exporting MUST NOT modify any cluster, indicator, profile or
  conversation. Two exports separated by no other activity MUST produce the same
  intelligence content.
- **FR-017**: Every export attempt — successful, refused, or failed — MUST produce
  exactly one audit record naming the acting principal, the cluster identifier, the
  outcome, and the time of the attempt.
- **FR-018**: The audit record MUST NOT contain the bundle itself or any indicator
  value.

### Key Entities

- **Threat actor cluster**: the durable, multi-conversation identity being exported.
  Has an identifier stable across exports, a set of attributed conversations, and a
  set of attributed indicators.
- **Indicator**: an observable extracted from the actor's conversations — address,
  domain, account number, wallet, phone. Carries an analyst verdict that the export
  policy reads.
- **Psychological profile**: the actor's influence levers, behavioural narrative,
  escalation pattern and targeting. Optional: a cluster may have none.
- **Attribution relationship**: the statement that an indicator belongs to this
  actor. One per exported indicator.
- **Export policy**: the existing rules deciding which indicators may leave the
  platform. Owned elsewhere; this feature consumes it and does not extend it.
- **Audit record**: one per export attempt. Who, which cluster, what outcome, when.
- **STIX 2.1 bundle**: the delivered artifact. Self-contained: a consumer needs
  nothing else to ingest it.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: From the cluster's detail screen, an analyst obtains the bundle file in
  exactly one action, with no intermediate screen, form or confirmation step.
- **SC-002**: For a cluster carrying up to 500 attributed indicators, the file is
  delivered to the analyst within 5 seconds, measured at the 95th percentile over 20
  consecutive exports.
- **SC-003**: 100% of bundles produced by this feature pass validation against the
  published STIX 2.1 schema, measured over a corpus of at least 10 clusters spanning
  the cases in Edge Cases.
- **SC-004**: 100% of export attempts by a principal lacking the intelligence-export
  permission are refused, measured by one automated test per principal kind:
  administrator, analyst with the permission, analyst without it, and the unattended
  feed credential.
- **SC-005**: Each of the four failure modes in FR-011 and FR-012 produces a message
  the other three do not, and writes no file — one automated test per mode, four
  tests, all passing.
- **SC-006**: Over a run of 20 export attempts mixing successes and refusals, the
  audit log grows by exactly 20 entries.
- **SC-007**: A bundle produced from a cluster whose conversations contain persona
  addresses and raw message text contains zero occurrences of either, measured by
  searching the bundle for both.
- **SC-008**: Two consecutive exports of an unchanged cluster produce bundles that are
  identical except for their bundle identifier.

## Assumptions

- The cluster detail screen already exists and is where the analyst will be when they
  want this. The export is an action added to that screen, not a new screen.
- The intelligence-export permission already used by the platform's other export
  actions is the right one here. Cluster export is being aligned with them rather
  than given a permission of its own; the alternative — a distinct permission per
  export surface — would let an operator grant one and forget the other, which is the
  failure mode the alignment avoids.
- The indicator export policy already applied to the automated feed is the correct
  policy for an on-demand export too. An analyst pulling a bundle by hand is not a
  reason to release an indicator the feed would withhold.
- The audit facility used elsewhere in the platform records these attempts. No new
  audit mechanism is introduced.
- The 5-second budget in SC-002 assumes the analyst is on a normal office connection
  and the platform is under its usual load; it is a target for the platform's own
  work, not a guarantee about the analyst's network.
- Out of scope for this feature: exporting several clusters in one action, scheduling
  a recurring cluster export, changing what the automated feed publishes, and any
  format other than STIX 2.1.

## Dependencies

- Threat actor clusters must already be built and attributed; this feature reads them
  and creates none.
- An on-demand cluster export path already exists in the platform. This specification
  states the behaviour that path must have; where the existing behaviour already
  satisfies a requirement, that requirement is satisfied and nothing is rebuilt.
- The existing construction of STIX threat actor, indicator and relationship objects,
  as already published to automated consumers. This feature must produce the same
  objects for the same actor, so that a consumer receiving both an on-demand bundle
  and a feed poll sees one actor and not two.
- The existing indicator export policy and the analyst verdicts it reads.
