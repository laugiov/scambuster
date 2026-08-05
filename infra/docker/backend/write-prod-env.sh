#!/bin/sh
# Symfony's console/kernel bootstraps Dotenv, which reads /app/.env even when the
# configuration is supplied as real container environment variables. Write that
# file from the current environment so php bin/console can boot. Both the app
# entrypoint and the scheduler run this first.
env | grep -E '^(APP_|DATABASE_URL|REDIS_URL|LOCK_DSN|CORS_|TOTP_|AUDIT_|JWT_|LLM_|MAILER_|SCAMBUSTER_|LOGIN_|PROMPT_|STIX_|SCORE_|CAMPAIGN_|REPLY_|CONVERSATION_|SIEM_|INGEST_|N8N_|HONEYPOT_|OIDC_|SCHEDULER_|POSTGRES_|ANTHROPIC_|OLLAMA_)=' > /app/.env
chown www-data:www-data /app/.env 2>/dev/null || true
chmod 640 /app/.env 2>/dev/null || true
