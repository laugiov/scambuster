# Post-Mortem Template (blameless)

Copy this file per incident to `docs/runbooks/post-mortems/YYYY-MM-DD-<slug>.md`.

---

## Incident: `<title>`
- **Date / duration:** `<start>` → `<end>` (`<hh:mm>`)
- **Severity:** SEV1 / SEV2 / SEV3
- **Incident Lead:** `<name>`
- **Status:** resolved / monitoring

## Summary
`<2–3 sentences: what happened, impact, resolution.>`

## Impact
- Systems / data affected: `<...>`
- Personal data involved? `<yes/no>` → if yes, Art 33/34 outcome: `<...>`
- Users / conversations / IOCs affected: `<...>`

## Timeline (UTC)
| Time | Event |
|------|-------|
| `hh:mm` | Detected via `<signal>` |
| `hh:mm` | Contained (`<action>`) |
| `hh:mm` | Resolved |

## Root cause
`<the technical + process cause — not "who". Use the 5-whys.>`

## What went well / what didn't
- Well: `<...>`
- Didn't: `<...>`

## Action items
| Action | Owner | Due | Type |
|--------|-------|-----|------|
| `<preventive fix>` | `<...>` | `<date>` | prevent / detect / mitigate |

## Follow-up
- Risk register updated? `<yes/no>`
- Runbook/IRP updated? `<yes/no>`
