# Reading the Threat-Actor screen

A field guide to the **Cluster Detail** page (`Clusters → pick a cluster`). It is the
single view where ScamBuster's first-party intelligence about one actor comes together:
**who** they are (clustering), **how** they manipulate (psychological profile), **when**
they operate (activity pattern), and **what to do** about them (abuse report).

> A *cluster* is a **threat actor**: a set of honeypot conversations linked together because
> they share an anchor indicator (the same IBAN, bank account, crypto wallet or phone). One
> cluster = one actor tracked across every conversation we had with them.

This guide explains each indicator, how to read it, and -- in the *Demo tip* boxes -- what to
say when you show it.

> **Page layout.** The Cluster Detail page is organized into four tabs, deep-linkable via
> a `?tab=` URL parameter (an unknown or missing value resolves to *Overview*, and the URL
> is left untouched): **Overview** (psychological profile + activity pattern, the sections
> below), **TTPs** (the cluster's TTP profile -- tactic frequencies, average confidence and the
> cross-message *"A → B"* tactic sequences described in
> [Reading the TTP screens](26_reading_the_ttp_screens.md)), **Indicators & Conversations**
> (kept on one tab because selecting an anchor IOC filters the conversation list below it),
> and **Campaigns & Abuse** (campaign excerpts + the takedown report). **Export STIX** stays
> a header action on every tab.

---

## 1. Header

`Name · date range · v<algorithm>` and the **STIX ID**. The name is auto-generated
(`ScamBuster Cluster #<hash>`); the date range is first-seen → last-seen across the cluster.
The STIX ID is the stable identifier this actor carries into OpenCTI / MISP.

**Export STIX** (top right) downloads the full actor bundle (indicators + sightings +
threat-actor SDO + psychological extension) for a downstream CTI platform.

---

## 2. Psychological Profile -- *how they manipulate*

A durable fingerprint aggregated across all the actor's messages, generated offline (no effect
on live scambaiting). See [Threat-Actor Profiling](21_threat_actor_profiling.md) for how it's built.

| Indicator | What it means | Why it matters |
|-----------|---------------|----------------|
| **Dominant lever** (big badge) | The actor's primary Cialdini influence principle: Authority, Urgency, Scarcity, Secrecy, Reciprocity, Liking, Social Proof (or None). | The single best summary of *how* they pressure a victim -- and what a warning to future victims should counter. |
| **Secondary levers** (chips) | The other principles they lean on. | Actors mixing Authority + Urgency + Secrecy are the classic BEC/CEO-fraud signature. |
| **Behavioural summary** | A 2–3 sentence narrative of their manipulation style. | The human-readable "MO" -- drops straight into a report or briefing. |
| **Escalation** | How pressure evolves across turns: rapid / gradual / stable / erratic. | *Rapid* escalation = high-pressure boiler-room; *stable* = patient, organised. |
| **Targets** | One line on who they prey on. | Who to warn; which department is exposed (e.g. "employees in financial roles"). |
| **Stimulus / Avg urgency / Hesitations / Lang switches** | Measured signals from the conversations: dominant psychological stimulus, mean urgency (0–1), number of hesitation moments, language switches. | Objective backing for the narrative. High avg-urgency + zero hesitation = a confident, scripted operator. |

**The 7 levers, in one line each:** *Authority* -- rank/titles/institutions · *Urgency* --
deadlines & last-chance framing · *Scarcity* -- rarity/limited availability · *Secrecy* -- "keep
this between us" · *Reciprocity* -- a "favour" that creates obligation · *Liking* -- flattery/fake
intimacy · *Social Proof* -- "others already paid".

> **Demo tip:** "ScamBuster doesn't just collect indicators -- it profiles the *human* behind
> them. This actor's dominant lever is **Secrecy**, reinforced with **Urgency**: they demand the
> transfer stay private and push for immediate action. That's a textbook CEO-fraud playbook, and
> we derived it purely from the scammer's own messages."

---

## 3. Activity Pattern -- *when they operate*

Computed on read from the actor's **inbound** (scammer→us) message timestamps. This is the
"tempo" of the actor.

