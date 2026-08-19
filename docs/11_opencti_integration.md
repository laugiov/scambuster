# OpenCTI Integration

> Feed a running OpenCTI platform from ScamBuster's TAXII server: IOCs with the
> story of how each was elicited, scammer TTPs on a scam kill chain, threat-actor
> clusters with their psychological profile, and sightings.

The transport is the [TAXII 2.1 server](16_taxii_server.md) -- that page is the
protocol reference. This one is the integration guide: what to configure, what
lands where in OpenCTI, and the traps.

---

## Before you start

- A running OpenCTI (validated against **7.260728.0**).
- ScamBuster reachable from the OpenCTI **container**, not just from your browser
  -- see [Networking](#networking-opencti-in-docker) below.
- `TAXII_API_KEY` set in ScamBuster's `.env`. **A JWT will not do**: it expires
  after 900 seconds, after which every poll returns `401`. What was already
  imported stays in the platform, so the feed looks populated while it has in
  fact stopped -- the failure is only visible in the OpenCTI logs.

```bash
# in ScamBuster's .env, then restart the backend
TAXII_API_KEY=$(openssl rand -hex 32)
```

---

## Create two feeds, not one

| Feed | Collection | Carries |
|---|---|---|
| ScamBuster IOCs | `a1b2c3d4-0001-4000-8000-000000000001` | indicators with the elicitation story, per-conversation threat-actors, their MITRE attack-patterns, `indicates` relationships |
| ScamBuster Threat Actors & TTPs | `a1b2c3d4-0003-4000-8000-000000000003` | consolidated actor clusters, psychological profiles, scam-phase TTPs, sightings |

The two overlap in object *types* but not in content: the scam-phase TTPs, the
sightings and the Cialdini profiles exist **only** in the second collection.
Wiring the IOC feed alone leaves the TTP and actor-profile screens empty.

In OpenCTI: **Data** → **Ingestion** → **TAXII Feeds** → **Add TAXII Feed**, once
per row above:

- **TAXII Server URL**: `http://<scambuster-host>:8081/api/v1/taxii2/api/`
  (the API root -- OpenCTI appends `collections/{id}/objects/` itself)
- **Version**: `2.1`
- **Collection**: the id from the table
- **Authentication type**: `basic`, value `taxii:<your TAXII_API_KEY>`
  (any username; the key goes in the password field)
- **Polling interval**: 1 hour is plenty. OpenCTI keeps a watermark per feed
  (`added_after_start`) and only asks for what changed since; within one batch it
  follows the server's `next` cursor. Nothing is re-imported wholesale.

---

## Networking: OpenCTI in Docker

If OpenCTI runs in Docker on the same host, a `localhost` URL points at the
OpenCTI container itself, and **`host.docker.internal` does not resolve on Linux**
unless you add it explicitly. Two options:

**Shared Docker network (recommended)** -- the containers then talk directly, no
host ports involved:

```bash
docker network connect scambuster_scambuster <opencti-container>
```

and use the internal URL, which is the container port, not the published one:

```
http://backend-dev:8080/api/v1/taxii2/api/
```

Make it survive a redeploy by declaring `scambuster_scambuster` as an external
network in your OpenCTI compose file.

**Host gateway** -- keep the published port and target the bridge gateway address
(`docker network inspect <network>` → `IPAM.Config[].Gateway`, typically
`172.x.0.1`), e.g. `http://172.20.0.1:8081/api/v1/taxii2/api/`.

Check before configuring the feed -- a wrong URL surfaces as a silent, permanently
failing ingestion:

```bash
docker exec <opencti-container> \
  wget -qO- http://backend-dev:8080/healthz   # expects {"status":"ok"}
```

---

## What OpenCTI keeps

OpenCTI persists the STIX properties it models and **drops custom extensions**.
ScamBuster's `x_scambuster_context`, `x_scambuster_actor` and
`x_scambuster_actor_psych` are therefore still emitted for consumers that
understand them, but everything that matters is mirrored onto standard fields:

| ScamBuster intelligence | Where it lands |
|---|---|
| Elicitation story (turn, stimulus, semantic role, urgency, PII-free excerpt) | Indicator **description** |
| Scam type, IOC role, stimulus, persona, analyst verdict | Indicator **labels** -- `scam-type:…`, `ioc-role:…`, `stimulus:…`, `persona:…`, `analyst:…` |
| ATT&CK technique, MISP taxonomy | Indicator **external references** |
| Scammer TTPs | **Attack patterns** with `scambuster-scam-phases` kill-chain phases |
| TTP frequency per actor | **Sightings** (count, first/last seen) |
| Cialdini psychological profile | Threat-actor **description** + `psych-lever:…` labels |
| Producer, sharing policy | **Author** = ScamBuster Threat Intelligence, **TLP marking** on every object |

One indicator off the feed, verbatim:

```
name        domain: support-urgenttransactions.com
description Revealed by the scammer at turn 7 of an INVOICE_FRAUD engagement.
            Elicited by a PAYMENT_INITIATION stimulus from persona "Librarian
            dreamer, florid language". Role in the scam narrative:
            INFRASTRUCTURE_DOMAIN. Urgency score 0.99. Context: Invoice fraud with
            fake overdue notice and legal threats to pressure immediate wire
            transfer. Extraction method: derived_from_email.
labels      malicious-activity · scambuster · scam-type:invoice_fraud ·
            ioc-role:infrastructure_domain · stimulus:payment_initiation ·
            persona:hopeless_romantic
author      ScamBuster Threat Intelligence
marking     TLP:AMBER
references  mitre-attack T1656 · misp-taxonomy rsit:fraud="fraud"
```

That description is the whole point of the integration: without the mirror onto
standard fields, OpenCTI would store this domain with a null description and a
single `malicious-activity` label.

### TTPs graft onto MITRE ATT&CK

ScamBuster's TTP taxonomy is a six-phase scam kill chain
(`hook`, `trust-building`, `payment-request`, `escalation`, `channel-switch`,
`exit`). A TTP that also maps to a MITRE technique is **merged by OpenCTI into
the existing MITRE attack-pattern**: the MITRE name wins, the ScamBuster name is
kept as an alias, and the entity ends up carrying *both* kill chains. Verified on
this install:

```
name        Impersonation                    (MITRE T1656)
aliases     Commercial brand impersonation   (the ScamBuster TTP)
author      ScamBuster Threat Intelligence
kill chains mitre-attack / defense-evasion
            scambuster-scam-phases / hook
```

Nothing is lost in the merge -- an analyst pivoting from ATT&CK finds the scam
phase, and one pivoting from the scam phase finds the ATT&CK technique.

---

## Verify the ingestion

The feed executes within a minute of being saved. Objects then flow through the
OpenCTI workers, so counts climb for a few minutes after the first poll.

```bash
# Feed state — last_execution_date must advance, and no 401 in the logs
docker logs <opencti-container> --since 10m 2>&1 | grep -iE "taxii|ingestion" | tail
```

For reference, here is what one install produced -- the seeded demo dataset plus a
single live-captured conversation, both feeds running. Your numbers will differ;
the point is that all four rows are non-zero:

| | Observed |
|---|---|
| Indicators authored by ScamBuster | 1258 |
| Attack patterns carrying `scambuster-scam-phases` | 24 |
| Sightings | 28 |
| Threat actors authored by ScamBuster | 46 |

A zero in the second or third row means the `…0003` feed is missing or failing.

---

## Known behaviours

**Indicators arrive without observables.** OpenCTI creates Indicators from the
feed but no Cyber Observables, so connectors that enrich observables
(VirusTotal, AbuseIPDB, Shodan) have nothing to act on until observables exist.
Turning that on is an OpenCTI-side decision -- check your platform's rules engine
and ingestion settings for the current release.

**IOC types with no STIX equivalent stay opaque.** Message-IDs, Telegram handles,
postal addresses and similar are emitted as `[x-scambuster:value = '…']`. They are
searchable and correlate with each other, but nothing will enrich them. Standard
types (`domain-name`, `email-addr`, `url`, `ipv4-addr`, `user-account`) map
normally, and OpenCTI recognises phone numbers, IBANs and crypto wallets as its
own extended pattern types (`x-opencti-phone-number`, `x-opencti-bank-account`,
`x-opencti-cryptocurrency-wallet`).

**Attribution depends on the identity travelling with the objects.** ScamBuster
ships the `identity` and `marking-definition` SDOs inside every unfiltered TAXII
envelope, so `created_by_ref` and `object_marking_refs` resolve on a fresh
platform. A response filtered with `?type=…` contains only the requested type, by
design -- do not build an ingestion on a type-filtered URL.

**Rotating `TAXII_API_KEY` breaks every consumer at once.** There is no overlap
window: change the value, restart the backend, then update each feed's
authentication. Expect a few failed polls in between.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| Ingestion runs once, then every poll returns `401` | The feed is configured with a JWT (900s lifetime). Use `TAXII_API_KEY` over HTTP Basic. |
| `Feed fetch failed` from the first poll | The URL is unreachable *from the container*. Test with the `wget` above. |
| Feed green, but no TTP or actor in OpenCTI | Only the IOC collection is wired. Add the `…0003` feed. |
| Indicators arrive with no description or labels | ScamBuster predates the interoperability mirror -- update, or check the objects on the wire with the API-root URL above. |
| `Failed to patch taxii ingestion success status` in the OpenCTI logs | An OpenCTI-side warning on its own `last_execution_status` field (seen on 7.260728.0). Harmless, unrelated to ScamBuster. |

---

## Related

- [TAXII 2.1 Server](16_taxii_server.md) -- protocol, collections, pagination, credentials
- [MISP Integration](13_misp_integration.md) -- the other CTI path, per-conversation Event export
- [Threat-Actor Profiling](21_threat_actor_profiling.md) -- what the psychological profile means
- [Reading the TTP screens](26_reading_the_ttp_screens.md) -- the same TTP data inside ScamBuster
