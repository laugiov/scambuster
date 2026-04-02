# ScamBuster — Demo Mode

Try ScamBuster without any API key, email account, or configuration.

## Quick start (local)

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

On first start, the backend entrypoint:
1. Waits for PostgreSQL
2. Runs database migrations
3. Generates JWT keys
4. Loads fixtures (personas, scam types, users)
5. Loads the demo dataset (`scambuster-dataset-sample.json`: 150 conversations)

On subsequent starts, the entrypoint detects existing data and skips seeding.

## Deploy on Railway (online demo)

### Prerequisites

- A [Railway](https://railway.app) account (free tier: $5/month credit)
- The ScamBuster repository connected to Railway

### One-time setup

1. **Create a Railway project**
   - Go to https://railway.app/new
   - Select "Deploy from GitHub repo"
   - Choose `laugiov/scambuster`

2. **Add database services**
   - In the project, click "+ New" → "Database" → "PostgreSQL"
   - Click "+ New" → "Database" → "Redis"

3. **Add the backend service**
   - Click "+ New" → "GitHub Repo" → select the repo
   - Settings → Source:
     - Root Directory: `/`
     - Dockerfile Path: `infra/docker/demo/Dockerfile.backend`
     - Branch: `demo`
   - Link the PostgreSQL and Redis services (Railway auto-injects `DATABASE_URL`, `REDIS_URL`)
   - Add environment variables (see below)

4. **Add the frontend service**
   - Click "+ New" → "GitHub Repo" → select the repo
   - Settings → Source:
     - Root Directory: `/`
     - Dockerfile Path: `infra/docker/demo/Dockerfile.frontend`
     - Branch: `demo`
   - Add variable: `BACKEND_URL=http://<backend-internal-hostname>:8080`
     (Railway provides the internal hostname in the service settings)

5. **Set backend environment variables**

   ```
   APP_ENV=dev
   APP_SECRET=<generate: openssl rand -hex 32>
   JWT_PASSPHRASE=<generate: openssl rand -hex 16>
   LLM_PROVIDER=mock
   LLM_MODEL=mock
   LLM_API_KEY=not-needed
   LLM_API_URL=https://api.openai.com/v1
   LLM_VALIDATION_ENABLED=true
   MAILER_DSN=null://null
   SCAMBUSTER_KILL_SWITCH=false
   SCAMBUSTER_SAFE_DOMAINS=*
   LOGIN_HASH_SALT=<generate: openssl rand -hex 16>
   VAULT_ADDR=http://localhost:8200
   VAULT_TOKEN=root
   VAULT_IMAP_PATH=secret/scambuster/imap/
   LOCK_DSN=redis://redis:6379
   PROMPT_INJECTION_ENABLED=false
   PROMPT_INJECTION_MODEL=gpt-4o-mini
   PROMPT_INJECTION_TEMPERATURE=0.2
   STIX_EXPORT_PATH=/tmp/stix-demo
   SCORE_RISK_MIN=30
   CAMPAIGN_PROMOTION_PPV_THRESHOLD=0.85
   CAMPAIGN_PROMOTION_MIN_HITS=5
   CAMPAIGN_PROMOTION_MIN_LEAD_TIME_SEC=10800
   REPLY_MIN_WORDS=50
   REPLY_MAX_WORDS=150
   REPLY_MAX_LINKS=1
   REPLY_HISTORY_LAST_N=5
   CONVERSATION_HISTORY_EXCLUDED_EMAILS=noreply@example.com
   SIEM_PROVIDER=none
   INGEST_LOGIN=user@example.com
   INGEST_PASSWORD=Un1que$$trongPassword2024
   APP_API_BASE_URL=http://localhost:8080
   N8N_ENCRYPTION_KEY=demo-n8n-key
   ```

   Note: `DATABASE_URL` and `REDIS_URL` are auto-injected by Railway when you link the database plugins. Do NOT set them manually.

6. **Generate the public URL**
   - In the frontend service → Settings → Networking → Generate Domain
   - This gives you a URL like `scambuster-demo.up.railway.app`

### Updating the demo

```bash
# On your local machine:
git checkout demo
git merge main
git push origin demo
# → Railway auto-deploys in ~2-3 minutes
```

The database is preserved across deploys. Only schema migrations run automatically.

### Resetting demo data

In the Railway backend service:
1. Add variable `DEMO_FORCE_RESEED=true`
2. Redeploy (Railway → service → Redeploy)
3. Remove the variable after deploy completes

### Estimated cost

With Railway's hobby plan ($5/month):
- 4 lightweight containers ≈ $3-5/month
- Within free tier credit for the first months
