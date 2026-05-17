# n8n Workflows — ScamBuster

This directory contains the versioned n8n workflow definitions for ScamBuster.

## Environment Variable Configuration

**Since v0.25**, workflows use environment variables instead of hardcoded values. Operators configure ScamBuster by editing `.env` — **never by editing workflow JSON files**.

| Variable | Purpose | Default | Required |
|----------|---------|---------|----------|
| `SCAMBUSTER_API_URL` | Backend API base URL (scheme://host:port) | `http://backend-dev:8080` | Yes |
| `INGEST_LOGIN` | n8n service account email | `user@example.com` | Yes |
| `INGEST_PASSWORD` | n8n service account password | `change-me` | Yes |

These variables are forwarded to the n8n container via `docker-compose.yml`. Workflows reference them as `$env.SCAMBUSTER_API_URL`, `$env.INGEST_LOGIN`, `$env.INGEST_PASSWORD`.

**Important**: `SCAMBUSTER_API_URL` must be scheme + host + port only (e.g., `http://backend-dev:8080`). No trailing slash, no `/api/v1` path — the path is appended by each workflow node.

---

## Available Workflows

| Workflow | Description |
|----------|-------------|
| WF-INTAKE-EMAIL-V2 | Email polling, parsing, POST to /ingest/raw, risk assessment, trigger reply + IOC extraction |
| WF-REPLY-GENERATE-V2 | LLM reply generation + validation via backend API |
| WF-REPLY-SEND-v1 | Human-like delay + send approved replies + confirm sent |
| WF-EXTRACT-AND-ENRICH-IOC | IOC extraction (LLM) + URLScan + VirusTotal enrichment |
| Gmail Scam Simulator - INIT | (Testing only) Generate fake phishing emails |
| Gmail Scam Simulator - REPLY | (Testing only) Auto-reply to advance test conversations |

### Cross-Workflow Dependencies

```
WF-INTAKE-EMAIL-V2
  ├── calls → WF-REPLY-GENERATE-V2 (by name)
  │              └── calls → WF-REPLY-SEND-v1 (by name)
  └── calls → WF-EXTRACT-AND-ENRICH-IOC (by name)
```

### Risk-Gated Reply Generation (Decision Gate)

`WF-INTAKE-EMAIL-V2` includes a `Decision Gate` node (n8n If v2) placed
between `Get Risk Assessment` and `Prepare Reply Data`. It reads the
backend's reply recommendation and routes accordingly:

```
Get Risk Assessment  →  Decision Gate
   GET /message/{msg_id}/risk          ├── true  → Prepare Reply Data → Trigger Reply Generation
   returns { should_reply: bool }      └── false → Skip Reply → Continue Loop
```

The condition is **already configured in the shipped JSON** as:

```
value1   =  {{ $('Get Risk Assessment').item.json.should_reply }}
operator =  is equal to
value2   =  true   (boolean)
```

Backend `RiskScorer::shouldReply` returns `false` for: no IOCs detected
(DMARC reports, GitHub system notifications, postmaster/abuse mail),
low aggregate risk score (< 40), or medium risk without exploitable
IOCs (no IBAN / phone / URL). When duplicating the workflow per honeypot
account (e.g., `WF-INTAKE-EMAIL-aldridgecounsel`), verify that the
Decision Gate config survives the copy — if a UI clone resets the
condition to `true == true`, every email will trigger a reply.

Workflows reference each other **by name** (not by ID). This means they work on any n8n instance after import — no manual ID patching required.

---

## Importing Workflows

### Automatic (recommended — requires spec 027)

Workflows are auto-imported on first `docker compose up` via the init script. No manual steps needed.

### Manual

1. Open n8n at http://localhost:5678
2. Click **"Add workflow"** > menu > **"Import from File"**
3. Import each JSON from `n8n/workflows/`
4. Activate the 4 production workflows (not the simulators)

---

## Backup

Original workflow files (before env var migration) are preserved in `n8n/workflows-backup-pre-025/`. To restore:

```bash
cp n8n/workflows-backup-pre-025/*.json n8n/workflows/
# Then re-import in n8n or restart the container
```

---

## Credentials

Workflows authenticate against the backend using `$env.INGEST_LOGIN` / `$env.INGEST_PASSWORD`. No credentials are stored in the workflow JSON files.

For email (IMAP/SMTP), see `docs/17_email_provider_setup.md` (spec 026).

---

## Troubleshooting

### "undefined/api/v1/..." errors in execution logs
`SCAMBUSTER_API_URL` is not set in the n8n container. Check `docker-compose.yml` forwards the variable and `.env` defines it.

### Authentication 401 errors
`INGEST_LOGIN` or `INGEST_PASSWORD` is not set or incorrect. Verify in `.env` and test with `curl`:
```bash
curl -X POST http://localhost:8081/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"your-login","password":"your-password"}'
```

### Workflow cross-calls fail ("Workflow not found")
Target workflows must be **imported AND activated** in n8n. Check with `n8n list:workflow` or the n8n UI.
