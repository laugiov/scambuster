#!/bin/sh
set -e

echo "╔══════════════════════════════════════════════╗"
echo "║       ScamBuster Demo — Starting...           ║"
echo "╚══════════════════════════════════════════════╝"

# ─── 0. Write .env from actual environment variables ───
# Railway injects env vars but Symfony needs a .env file to bootstrap.
# We generate one from the current environment so Doctrine can connect.
echo "[demo] Writing .env from environment variables..."
# Ensure LOCK_DSN has a fallback (semaphore = local, no Redis dependency)
export LOCK_DSN="${LOCK_DSN:-semaphore}"
env | grep -E '^(DATABASE_URL|REDIS_URL|APP_|JWT_|LLM_|MAILER_|SCAMBUSTER_|VAULT_|LOGIN_|LOCK_|PROMPT_|STIX_|SCORE_|CAMPAIGN_|REPLY_|CONVERSATION_|SIEM_|INGEST_|N8N_|PORT)' > /app/.env
echo "[demo] .env written with $(wc -l < /app/.env) variables."

# ─── 0b. Boot-time sanity checks (surface the real problem early) ───
echo "[demo] Sanity checks:"
# DATABASE_URL presence (without leaking the password).
if [ -z "${DATABASE_URL:-}" ]; then
  echo "[demo]   DATABASE_URL: MISSING — Doctrine cannot connect."
else
  db_host=$(printf '%s' "$DATABASE_URL" | sed -E 's#^[a-z+]+://[^@]*@([^:/]+).*#\1#')
  echo "[demo]   DATABASE_URL host: ${db_host}"
fi
# APP_SECRET length (spec 050 SmtpDsnEncryptor requires >= 12 chars).
if [ -z "${APP_SECRET:-}" ]; then
  echo "[demo]   APP_SECRET: MISSING — Symfony will fail to boot."
