# ScamBuster — Security & Operations Monitoring

Turnkey Prometheus + Grafana stack that scrapes the backend's `/api/metrics`
endpoint and surfaces the security-relevant signals: **kill-switch state**,
**dependency health**, ingest liveness, and throughput.

This is **optional** — ScamBuster runs without it. Enable it when you want
alerting and dashboards for a production deployment.

## What it monitors

`/api/metrics` exposes (Prometheus text format, `text/plain; version=0.0.4`):

| Metric | Type | Meaning |
|--------|------|---------|
| `scambuster_kill_switch` | gauge | `1` = all outbound replies halted |
| `scambuster_health_check{service}` | gauge | `1` = dependency OK, `0` = down |
| `scambuster_conversations_total{status}` | gauge | conversations by status |
| `scambuster_messages_total{direction}` | gauge | messages in/out |
| `scambuster_iocs_total` / `scambuster_iocs_unique` | gauge | IOC volumes |
| `scambuster_convergence_ratio` | gauge | bandit convergence (0–1) |
| `scambuster_info{version}` | gauge | build version |

## Authentication (important)

`/api/metrics` is **admin-only** (`ROLE_ADMIN`) — it is not public. Prometheus
must present a valid admin JWT on every scrape.

1. Create a dedicated admin service account (do **not** reuse a human login).
2. Obtain a JWT for it (note the field is `access_token`, not `token`):
   ```bash
   curl -s -X POST http://<backend>/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"email":"metrics@your-org.example","password":"<secret>"}' \
     | jq -r .access_token > infra/monitoring/prometheus/scambuster-token
   chmod 600 infra/monitoring/prometheus/scambuster-token
   ```
   The file holds the **raw** JWT (no `Bearer ` prefix — Prometheus adds it via
   `authorization.type: Bearer`). It is git-ignored.
3. **Important — the access token is short-lived (`token_ttl: 900` = 15 minutes).**
   Prometheus re-reads `credentials_file` on every scrape, so refreshing the file
   in place is enough — no reload/SIGHUP needed. Re-run the command above from a
   cron/systemd-timer **more often than every 15 minutes** (e.g. every 10 min), or
   drive it from your secret manager. If the token goes stale the scrape 401s and
   the `ScamBusterMetricsUnreachable` alert fires (`up == 0`, which Prometheus
   synthesises regardless of auth). A 15-minute TTL makes a static, hand-placed
   token unsuitable for anything but a quick demo — automate the refresh.

## Run

```bash
# 1. point the scrape target at your backend
$EDITOR infra/monitoring/prometheus/prometheus.yml   # targets: ['backend:8080']

# 2. drop in the admin token (see above)

# 3. start
docker compose -f infra/monitoring/docker-compose.yml up -d
```

- Prometheus: http://localhost:9090 (check **Status → Targets** is UP)
- Grafana:    http://localhost:3003 (admin / `GRAFANA_ADMIN_PASSWORD`, default `changeme`)
  - The **ScamBuster → Security & Operations** dashboard is auto-provisioned.

If your backend runs in another compose project, attach both stacks to the same
Docker network (e.g. `docker network connect scambuster-net scambuster-prometheus`)
or scrape it over the host (`targets: ['host.docker.internal:8081']`).

## Alerts

Defined in [`prometheus/alert.rules.yml`](prometheus/alert.rules.yml):

| Alert | Severity | Fires when |
|-------|----------|-----------|
| `ScamBusterKillSwitchActive` | warning | kill switch active > 1m (confirm it was intended) |
| `ScamBusterDependencyDown` | critical | a dependency unhealthy > 2m |
| `ScamBusterMetricsUnreachable` | critical | scrape fails > 2m (backend down or token expired) |
| `ScamBusterIngestStalled` | warning | no inbound mail ingested in 6h |

Wire these to your notifier (Alertmanager, Grafana alerting, PagerDuty…). Routing
is deployment-specific and intentionally left to the operator. Incident handling
for these alerts is covered by the
[Incident Response Plan](../../docs/runbooks/incident-response-plan.md).

## Files

```
infra/monitoring/
├── docker-compose.yml                 # Prometheus + Grafana
├── prometheus/
│   ├── prometheus.yml                 # scrape config (admin bearer token)
│   ├── alert.rules.yml                # security & ops alerts
│   └── scambuster-token               # admin JWT (git-ignored, you create it)
└── grafana/
    ├── dashboards/scambuster-security.json
    └── provisioning/
        ├── datasources/prometheus.yml
        └── dashboards/scambuster.yml
```
