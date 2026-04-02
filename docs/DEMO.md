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

The demo includes 150 pre-loaded conversations, 876 IOCs, 5 campaigns, pipeline monitoring, convergence history, and full analytics.

## What works in demo mode

| Screen | Status | Description |
|--------|--------|-------------|
| Dashboard | Full | 150 conversations, IOC stats, active conversations, bandit performance |
| Conversations | Full | Browse conversations, view message threads, IOCs per conversation |
| IOC Explorer | Full | 876 IOCs across 8 types, search, filter, Intelligence Profile |
| STIX Export | Full | Export STIX bundles from closed conversations |
| Personas > Performance | Full | 27 personas with pull stats and convergence |
| Personas > Convergence | Full | 218 convergence snapshots across 8 weeks |
| Monitoring > Conversations | Full | Active conversations by scam type, timeout tracking |
| Monitoring > Pipeline | Full | Pipeline execution traces with component waterfall |
| Monitoring > Injection | Full | Prompt injection detection (15 alerts, 4 HIGH) |
| Monitoring > LLM Costs | Full | Cost breakdown by purpose |
| Analytics | Full | 8 charts with 8-week trends |
| Campaigns | Full | 5 detected campaigns (2 promoted, 3 shadow) |

## What does NOT work in demo mode

- **Email ingestion**: No IMAP polling (n8n is not running)
- **LLM reply generation**: `LLM_PROVIDER=mock` — no real API calls
- **Email sending**: `MAILER_DSN=null://null` — no SMTP
- **n8n workflows**: n8n is not included in the demo stack

## Useful commands

```bash
make demo-up       # Start demo (build + run)
make demo-down     # Stop demo
make demo-reset    # Wipe DB + restart (full re-seed)
make demo-logs     # Follow container logs
```

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
5. Loads the demo dataset (150 conversations)
6. Shifts all dates to "now" (so the demo always looks fresh)

On subsequent starts, the entrypoint detects existing data, skips seeding, and only refreshes dates if they are more than 1 hour old.
