# n8n Workflows - ScamBuster

This directory contains the versioned n8n workflow definitions for the ScamBuster project.

---

## Structure

```
n8n/
  workflows/          # Exported JSON workflow files
    *.json
  README.md           # This file
```

---

## Available Workflows

Workflows are stored as JSON files exported from n8n:

| Workflow | Description |
|----------|-------------|
| WF-INTAKE-EMAIL-V2 | Gmail polling, email parsing, POST to /ingest/raw |
| WF-REPLY-GENERATE-V2 | LLM reply generation + validation |
| WF-REPLY-SEND-v1 | Send approved replies via email |
| WF-EXTRACT-AND-ENRICH-IOC | IOC extraction and enrichment pipeline |
| Gmail Scam Simulator | Test workflow for simulating inbound scam emails |

---

## Exporting a Workflow from n8n

1. Open the workflow in n8n
2. Click the menu (three dots) in the top-right corner
3. Select **"Download"**
4. Save the JSON file in `n8n/workflows/`
5. Rename with a descriptive name (e.g., `email-ingestion-gmail.json`)

---

## Importing a Workflow into n8n

1. Open n8n
2. Click **"Add workflow"** or the `+` button
3. Click the menu (three dots)
4. Select **"Import from File"**
5. Select the JSON file from `n8n/workflows/`
6. Configure the required credentials
7. Activate the workflow

---

## Credentials

**WARNING**: Never commit real secrets (tokens, passwords, API keys) to this repository.

Credentials are stored encrypted in n8n (`data/n8n/database.sqlite`). Configure them manually in the n8n UI.

---

## Backend Integration

Workflows interact with the ScamBuster backend via the REST API.

**Main endpoint**: `POST /api/v1/communication/ingest/raw`

**Authentication**: JWT Bearer Token (obtained via `POST /api/v1/auth/login`)

---

## Best Practices

### Naming
- Use descriptive names in kebab-case
- Format: `<source>-<action>-<target>.json`

### Documentation
- Add a clear description inside each workflow
- Use Sticky Notes in n8n to explain complex sections
- Document required environment variables and credentials

### Testing
- Test workflows in dev environment before activating in production
- Use test data only (no real sensitive data)

---

## Deployment

### Development
1. Import workflows from this directory
2. Configure dev credentials
3. Point to dev API: `http://backend-dev:8080/api`

### Production
1. Import workflows from this directory
2. Configure production credentials
3. Point to production API
4. Activate workflows
5. Monitor logs in n8n Executions panel

---

## Troubleshooting

### Workflow does not start
- Verify credentials are configured
- Check API backend permissions
- Check n8n execution logs

### Authentication error
- Verify the JWT token is valid
- Regenerate a new token if needed
- Verify the `Authorization: Bearer <token>` header is correct

### 422 error (Validation)
- Verify the JSON payload format
- Check the API documentation at `/api/doc`
- Ensure all required fields are present
