# MISP Round-Trip Procedure

**Cadence**: before a release, and after any change to the MISP export or the tag
provider
**Result goes to**: `docs/standards/interoperability-conformance.md` §3, with a date

This is a manual procedure rather than a CI job, and that is a decision rather than
an omission. A MISP service container in CI means a multi-hundred-megabyte image and
a multi-minute boot on every pull request, to check something that changes only when
the export format changes. A release gate matches the actual rate of change.

The trade is honest as long as the result is recorded. An unrun procedure proves
nothing, so until §3 of the conformance statement carries a date, the dedup claim
stays in "stated, not yet proven".

---

## What is being proven

Three things, in one sitting:

1. A ScamBuster event imports into a stock MISP instance without errors.
2. `scambuster:ttp="SB-Txxx"` tags arrive as machine tags — MISP parses them as
   `namespace:predicate="value"`, not as free-text labels.
3. Importing the same event a second time creates no duplicate attributes.

The third is the one that matters. Deduplication is what makes a feed safe to poll
on a schedule, and it is the property a consumer cannot verify from documentation.

---

## Setup

A disposable local instance. Do not run this against a shared or production MISP.

```bash
docker run -d --name misp-roundtrip \
  -p 8443:443 \
  -e "BASE_URL=https://localhost:8443" \
  ghcr.io/misp/misp-docker/misp-core:latest

# MISP takes a few minutes to finish its first boot.
docker logs -f misp-roundtrip
```

Log in, create an automation key under **Administration → List Auth Keys**, and note
it as `MISP_KEY`.

---

## Step 1 — export an event

The MISP event export is an API endpoint, not a console command
(`scambuster:misp:test` only checks connectivity to a MISP instance). Pick a
conversation that carries confirmed TTPs, or the tags this procedure exists to check
will not be in the event.

```bash
TOKEN=$(curl -sS -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"<admin email>","password":"<password>"}' | jq -r '.access_token')

curl -sS "http://localhost:8081/api/v1/conversations/<conv_id>/export/misp" \
  -H "Authorization: Bearer $TOKEN" > /tmp/scambuster-event.json
```

See `docs/13_misp_integration.md` for the full export documentation.

Check before going further:

- The event has attributes.
- It carries at least two `scambuster:ttp="SB-Txxx"` tags.
- **It contains no verbatim scammer text.** That rule has no exception, and MISP is
  an export path like any other. If evidence text appears here, stop the
  procedure and fix the export — that is a more serious finding than anything else
  this round-trip could produce.

---

## Step 2 — first import

```bash
curl -sk -X POST "https://localhost:8443/events/add" \
  -H "Authorization: $MISP_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d @/tmp/scambuster-event.json | tee /tmp/import-1.json
```

Record the event id it returns.

---

## Step 3 — check tag resolution

In the MISP UI, open the event and look at its tags.

| What you see | What it means |
|--------------|---------------|
| `scambuster:ttp="SB-T001"` rendered as a machine tag (namespace, predicate and value as distinct parts) | The tag is well-formed. This is the expected result today. |
| The tag shown but with no description on hover | Expected. The `scambuster` namespace is not registered in the MISP taxonomies repository yet, so nothing resolves it to a definition. Registering it is gated: see docs/standards-track.md. |
| The tag rendered as one opaque free-text label | A defect. The tag string is malformed — record it and fix the provider. |

Record which of the three you got.

---

## Step 4 — re-import the same event

```bash
curl -sk -X POST "https://localhost:8443/events/add" \
  -H "Authorization: $MISP_KEY" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d @/tmp/scambuster-event.json | tee /tmp/import-2.json
```

Then compare attribute counts:

```bash
curl -sk "https://localhost:8443/events/view/<event_id>" \
  -H "Authorization: $MISP_KEY" -H "Accept: application/json" \
  | python3 -c "import sys,json; e=json.load(sys.stdin)['Event']; print('attributes:', len(e.get('Attribute', [])), 'tags:', len(e.get('Tag', [])))"
```

**Pass**: the attribute count is unchanged from after step 2.

**Fail**: the count grew. Record by how much and which attribute types duplicated —
that is the finding, and it means the deterministic-id property does not survive the
MISP import path even though it holds inside STIX.

---

## Step 5 — record the result

Add a row to `docs/standards/interoperability-conformance.md` §3 with:

- the date,
- the MISP version tested against (from **Administration → Diagnostics**),
- the ScamBuster commit,
- attribute counts after the first and second import,
- what step 3 showed.

Then move the dedup claim out of §4 and into §3 — or leave it in §4 and record what
failed. Both are acceptable outcomes of running this. Not running it is not.

---

## Cleanup

```bash
docker rm -f misp-roundtrip
```

The instance holds exported scam data. Remove it rather than leaving it running on a
laptop.
