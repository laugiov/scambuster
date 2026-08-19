# Threat-Actor Psychological Profiling

ScamBuster builds a durable **psychological + behavioural fingerprint** for each
threat actor it tracks, aggregated across all of that actor's conversations. It
answers, per actor: *how does this scammer manipulate, and who do they target?*

This is first-party intelligence derived from the actor's own messages -- not
external reputation enrichment (that belongs in OpenCTI / MISP).

## What a profile contains

| Field | Meaning |
|-------|---------|
| **Dominant Cialdini lever** | The actor's primary influence principle -- one of Authority, Urgency, Scarcity, Secrecy, Reciprocity, Liking, SocialProof (or None). |
| **Secondary levers** | Up to three other principles they also use. |
| **Behavioural summary** | A 2–3 sentence narrative of how they manipulate. |
| **Escalation pattern** | How their pressure evolves across turns (rapid / gradual / stable / erratic). |
| **Victim targeting** | One sentence on who they prey on. |
| **Behavioural signals** | Measured from `ioc_context`: dominant psychological stimulus, average urgency, hesitation events, language switches. |
| **Provenance** | Generating model, prompt version, timestamp. |

The **Cialdini vocabulary** is deliberately the same closed set the live
per-turn analysis uses (`ConversationAnalyzer` RULE #7), so the persisted profile
speaks the same language as the reply-time "Cialdini mirror".

## How it's generated

The profile is generated **offline** -- it never touches reply generation, so
there is zero risk to production scambaiting behaviour.

1. **Actor identity** = an IOC cluster (`threat_actor_cluster`), the same durable,
   multi-conversation identity the STIX threat-actor SDO is built from. Actors that
   are not yet clustered (singleton conversations) carry no persisted profile.
2. For each cluster, `ThreatActorPsychProfileGenerator` gathers the actor's inbound
   (scammer) messages plus the already-persisted `ioc_context` behavioural aggregate
   (`ClusterQueryService::getBehavioralProfile`).
3. A single LLM call returns the dominant lever + secondary levers + behavioural
   narrative + escalation pattern + victim targeting. Output is strictly validated
   against the lever/pattern vocabularies before it is trusted.
4. The result is upserted (one row per cluster) into `threat_actor_psych_profile`.

The generator is **fail-safe** (any error writes nothing and returns null -- the
profile simply stays "pending") and **idempotent** (re-running skips clusters that
already have a profile unless `--force` is given).

## Where it appears

- **UI** -- a "Psychological Profile" panel at the top of the **Cluster Detail**
  page (dominant lever badge, behavioural narrative, escalation, targeting, and the
  behavioural signals). Shows an empty state until a profile has been generated. For a
  full walkthrough of that screen (this panel plus the Activity Pattern and Abuse Report
  panels), with demo talking points, see
  [Reading the Threat-Actor screen](23_reading_the_threat_actor_screen.md).
- **API** -- `GET /api/v1/clusters/{clusterId}/psych-profile` (requires `ioc:read`);
  returns the profile JSON or `404` when none exists yet.
- **STIX export** -- a clustered threat-actor SDO carries the profile as an
  `x_scambuster_actor_psych` custom extension (schema_version 1.0), alongside the
  existing `x_scambuster_actor` extension (engagement metrics), for downstream CTI
  (OpenCTI / MISP). The same SDO carries MITRE ATT&CK technique mapping and `indicates`
  relationships to every IOC of the conversation, and the bundle is validated for import
  into **OpenCTI** -- see the [OpenCTI Integration guide](11_opencti_integration.md).

## Running it

```bash
# Profile every cluster that doesn't have a profile yet (idempotent):
php bin/console app:actor:compute-psych-profiles

# Options:
#   --cluster=<uuid>   profile a single cluster
#   --force            re-generate clusters that already have a profile
#   --budget-usd=2.00  cumulative LLM cost cap (default 2.00)
#   --dry-run          list clusters that would be processed, no LLM call
```

### Scheduling

The command is wired into the container scheduler
([`infra/docker/backend/scheduler.sh`](../infra/docker/backend/scheduler.sh)) to run
**daily at ~06:00 UTC** (after clustering has had the day to form clusters), with a
`--budget-usd=1.00` cap. Because it is idempotent, the daily run only profiles new
clusters.

## Cost

One LLM call per cluster on `gpt-4o-mini` (~$0.002 each), bounded by `--budget-usd`.
The daily scheduled run only touches new clusters, so steady-state cost is a few
cents per month.

## Part of the threat-actor intelligence stack

The psychological profile is one of four related, first-party (no external enrichment)
threat-actor intelligence capabilities:

- **Fuzzy actor clustering** -- actors (`threat_actor_cluster`) are formed by linking
  conversations that share an anchor IOC. Matching is on the *canonical* value, so
  formatting variants collapse (ETH wallet case; IBAN / card / phone separators) while
  genuinely different values (e.g. two IBANs one digit apart) stay separate. It is
  deliberately formatting-equivalence, **not** edit-distance -- a false merge of two
  actors is treated as worse than a missed link.
- **Analyst feedback loop** -- an analyst marks an IOC confirmed / false-positive
  (`POST /api/v1/iocs/{indicatorId}/feedback`, permission `ioc:feedback`, audited). The
  verdict overrides export confidence: confirmed pins it high, false-positive drops it
  near zero, so downstream CTI deprioritises rejected IOCs. See the
  [API reference](12_api_quick_reference.md#threat-actor-intelligence).
- **Explicit STIX evidence** -- the export makes the "seen N times" signal a first-class
  STIX `sighting` SDO on each indicator (count, first and last seen, where-sighted), and
  standard-observable IOCs also emit `observed-data` + a Cyber Observable Object. The actor psychological profile rides along as the
  `x_scambuster_actor_psych` extension on the clustered threat-actor SDO. See the
  [TAXII / STIX guide](16_taxii_server.md).
- **Temporal analysis** -- `GET /api/v1/clusters/{id}/temporal` surfaces *when* an actor is
  active: the activity window, hour-of-day and day-of-week cadence, the busiest day, burst
  days (a day at ≥ 2× the actor's median daily volume, floored at 3 messages), and the
  longest dormancy gap. Computed on-read from the actor's inbound messages -- no external
  data, no reply-path touch -- so it is always current and complements the *how* (psychological
  profile) and *who* (clustering) with a *when*.
- **Abuse / takedown report** -- `GET /api/v1/clusters/{id}/abuse-report` is the capstone that
  weaves all of the above into one actionable artifact: the actor identity, the actionable
  indicators (each routed to the *standard* abuse desk for its type -- IBAN → bank, wallet →
  exchange, domain → registrar, phone → carrier, …), the temporal activity and the psychological
  summary, plus a ready-to-send plain-text body. It is strictly factual -- first-party observed
  data with an explicit provenance disclaimer, no external-reputation claim, no fabricated
  attribution.
