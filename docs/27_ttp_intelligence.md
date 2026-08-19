# Scammer TTP Intelligence

How ScamBuster tags the **scammer's tactics, techniques and procedures (TTPs)**, what it
stores, what it exports, and where the module's boundaries are. This is the architectural
description of the module; for the analyst-facing screens it produces, see
[Reading the TTP screens](26_reading_the_ttp_screens.md).

TTPs are tagged on inbound messages against a **closed, 27-entry taxonomy** spanning a
six-phase scam kill chain (hook, trust-building, payment-request, escalation,
channel-switch, exit). A stimulus is something **our persona** does; a TTP is something
**the scammer** does — the two are kept strictly separate, and the analytical value is the
crossing **stimulus → TTP → IOC**.

> **Note on metrics.** The TTP module was added **after** the nine-month production window
> covered by the white paper, so **no published metric applies to it**. Its extraction
> precision is to be established by the manual audit (`scambuster:ttp:audit-sample`); no
> precision figure is claimed until that audit exists.

## Extraction

**Inbound-only LLM tagging** — our own replies are never analysed. Each observation carries
a confidence, a `confirmed` / `review` status (below a configurable threshold, nothing is
silently dropped), a verbatim evidence quote (**stored internally only**, never in any API
response or export — consumers see character offsets), and model/prompt provenance.

## Read APIs

`GET /conversations/{id}/ttps`, `/clusters/{id}/ttps`, `/ttps`, `/ttps/cluster-matrix`,
`/ttps/{code}/iocs`, `/iocs/{id}/ttps`; a manual
`POST /communication/message/{msgId}/extract-ttps` remains as an ops/test surface. Full
signatures in the [API quick reference](12_api_quick_reference.md#ttp-intelligence).

## Analyst UI

A cluster TTP panel, a per-conversation stimulus → TTP → IOC elicitation timeline with
neutral stimulus/causality chips, and a tabbed **TTP Explorer** (taxonomy with per-TTP
detail pages, phase analytics with an 8-week trend, the cluster-overlap playbook matrix,
and a read-only review queue with on-demand, masked-by-default evidence). Each surface is
walked through in [Reading the TTP screens](26_reading_the_ttp_screens.md).

## CTI export

One stable STIX 2.1 attack-pattern per TTP (with `kill_chain_phases`),
`threat-actor uses attack-pattern` relationships and sightings from cluster aggregates, and
`scambuster:ttp` + MITRE ATT&CK galaxy MISP tags. Evidence text is never exported. The
taxonomy's own standards status is recorded in [standards-track.md](standards-track.md).

## Operator tooling

`scambuster:ttp:backfill` (historical extraction, preview by default, budget-capped,
idempotent) and `scambuster:ttp:audit-sample` (random-sample CSV for a manual precision
audit — the only path by which evidence text leaves the database).

## Feature flag and failure mode

The whole module sits behind the **`TTP_EXTRACTION_ENABLED`** feature flag (default on) and
fails safe: disabled or failing, it never affects ingestion, IOC extraction or replies.

---

See also: [Reading the TTP screens](26_reading_the_ttp_screens.md) ·
[Threat-Actor Profiling](21_threat_actor_profiling.md) ·
[TAXII Server](16_taxii_server.md) · [MISP Integration](13_misp_integration.md)