| Indicator | How to read it |
|-----------|----------------|
| **Messages-by-hour chart** (24 bars, peak highlighted) | The actor's **daily rhythm**. Activity clustered into a ~9-hour band is a *working-hours* signature that hints at a single timezone / an office operation; a flat 24-hour spread suggests automation or a distributed team. |
| **Weekday strip** (7 bars, peak highlighted) | Which days they work. Mon–Fri concentration = a "business-hours" operation; weekend activity is common for romance/investment scams. |
| **Peak hour / Peak day** | The single busiest hour-of-day and weekday. |
| **Inbound / active days** (`96 · 27/53`) | Total scammer messages, and active-days out of the calendar span -- how *persistent* they were. |
| **Busiest day** | The single most active date (and its message count). |
| **Burst days** (orange ⚡) | Days at ≥ 2× the actor's median daily volume -- **campaign spikes**. Clustered burst days (e.g. a run in mid-June) often mark a coordinated push. |
| **Median gap** | Typical time between messages -- the reply cadence. Minutes = an engaged live operator; hours = batch/asynchronous. |
| **Longest gap** | The longest dormancy -- was the actor continuous, or did they go quiet and resurface? |

> **Demo tip:** "Look at the hour chart -- this actor works a tight 9-to-5 window, peaking at
> 16:00 on Wednesdays. Six burst days cluster in mid-June: that's a campaign push, not steady
> background noise. For attribution, that working-hours signature is a timezone fingerprint."

---

## 4. Anchor IOCs & Conversations -- *who they are*

**Anchor IOCs** are the shared financial/contact indicators (IBAN, bank account, wallet, phone)
that *define* the cluster -- the reason these conversations are one actor. Each shows its reach
(how many conversations it appears in) and a semantic role (e.g. *Payment Destination*).
**Conversations** lists every honeypot exchange in the cluster with its scam type and risk score.

> **Demo tip:** "These three separate conversations are one actor because they all funnel money
> to the **same bank account** -- that's the anchor. Exact-match would have missed a rotated
> account; our canonical matching still linked them."

---

## 5. Abuse / Takedown Report -- *what to do about it*

Assembled automatically the moment a cluster opens (it is DB-only -- no LLM, no click), a factual,
first-party report laid out for action:

- **Actor + evidence chips** -- name, sophistication, conversation / inbound-message / actionable-IOC
  counts, **criminal time wasted**, burst days. The at-a-glance case summary.
- **Criminal time wasted** (green ⏱ chip) -- the total time the actor was kept engaged on the honeypot,
  summed across the cluster's conversations (only genuine ≥ 2-turn exchanges count). The headline
  *impact* figure for this specific actor: time spent talking to the honeypot is time they did **not**
  spend on real victims. Same methodology as the platform-wide *Hours Wasted* metric, scoped to one
  actor; it also appears as a `Criminal time wasted: N hours` line in the downloadable report text.
- **Actionable indicators, routed** -- every actionable IOC with the **standard abuse desk** it
  should be reported to (IBAN → *issuing bank / financial-crime unit*, wallet → *exchange /
  blockchain analytics*, phone → *telecom carrier*, domain → *registrar*, …), colour-coded by
  family (financial / contact / infrastructure).
- **Full report text** (collapsible) -- the ready-to-send plain-text complaint, with **Download
  .txt**. First-party only, with an explicit provenance disclaimer -- no external-reputation claim.

It is strictly factual: only what the honeypot observed. It never fabricates attribution.

> **Demo tip:** "One click turns the intelligence into an action plan: here are the actor's
> indicators, each already routed to the desk that can act on it -- the bank account to the bank,
> the phone to the carrier. Download the text and it's ready to paste into an abuse complaint.
> This is the step most platforms leave to the analyst; we hand it over done."

---

## Where the same data goes (for the technical audience)

Every indicator on this screen is also available programmatically:

- `GET /api/v1/clusters/{id}/psych-profile` -- the psychological profile
- `GET /api/v1/clusters/{id}/temporal` -- the activity-pattern metrics
- `GET /api/v1/clusters/{id}/abuse-report` -- the abuse report (structured + `text`)
- `GET /api/v1/clusters/{id}/export/stix` -- the full STIX 2.1 actor bundle

See the [API quick reference](12_api_quick_reference.md#threat-actor-intelligence) and the
[TAXII / STIX guide](16_taxii_server.md).
