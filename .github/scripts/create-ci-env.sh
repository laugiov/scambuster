#!/usr/bin/env bash
# Shared CI environment file generator.
#
# Usage (from repo root):
#   bash .github/scripts/create-ci-env.sh
#
# Creates .env and .env.test with all variables needed by CI jobs.
# Single source of truth — called by every CI job instead of
# duplicating the env block in each workflow step.
set -euo pipefail

cat > .env << 'ENVEOF'
APP_ENV=test
APP_DEBUG=0
APP_SECRET=ci-test-app-secret-32chars-min!!
DATABASE_URL=postgresql://postgres:postgres@postgres:5432/scambuster_test
POSTGRES_PASSWORD=postgres
INGEST_LOGIN=ingest-ci
INGEST_PASSWORD=ingest-ci-password
REDIS_URL=redis://redis:6379
LOCK_DSN=redis://redis:6379
JWT_SECRET=ci-test-jwt-secret
JWT_PASSPHRASE=ci-test-passphrase
LOGIN_HASH_SALT=ci-test-salt
LLM_API_KEY=sk-test-not-a-real-key
LLM_PROVIDER=mock
LLM_MODEL=mock
LLM_API_URL=https://api.openai.com/v1
LLM_VALIDATION_ENABLED=true
SCAMBUSTER_KILL_SWITCH=false
PROMPT_INJECTION_ENABLED=false
PROMPT_INJECTION_MODEL=gpt-4o-mini
PROMPT_INJECTION_TEMPERATURE=0.2
STIX_EXPORT_PATH=/tmp/stix-test
SCORE_RISK_MIN=30
CAMPAIGN_PROMOTION_PPV_THRESHOLD=0.85
CAMPAIGN_PROMOTION_MIN_HITS=5
CAMPAIGN_PROMOTION_MIN_LEAD_TIME_SEC=10800
REPLY_MIN_WORDS=50
REPLY_MAX_WORDS=150
REPLY_MAX_LINKS=1
CONVERSATION_HISTORY_EXCLUDED_EMAILS=noreply@example.com
APP_API_BASE_URL=http://localhost:8080
N8N_ENCRYPTION_KEY=ci-test-n8n-key
MAILER_DSN=null://null
SCAMBUSTER_SAFE_DOMAINS=gmail.com,yahoo.com,outlook.com,example.com
SCAMBUSTER_API_URL=http://backend-dev:8080
HONEYPOT_IMAP_HOST=imap.gmail.com
HONEYPOT_IMAP_USER=test@example.com
HONEYPOT_IMAP_PASSWORD=testpass
REPLY_HISTORY_LAST_N=5
SIEM_PROVIDER=none
TOTP_ENCRYPTION_KEY=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
AUDIT_HMAC_KEY=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
ENVEOF

# Remove leading whitespace (safety net if heredoc is indented)
sed -i 's/^[[:space:]]*//' .env
cp .env .env.test

echo "CI environment files created (.env + .env.test)"