else
  app_secret_len=${#APP_SECRET}
  if [ "$app_secret_len" -lt 12 ]; then
    echo "[demo]   APP_SECRET: too short (${app_secret_len} chars, min 12) — SmtpDsnEncryptor will throw at boot."
  else
    echo "[demo]   APP_SECRET length: ${app_secret_len}"
  fi
fi
# PHP sodium extension (spec 050 encryption depends on it).
if php -m 2>/dev/null | grep -qi '^sodium$'; then
  echo "[demo]   PHP sodium ext: ok"
else
  echo "[demo]   PHP sodium ext: MISSING — SmtpDsnEncryptor will throw at boot."
fi

# ─── 1. Wait for PostgreSQL — TWO PHASES ───
# Phase A: pure TCP reachability via pg_isready / fsockopen — no Symfony.
# Phase B: ONE Symfony kernel boot probe with full output captured.
#
# Splitting these matters: previously we ran `php bin/console doctrine:query:sql`
# in a loop, which (a) cold-compiles the DI container on every iteration, easily
# overrunning the 60s budget on small plans, and (b) made any kernel boot
# failure indistinguishable from a database outage.
echo "[demo]   APP_ENV: ${APP_ENV:-(unset)}"

if [ -z "${DATABASE_URL:-}" ]; then
  echo "[demo] FATAL: DATABASE_URL is unset, cannot connect."
  exit 1
fi
pg_host=$(printf '%s' "$DATABASE_URL" | sed -E 's#^[a-z+]+://[^@]*@([^:/]+).*#\1#')
pg_port=$(printf '%s' "$DATABASE_URL" | sed -nE 's#^[a-z+]+://[^@]*@[^:]+:([0-9]+).*#\1#p')
pg_port=${pg_port:-5432}

echo "[demo] Phase A: waiting for TCP reachability of ${pg_host}:${pg_port} (60s budget)..."
retries=0
until php -r "exit(@fsockopen('$pg_host', $pg_port, \$e, \$es, 3) === false ? 1 : 0);" 2>/dev/null; do
  retries=$((retries + 1))
  if [ $retries -ge 30 ]; then
    echo "[demo] FATAL: TCP connect to ${pg_host}:${pg_port} failed for 60s."
    echo "[demo]   Postgres is genuinely unreachable from this container."
    echo "[demo]   Likely causes:"
    echo "[demo]     - DATABASE_URL points to the wrong host (e.g. public proxy"
    echo "[demo]       'interchange.proxy.rlwy.net' instead of internal"
    echo "[demo]       'postgres.railway.internal')"
    echo "[demo]     - Postgres service is down or restarting"
    echo "[demo]     - Network policy blocks egress on this port"
    exit 1
  fi
  sleep 2
done
echo "[demo] Phase A: TCP open."

echo "[demo] Phase B: probing Symfony kernel boot (single attempt, full output)..."
boot_log=$(mktemp)
if ! php bin/console doctrine:query:sql "SELECT 1" > "$boot_log" 2>&1; then
  echo "[demo] FATAL: kernel boot OR DB query failed. Combined stdout+stderr:"
  echo "[demo] ─────────────────────────────────────────────────────"
  if [ -s "$boot_log" ]; then
    sed 's/^/[demo]   /' < "$boot_log"
  else
    echo "[demo]   <no output — process killed before flushing>"
    echo "[demo]   Diagnostic info:"
    echo "[demo]     PHP version : $(php -v 2>&1 | head -1)"
    echo "[demo]     PHP memory_limit: $(php -r 'echo ini_get("memory_limit");' 2>/dev/null)"
    echo "[demo]     bin/console : $(test -f /app/bin/console && echo present || echo MISSING)"
    echo "[demo]     vendor/auto : $(test -f /app/vendor/autoload.php && echo present || echo MISSING)"
    echo "[demo]   Most likely: the container compile was OOM-killed."
    echo "[demo]   Try: increase Railway memory limit OR pre-warm cache during build."
  fi
  echo "[demo] ─────────────────────────────────────────────────────"
  exit 1
fi
rm -f "$boot_log"
echo "[demo] Phase B: kernel + DB query OK."
echo "[demo] Database ready."

# ─── 2. Run migrations ───
echo "[demo] Running migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>&1 | tail -3

# ─── 3. Generate JWT keys if missing ───
if [ ! -f config/jwt/private.pem ]; then
  echo "[demo] Generating JWT keys..."
  mkdir -p config/jwt
  openssl genpkey -algorithm RSA -out config/jwt/private.pem \
    -aes-256-cbc -pass "pass:${JWT_PASSPHRASE:-demo-passphrase}" -pkeyopt rsa_keygen_bits:2048 2>/dev/null
  openssl rsa -in config/jwt/private.pem -pubout -out config/jwt/public.pem \
    -passin "pass:${JWT_PASSPHRASE:-demo-passphrase}" 2>/dev/null
  echo "[demo] JWT keys generated."
else
  echo "[demo] JWT keys exist — skipping."
fi

# ─── 4. Seed if DB is empty ───
CONV_COUNT=$(php bin/console doctrine:query:sql \
  "SELECT COUNT(*) FROM conversation" --no-interaction 2>/dev/null \
  | grep -oE '[0-9]+' | head -1 || echo "0")

if [ "$CONV_COUNT" = "0" ] || [ "${DEMO_FORCE_RESEED:-false}" = "true" ]; then
  echo "[demo] Seeding database..."

  if [ "${DEMO_FORCE_RESEED:-false}" = "true" ]; then
    echo "[demo] Force reseed requested — truncating all tables..."
    php bin/console doctrine:query:sql "
      DO \$\$ DECLARE r RECORD;
      BEGIN
        FOR r IN (SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename != 'doctrine_migration_versions') LOOP
          EXECUTE 'TRUNCATE TABLE ' || quote_ident(r.tablename) || ' CASCADE';
        END LOOP;
      END \$\$;
    " --no-interaction 2>/dev/null || true
    echo "[demo] All tables truncated."
  fi

  echo "[demo] Loading fixtures..."
  php bin/console doctrine:fixtures:load --no-interaction --append 2>&1 | tail -5

  echo "[demo] Loading demo dataset..."
  php bin/console scambuster:demo:load --purge 2>&1 | tail -3

  echo "[demo] Cleaning fixture test data..."
  php bin/console doctrine:query:sql "DELETE FROM conversation WHERE stix_id NOT LIKE 'demo-%'" --no-interaction 2>/dev/null || true

  # ─── Shift all dates to end "today" ───
  echo "[demo] Shifting dates to current time..."
  php bin/console doctrine:query:sql "
    DO \$\$
    DECLARE
      max_ts TIMESTAMP;
      shift_interval INTERVAL;
    BEGIN
      SELECT MAX(ts_last) INTO max_ts FROM conversation WHERE stix_id LIKE 'demo-%';
      IF max_ts IS NOT NULL THEN
        shift_interval := NOW() - max_ts;
        UPDATE conversation SET
          ts_first = ts_first + shift_interval,
          ts_last = ts_last + shift_interval,
          created_at = created_at + shift_interval,
          updated_at = updated_at + shift_interval
        WHERE stix_id LIKE 'demo-%';
        UPDATE message SET
          ts_msg = ts_msg + shift_interval,
          ts_ingest = ts_ingest + shift_interval
        WHERE conv_id IN (SELECT conv_id FROM conversation WHERE stix_id LIKE 'demo-%');
        UPDATE observed_ioc SET
          ts_observed = ts_observed + shift_interval
        WHERE msg_id IN (SELECT msg_id FROM message WHERE conv_id IN (SELECT conv_id FROM conversation WHERE stix_id LIKE 'demo-%'));
        UPDATE indicator SET
          first_seen = first_seen + shift_interval,
          last_seen = last_seen + shift_interval,
          created_at = created_at + shift_interval,
          updated_at = updated_at + shift_interval;
        UPDATE llm_usage SET
          created_at = created_at + shift_interval;
        UPDATE bandit_convergence_log SET
          logged_at = logged_at + shift_interval;
        RAISE NOTICE 'Shifted all dates by %', shift_interval;
      END IF;
    END \$\$;
  " --no-interaction 2>/dev/null || true
  echo "[demo] Dates shifted to current time."

  # ─── Clustering backfill (populate Clusters page) ───
  echo "[demo] Running clustering backfill..."
  php bin/console app:clustering:backfill --no-interaction 2>&1 | tail -3

  echo "[demo] Applying data quality fixes..."
  php bin/console app:fix:risk-scores -q 2>/dev/null || true
  php bin/console app:fix:semantic-roles -q 2>/dev/null || true
  php bin/console app:compute:cluster-sophistication -q 2>/dev/null || true
  echo "[demo] Data quality fixes applied."

  echo "[demo] Database seeded."
else
  # Still shift dates on restart to keep demo "fresh"
  echo "[demo] Refreshing dates to current time..."
  php bin/console doctrine:query:sql "
    DO \$\$
    DECLARE
      max_ts TIMESTAMP;
      shift_interval INTERVAL;
    BEGIN
      SELECT MAX(ts_last) INTO max_ts FROM conversation WHERE stix_id LIKE 'demo-%';
      IF max_ts IS NOT NULL AND (NOW() - max_ts) > INTERVAL '1 hour' THEN
        shift_interval := NOW() - max_ts;
        UPDATE conversation SET
          ts_first = ts_first + shift_interval,
          ts_last = ts_last + shift_interval,
          created_at = created_at + shift_interval,
          updated_at = updated_at + shift_interval
        WHERE stix_id LIKE 'demo-%';
        UPDATE message SET
          ts_msg = ts_msg + shift_interval,
          ts_ingest = ts_ingest + shift_interval
        WHERE conv_id IN (SELECT conv_id FROM conversation WHERE stix_id LIKE 'demo-%');
        UPDATE observed_ioc SET
          ts_observed = ts_observed + shift_interval
        WHERE msg_id IN (SELECT msg_id FROM message WHERE conv_id IN (SELECT conv_id FROM conversation WHERE stix_id LIKE 'demo-%'));
        UPDATE indicator SET
          first_seen = first_seen + shift_interval,
          last_seen = last_seen + shift_interval,
          created_at = created_at + shift_interval,
          updated_at = updated_at + shift_interval;
        UPDATE llm_usage SET
          created_at = created_at + shift_interval;
        UPDATE bandit_convergence_log SET
          logged_at = logged_at + shift_interval;
        RAISE NOTICE 'Shifted dates by %', shift_interval;
      END IF;
    END \$\$;
  " --no-interaction 2>/dev/null || true
  echo "[demo] Database has $CONV_COUNT conversations — dates refreshed."
fi

# ─── 5. Clear cache ───
php bin/console cache:clear --no-warmup -q 2>/dev/null || true

echo ""
echo "╔══════════════════════════════════════════════╗"
echo "║       ScamBuster Demo — Ready!                ║"
echo "╠══════════════════════════════════════════════╣"
echo "║  API:   http://localhost:8080/api/doc         ║"
echo "║  Login: user@example.com                      ║"
echo "║  Pass:  Un1que\$trongPassword2024              ║"
echo "╚══════════════════════════════════════════════╝"
echo ""

exec php -S 0.0.0.0:${PORT:-8080} -t public
