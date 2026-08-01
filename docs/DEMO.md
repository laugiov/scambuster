# ScamBuster — Demo Mode

Try ScamBuster without any API key, email account, or configuration.

## Quick start

```bash
git clone https://github.com/laugiov/scambuster.git
cd scambuster
make demo-up
```

Wait ~2 minutes for the first build, then open:

- **Dashboard**: http://localhost:3002
- **Login**: `user@example.com` / `Un1que$trongPassword2024`

The demo includes 36 pre-loaded conversations, 482 IOCs, pipeline monitoring, convergence history, and full analytics.

## What works in demo mode

| Screen | Status | Description |
|--------|--------|-------------|
| Dashboard | Full | 36 conversations, IOC stats, active conversations, bandit performance |
| Conversations | Full | Browse conversations, view message threads, IOCs per conversation |
| IOC Explorer | Full | 482 IOCs across 16 types, search, filter, Intelligence Profile |
| STIX Export | Full | Export STIX bundles from closed conversations |
| TTP Explorer | Full | Scammer tactics (TTPs) from demo-seeded observations: tabbed Explorer (taxonomy, phase analytics, cluster playbooks, read-only review queue), per-TTP detail pages, IOC ↔ TTP pivots, plus the per-conversation stimulus → TTP → IOC timeline and cluster TTP panel (seeded approximations, see note below) |
| Personas > Performance | Full | 27 personas (with performance stats for those that have accumulated sessions) |
| Personas > Convergence | Full | 84 convergence snapshots |
| Monitoring > Conversations | Full | Active conversations by scam type, timeout tracking |
| Monitoring > Pipeline | Full | Pipeline execution traces with component waterfall |
| Monitoring > Injection | Full | Prompt injection detection (15 alerts, 4 HIGH) |
| Monitoring > LLM Costs | Full | Cost breakdown by purpose |
| Analytics | Full | 8 charts with 8-week trends |
| Campaigns | Experimental | 4 detected campaigns (experimental, disconnected from automated flow) |

## What does NOT work in demo mode

- **Email ingestion**: No IMAP polling (n8n is not running)
- **LLM reply generation**: `LLM_PROVIDER=mock` — no real API calls
- **Email sending**: `MAILER_DSN=null://null` — no SMTP
- **n8n workflows**: n8n is not included in the demo stack

> **Want the full experience?** Follow the [Quickstart Guide](QUICKSTART.md) to deploy ScamBuster with live email ingestion, LLM-powered replies, and automated workflows.

## Useful commands

```bash
make demo-up       # Start demo (build + run)
make demo-down     # Stop demo
make demo-reset    # Wipe DB + restart (full re-seed)
make demo-logs     # Follow container logs
```

> The demo runs in its own Docker Compose project (`scambuster-demo`, declared in
> `docker-compose.demo.yml`) with its own containers and volume, so it never recreates the database
> or rebuilds the frontend image of a development stack you may have running from the main
> `docker-compose.yml`. Inspect it directly with `docker compose -f docker-compose.demo.yml ps`.
>
> The dashboard binds host port **3002**, the same port the development frontend uses. Stop your dev
> frontend before `make demo-up`, or run both at once by giving the demo a different port:
> `DEMO_FRONTEND_PORT=3003 make demo-up`.

## How it works

The demo uses 4 Docker containers:

```
frontend (nginx)  →  backend (PHP 8.3)  →  PostgreSQL 15
                                         →  Redis 7
```

On first start, the backend entrypoint automatically:
1. Waits for PostgreSQL
2. Runs database migrations
3. Generates JWT keys
4. Loads fixtures (personas, scam types, users)
5. Loads the demo dataset (36 conversations)
6. Shifts all dates to "now" (so the demo always looks fresh)
7. Seeds deterministic, plausible scammer-TTP observations from the demo message bodies (so the TTP intelligence surfaces render with data)

On subsequent starts, the entrypoint detects existing data, skips seeding, and only refreshes dates if they are more than 1 hour old.

> **Note on seeded TTP & stimulus data.** In production, scammer tactics (TTPs)
> are tagged by the LLM extractor and each IOC revelation's stimulus is classified
> by the LLM enricher. The demo runs `LLM_PROVIDER=mock` and has no API key, so
> both are seeded deterministically instead:
>
> - **TTP observations** are produced by phrase-matching the real demo message
>   bodies against a scam-type-aware tactic map. The candidate list is rotated by
>   message position so the per-message cap does not keep truncating to the same
>   few tactics, giving the taxonomy broad coverage. The stored evidence quotes
>   are genuine verbatim substrings of the demo messages with correct offsets.
> - **Stimulus types** (`ioc_context.stimulus_type`, used by the stimulus → TTP →
>   IOC timeline and the Stimulus × TTP matrix) vary per message along a plausible
>   per-scam-type arc keyed to the turn position — first-contact revelations are
>   `PASSIVE`, later ones progress through trust-building, requests, payment and
>   urgency — replacing the earlier one-value-per-scam-type placeholder. The
>   linked stimulus message (`stimulus_msg_id`) is the real preceding outbound.
>
> All of these rows are deterministic, seeded approximations — **not** real
> model output — and each is stamped so it stays distinguishable from real data:
> TTP observations carry `ttp_observation.extraction_model = demo-seed`, and the
> enriched IOC-context rows (semantic role, stimulus type, etc.) carry
> `ioc_context.enrichment_model = demo-seed`.
