#!/bin/sh
# Production entrypoint: fail-fast checks → wait for DB → ensure JWT keys →
# run migrations → seed reference data on an empty DB → hand off to supervisord
# (php-fpm + nginx). Runs as root, then supervisord drops workers to www-data.
set -e

echo "[prod] ScamBuster production entrypoint"

# ── Required configuration (fail fast; never boot half-configured) ───────────
: "${DATABASE_URL:?DATABASE_URL is required}"
: "${APP_SECRET:?APP_SECRET is required (>= 12 chars)}"
if [ "${#APP_SECRET}" -lt 12 ]; then echo "[prod] FATAL: APP_SECRET too short (min 12)"; exit 1; fi
if [ -z "${TOTP_ENCRYPTION_KEY:-}" ] || [ -z "${AUDIT_HMAC_KEY:-}" ]; then
    echo "[prod] FATAL: TOTP_ENCRYPTION_KEY and AUDIT_HMAC_KEY are required (64 hex chars each)."
    echo "[prod]        Generate each with: openssl rand -hex 32"
    exit 1
fi
: "${JWT_PASSPHRASE:?JWT_PASSPHRASE is required (protects the JWT private key)}"

cd /app

# Symfony's console reads /app/.env at bootstrap — write it from the environment.
sh /opt/write-prod-env.sh

# ── Wait for PostgreSQL (TCP) ────────────────────────────────────────────────
pg_host=$(printf '%s' "$DATABASE_URL" | sed -E 's#^[a-z+]+://[^@]*@([^:/]+).*#\1#')
pg_port=$(printf '%s' "$DATABASE_URL" | sed -nE 's#^[a-z+]+://[^@]*@[^:]+:([0-9]+).*#\1#p'); pg_port=${pg_port:-5432}
echo "[prod] waiting for postgres ${pg_host}:${pg_port} ..."
i=0
until pg_isready -h "$pg_host" -p "$pg_port" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -ge 30 ]; then echo "[prod] FATAL: postgres unreachable after 60s"; exit 1; fi
    sleep 2
done
echo "[prod] postgres reachable."

# ── JWT keypair (RS256) — generate once if absent ────────────────────────────
if [ ! -f config/jwt/private.pem ]; then
    echo "[prod] generating RS256 JWT keypair..."
    mkdir -p config/jwt
    openssl genpkey -algorithm RSA -out config/jwt/private.pem \
        -aes256 -pass "pass:${JWT_PASSPHRASE}" >/dev/null 2>&1
    openssl pkey -in config/jwt/private.pem -passin "pass:${JWT_PASSPHRASE}" \
        -pubout -out config/jwt/public.pem >/dev/null 2>&1
    chmod 600 config/jwt/private.pem
    chmod 644 config/jwt/public.pem
fi

# ── Migrations (automatic) ───────────────────────────────────────────────────
echo "[prod] running database migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# ── Reference/lookup data + default admin ────────────────────────────────────
# Migrations seed only part of the reference data (personas, 7 of 13 scam types)
# and never seed channels, directions, scam↔persona links, or a user. This image
# is --no-dev (no fixtures bundle), so fill the gaps with an idempotent SQL seed.
# It is INSERT-ONLY (never updates/deletes), so it is safe to run on every boot.
# libpq rejects the ?serverVersion=... query string, so strip it for psql.
PSQL_URL=$(printf '%s' "$DATABASE_URL" | sed 's/?.*//')
FRESH_INSTALL=$(psql "$PSQL_URL" -tAc "SELECT CASE WHEN count(*)=0 THEN 1 ELSE 0 END FROM app_users" 2>/dev/null || echo 0)

echo "[prod] seeding reference data (idempotent)..."
psql "$PSQL_URL" -v ON_ERROR_STOP=1 -f /opt/prod-seed-reference.sql >/dev/null
SCAM_COUNT=$(psql "$PSQL_URL" -tAc "SELECT count(*) FROM lkp_scam_type" 2>/dev/null || echo "?")
echo "[prod] reference data ready: ${SCAM_COUNT} scam types, "\
"$(psql "$PSQL_URL" -tAc 'SELECT count(*) FROM lkp_channel' 2>/dev/null) channels, "\
"$(psql "$PSQL_URL" -tAc 'SELECT count(*) FROM scam_type_persona' 2>/dev/null) persona links."

if [ "${FRESH_INSTALL}" = "1" ]; then
    # Register the honeypot mailbox so inbound resolves, when a real one is set.
    if [ -n "${HONEYPOT_IMAP_USER:-}" ] && [ "${HONEYPOT_IMAP_USER}" != "your-honeypot@gmail.com" ]; then
        php bin/console app:mail-account:add \
            --owner-id="22222222-2222-2222-2222-222222222222" \
            --email="${HONEYPOT_IMAP_USER}" \
            --endpoint="${HONEYPOT_IMAP_HOST:-imap.gmail.com}" \
            --label="honeypot" --no-interaction 2>/dev/null \
            && echo "[prod] honeypot mailbox registered: ${HONEYPOT_IMAP_USER}" \
            || echo "[prod] (mailbox auto-register skipped — add later with app:mail-account:add)"
    fi
    echo ""
    echo "  ####################################################################"
    echo "  #  SECURITY: default admin seeded with the PUBLIC default password. #"
    echo "  #  Log in as user@example.com and CHANGE THE PASSWORD before you    #"
    echo "  #  expose this instance (or seed your own user and delete this one).#"
    echo "  ####################################################################"
    echo ""
fi

# ── Warm cache + hand ownership to the runtime user ──────────────────────────
php bin/console cache:clear --no-warmup 2>/dev/null || true
chown -R www-data:www-data /app/var /app/config/jwt 2>/dev/null || true

echo "[prod] starting supervisord (php-fpm + nginx) on :8080 ..."
exec supervisord -c /etc/supervisor/conf.d/scambuster.conf
