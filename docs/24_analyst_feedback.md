# Analyst Feedback — making a human verdict count everywhere

ScamBuster extracts indicators (IOCs) automatically, with a confidence score derived from *how*
each one was found (header vs. regex vs. LLM) and how often it recurs. That machine confidence is a
good default, but an analyst who has actually looked at an IOC knows better. **Analyst feedback lets a
human overrule the machine — and that verdict then propagates to every place the IOC is published.**

> **Why it matters.** A single false-positive that leaks into a shared feed wastes every downstream
> consumer's time (and erodes trust in the source). A confirmed IOC, conversely, deserves to be
> actioned immediately. Feedback turns a one-off human judgement into an authoritative signal that
> follows the IOC into STIX, TAXII, MISP and the CSV/NDJSON feeds — no re-review needed.

---

## 1. The verdict

Two values, one per indicator (the latest verdict wins — it is an upsert, not a log):

| Verdict | Meaning | Effect on confidence |
|---------|---------|----------------------|
| `confirmed` | A real, analyst-vetted IOC. | Pinned **high** (never lowered below 0.99). |
| `false_positive` | A mis-extraction / benign value. | Dropped **near zero** (0.05). |

A verdict is authoritative: it *outranks* the computed confidence rather than nudging it.

---

## 2. Submitting a verdict

```
POST /api/v1/iocs/{indicatorId}/feedback
Authorization: Bearer <token>          # permission: ioc:feedback
Content-Type: application/json

{ "verdict": "false_positive", "note": "vendor's real domain, not the scammer's" }
```

- `verdict` — `confirmed` or `false_positive` (required).
- `note` — optional free text, kept for the audit trail (≤ 1000 chars).

| Response | When |
|----------|------|
| `200 { "indicator_id", "verdict" }` | Verdict recorded and propagated. |
| `404` | Unknown `indicatorId`. |
| `422` | `verdict` is not one of the two allowed values. |
| `400` | Body is not valid JSON. |

Every submission is written to the audit log as an `IOC_FEEDBACK` event (analyst identity, verdict, IP)
so the feedback itself is fully traceable.

---

## 3. Where the verdict shows up

Submitting a verdict does **not** just tag the IOC — it rewrites the persisted confidence of every
observation of that indicator, and each export surface honours it in the way that fits that surface:

| Surface | How a verdict appears |
|---------|-----------------------|
| **IOC confidence** (`observed_ioc.confidence_score`) | `confirmed` → ≥ 0.99 on every observation; `false_positive` → 0.05. This is the single source of truth the STIX exports read. |
| **STIX** (conversation, standalone IOC, and campaign bundles) | Indicator `confidence` becomes **99** (confirmed) or **5** (false-positive) — uniformly, across all three export paths. |
| **TAXII 2.1** (public feed) | The emitted `confidence` is folded through the same rule — a false-positive reads **5**, a confirmed IOC reads **99**. |
| **MISP** (conversation export) | `false_positive` sets the attribute's **`to_ids = false`** (so a downstream MISP consumer never auto-actions it) and adds a `scambuster:analyst-verdict="false_positive"` tag; `confirmed` sets **`to_ids = true`** + the matching tag. |
| **CSV / NDJSON** (flat feed) | A trailing **`analyst_verdict`** column carries `confirmed` / `false_positive` / empty — ready for grep, `jq`, or a spreadsheet filter. |
| **Abuse / takedown report** (`/clusters/{id}/abuse-report`) | The indicator is **omitted entirely**, not down-scored. This report is addressed to a bank or a national financial-crime unit and cannot be unsent, so a rejected indicator must not appear at all. |

An indicator with **no** verdict keeps its machine confidence everywhere, and the confidence-based
surfaces above behave exactly as before. Note the one place where absence of a verdict is itself
decisive: the **export hold** withholds a *financial* indicator until an analyst confirms it, on
every outgoing path — STIX, TAXII, the flat feeds and the abuse report alike. That is deliberate:
naming a bank account to its bank on unconfirmed evidence is the harm the hold exists to prevent.
The internal review screens are the exception and always show everything, held or rejected, since
that is where an analyst goes to see and revise their own verdicts.

Nothing in the reply/scambaiting path is touched.

---

## 4. Good to know

- **One verdict per indicator.** Re-submitting overrides the previous verdict (and re-applies the
  confidence). There is no "un-verdict" — a verdict is a deliberate, terminal statement; to reverse a
  mistake, submit the opposite verdict.
- **It spans conversations.** The same IOC seen in five different conversations shares one indicator, so
  a single verdict updates all five observations at once.
- **Deleting the indicator removes the verdict** (foreign-key cascade) — no orphaned feedback.
- **False-positive ≠ deleted.** The IOC stays in the dataset for provenance; it is simply marked
  low-confidence and non-actionable so consumers can filter it out.

---

## 5. Try it (demo)

1. Open a conversation, note an indicator you know is bogus (e.g. the honeypot's own address that slipped
   into extraction).
2. `POST …/iocs/{id}/feedback` with `{"verdict":"false_positive"}`.
3. Re-export the conversation as **STIX** or **MISP** (or pull the **CSV** feed): the same IOC now reads
   confidence **5** / `to_ids=false` / `analyst_verdict=false_positive`. One call, every surface updated.

> **Demo tip.** This is the human-in-the-loop story CTI teams ask for: "your pipeline is automated —
> can my analysts still correct it?" Yes, and the correction is authoritative and audited, not a local
> annotation that dies in one screen.

The feedback loop is one of the first-party (no external enrichment) capabilities of the
threat-actor intelligence stack, alongside fuzzy actor clustering, psychological profiling
and explicit STIX evidence — see
[Threat-Actor Profiling](21_threat_actor_profiling.md#part-of-the-threat-actor-intelligence-stack).

See also: [Reading the Threat-Actor screen](23_reading_the_threat_actor_screen.md) for the actor-level
view these indicators roll up into.
