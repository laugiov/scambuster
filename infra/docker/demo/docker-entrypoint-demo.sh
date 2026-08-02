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
# nelmio_cors needs CORS_ALLOW_ORIGIN or every /api request (login included) 500s.
# The demo can be served on any host, so allow all browser origins unless overridden.
if [ -z "${CORS_ALLOW_ORIGIN:-}" ]; then export CORS_ALLOW_ORIGIN='^https?://.+$'; fi
# Reply-pipeline feature flags have no config default; an unset one throws
# "Environment variable not found" at container compile and rolls back the DB seed.
export REPLY_SIGNATURE_STRIP_ENABLED="${REPLY_SIGNATURE_STRIP_ENABLED:-true}"
export REPLY_VALIDATOR_CONTEXT_ENABLED="${REPLY_VALIDATOR_CONTEXT_ENABLED:-true}"
export REPLY_VALIDATOR_STRUCTURED_CORRECTION="${REPLY_VALIDATOR_STRUCTURED_CORRECTION:-true}"
# Two migrations hard-require a 64-hex key (TOTP-secret encryption, then the audit HMAC chain) or
# they throw and halt the whole migration run at that point. The app boots from REAL env vars (the
# Symfony Runtime skips .env when APP_ENV is set), so these must be exported, not written to .env.
# Disposable demo data needs no real secrets, so default fixed throwaway keys when the host has none.
export TOTP_ENCRYPTION_KEY="${TOTP_ENCRYPTION_KEY:-70791234deadc0de70791234deadc0de70791234deadc0de70791234deadc0de}"
export AUDIT_HMAC_KEY="${AUDIT_HMAC_KEY:-a11ce5b0dedbeef00a11ce5b0dedbeef00a11ce5b0dedbeef00a11ce5b0dedbe}"
# Quote every value. A raw `env` dump breaks Symfony's Dotenv parser on the first
# value that contains a space or a newline (it aborts the whole boot), and a
# multi-line value would even bleed into the next line as a bogus KEY=VALUE. PHP is
# always present here; it wraps each value in a double-quoted entry and escapes
# backslash, double-quote, dollar, newline and CR (tab is legal raw inside quotes).
php -r '
$re = "/^(DATABASE_URL|REDIS_URL|APP_|CORS_|AUDIT_|TOTP_|JWT_|LLM_|MAILER_|SCAMBUSTER_|VAULT_|LOGIN_|LOCK_|PROMPT_|STIX_|SCORE_|CAMPAIGN_|REPLY_|CONVERSATION_|SIEM_|INGEST_|N8N_|PORT)/";
$out = [];
foreach (getenv() as $k => $v) {
    if (preg_match($re, $k)) {
        $out[] = $k . "=\"" . addcslashes((string) $v, "\\\"\$\n\r") . "\"";
    }
}
sort($out);
if (false === file_put_contents("/app/.env", $out ? implode("\n", $out) . "\n" : "")) {
    fwrite(STDERR, "cannot write /app/.env\n");
    exit(1);
}
'
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
# APP_SECRET length (SmtpDsnEncryptor requires >= 12 chars).
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
# PHP sodium extension (encryption depends on it).
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

  # Load ONLY the light reference/lookup rows the demo dataset needs (scam types,
  # personas, channels, directions, mail account, users). The full fixture set pulls in
  # thousands of heavy message/attachment/campaign rows that get deleted right below —
  # loading them here OOM/crashes the container at boot on constrained hosts.
  echo "[demo] Loading reference data..."
  php bin/console doctrine:fixtures:load --no-interaction --append --group=reference 2>&1 | tail -5

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

  # Threat-actor psychological profiles are LLM-generated in production; the demo has no API key,
  # so seed a representative profile per cluster, templated from its dominant scam type (no LLM).
  echo "[demo] Seeding threat-actor psychological profiles..."
  php bin/console doctrine:query:sql "
    WITH stim AS (
      SELECT tacc.cluster_id, mode() WITHIN GROUP (ORDER BY ic.stimulus_type) AS dom_stimulus
      FROM ioc_context ic
      JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
      JOIN message m ON oi.msg_id = m.msg_id
      JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
      WHERE ic.stimulus_type IS NOT NULL AND ic.stimulus_type NOT IN ('', 'PASSIVE')
      GROUP BY tacc.cluster_id
    ),
    cd AS (
      SELECT tacc.cluster_id,
             mode() WITHIN GROUP (ORDER BY st.code) AS scam_code,
             count(DISTINCT m.msg_id) FILTER (WHERE d.code = 'in') AS in_msgs,
             count(DISTINCT c.conv_id) AS conv_ct
      FROM threat_actor_cluster_conversation tacc
      JOIN conversation c ON c.conv_id = tacc.conv_id
      JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
      LEFT JOIN message m ON m.conv_id = c.conv_id
      LEFT JOIN lkp_direction d ON m.direction = d.dir_id
      GROUP BY tacc.cluster_id
    )
    INSERT INTO threat_actor_psych_profile (cluster_id, dominant_lever, secondary_levers, behavioural_summary, escalation_pattern, victim_targeting, dominant_stimulus, avg_urgency, hesitation_events, language_switches, conversation_count, message_count, generated_at, generated_by_model, prompt_version)
    SELECT cd.cluster_id,
      CASE cd.scam_code WHEN 'INVOICE_FRAUD' THEN 'Authority' WHEN 'CEO_FRAUD' THEN 'Authority' WHEN 'TECH_SUPPORT' THEN 'Authority' WHEN 'ROMANCE' THEN 'Liking' WHEN 'CHARITY' THEN 'Liking' WHEN 'LOTTERY' THEN 'Scarcity' WHEN 'ADVANCE_FEE_419' THEN 'Scarcity' WHEN 'INVESTMENT' THEN 'Social Proof' WHEN 'JOB_OFFER' THEN 'Reciprocity' ELSE 'Urgency' END,
      '{}',
      CASE cd.scam_code
        WHEN 'INVOICE_FRAUD' THEN 'Impersonates a known vendor or finance contact and applies deadline pressure to force an unverified payment to a newly supplied account.'
        WHEN 'CEO_FRAUD' THEN 'Poses as a senior executive and leverages hierarchy plus urgency to push an out-of-process wire transfer.'
        WHEN 'ROMANCE' THEN 'Builds false intimacy and trust across many exchanges before introducing a sudden financial emergency.'
        WHEN 'LOTTERY' THEN 'Dangles a large time-limited windfall that can only be released after an upfront processing fee.'
        WHEN 'ADVANCE_FEE_419' THEN 'Promises a share of a large sum in exchange for advance fees that keep escalating.'
        WHEN 'TECH_SUPPORT' THEN 'Poses as official support and manufactures an urgent security threat to obtain remote access or payment.'
        WHEN 'INVESTMENT' THEN 'Cites fabricated returns and other satisfied investors to lure targets into a fake high-yield scheme.'
        WHEN 'JOB_OFFER' THEN 'Offers an attractive remote role then requests fees or personal data as a hiring formality.'
        WHEN 'CHARITY' THEN 'Exploits goodwill around a topical cause to solicit donations to an actor-controlled account.'
        ELSE 'Spoofs a trusted brand and pressures immediate action to harvest credentials or extract payment.' END,
      CASE cd.scam_code WHEN 'ROMANCE' THEN 'gradual' WHEN 'INVESTMENT' THEN 'gradual' WHEN 'CEO_FRAUD' THEN 'gradual' WHEN 'INVOICE_FRAUD' THEN 'gradual' WHEN 'CHARITY' THEN 'stable' ELSE 'rapid' END,
      CASE cd.scam_code
        WHEN 'ROMANCE' THEN 'Isolated individuals seeking companionship.'
        WHEN 'INVOICE_FRAUD' THEN 'Employees in finance and accounts-payable roles.'
        WHEN 'CEO_FRAUD' THEN 'Finance staff who can authorise payments.'
        WHEN 'TECH_SUPPORT' THEN 'Less technical users, often older.'
        WHEN 'INVESTMENT' THEN 'Yield-seeking retail investors.'
        WHEN 'LOTTERY' THEN 'Hopeful respondents to unsolicited good news.'
        WHEN 'JOB_OFFER' THEN 'Active job seekers.'
        WHEN 'CHARITY' THEN 'Empathetic donors.'
        ELSE 'Recipients of the spoofed brand or offer.' END,
      COALESCE(s.dom_stimulus, CASE cd.scam_code WHEN 'INVOICE_FRAUD' THEN 'PAYMENT_INITIATION' WHEN 'CEO_FRAUD' THEN 'PAYMENT_INITIATION' WHEN 'ROMANCE' THEN 'TRUST_BUILDING' WHEN 'CHARITY' THEN 'TRUST_BUILDING' WHEN 'LOTTERY' THEN 'URGENCY_PRESSURE' WHEN 'TECH_SUPPORT' THEN 'URGENCY_PRESSURE' WHEN 'ADVANCE_FEE_419' THEN 'URGENCY_PRESSURE' ELSE 'DIRECT_REQUEST' END),
      CASE cd.scam_code WHEN 'TECH_SUPPORT' THEN 0.85 WHEN 'LOTTERY' THEN 0.8 WHEN 'CEO_FRAUD' THEN 0.75 WHEN 'INVOICE_FRAUD' THEN 0.7 WHEN 'ROMANCE' THEN 0.45 ELSE 0.6 END,
      0, 0,
      COALESCE(tac.conversation_count, cd.conv_ct), COALESCE(cd.in_msgs, 0),
      NOW(), 'demo-seed', 'demo-v1'
    FROM cd JOIN threat_actor_cluster tac ON tac.cluster_id = cd.cluster_id
    LEFT JOIN stim s ON s.cluster_id = cd.cluster_id
    WHERE tac.merged_into_id IS NULL AND NOT EXISTS (SELECT 1 FROM threat_actor_psych_profile p WHERE p.cluster_id = cd.cluster_id);
  " --no-interaction 2>&1 | tail -2 || true

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

# ─── 4b. Backfill psych-profile stimulus (idempotent) ───
# Runs on every boot so profiles seeded before this field existed get it too,
# regardless of whether a reseed happened this boot. Only touches NULL rows.
echo "[demo] Backfilling psychological-profile stimulus..."
php bin/console doctrine:query:sql "
  WITH stim AS (
    SELECT tacc.cluster_id, mode() WITHIN GROUP (ORDER BY ic.stimulus_type) AS dom_stimulus
    FROM ioc_context ic
    JOIN observed_ioc oi ON ic.obs_id = oi.obs_id
    JOIN message m ON oi.msg_id = m.msg_id
    JOIN threat_actor_cluster_conversation tacc ON tacc.conv_id = m.conv_id
    WHERE ic.stimulus_type IS NOT NULL AND ic.stimulus_type NOT IN ('', 'PASSIVE')
    GROUP BY tacc.cluster_id
  ),
  sc AS (
    SELECT tacc.cluster_id, mode() WITHIN GROUP (ORDER BY st.code) AS scam_code
    FROM threat_actor_cluster_conversation tacc
    JOIN conversation c ON c.conv_id = tacc.conv_id
    JOIN lkp_scam_type st ON c.scam_type_id = st.scam_type_id
    GROUP BY tacc.cluster_id
  )
  UPDATE threat_actor_psych_profile p
  SET dominant_stimulus = COALESCE(s.dom_stimulus, CASE sc.scam_code
    WHEN 'INVOICE_FRAUD' THEN 'PAYMENT_INITIATION' WHEN 'CEO_FRAUD' THEN 'PAYMENT_INITIATION'
    WHEN 'ROMANCE' THEN 'TRUST_BUILDING' WHEN 'CHARITY' THEN 'TRUST_BUILDING'
    WHEN 'LOTTERY' THEN 'URGENCY_PRESSURE' WHEN 'TECH_SUPPORT' THEN 'URGENCY_PRESSURE'
    WHEN 'ADVANCE_FEE_419' THEN 'URGENCY_PRESSURE' ELSE 'DIRECT_REQUEST' END)
  FROM sc
  LEFT JOIN stim s ON s.cluster_id = sc.cluster_id
  WHERE p.cluster_id = sc.cluster_id AND p.dominant_stimulus IS NULL;
" --no-interaction 2>&1 | tail -2 || true

# ─── 4c. Seed scammer TTP observations (idempotent, every boot) ───
# TTPs are LLM-extracted in production; the demo has no API key, so seed
# deterministic, plausible observations from the real demo message bodies
# (verbatim evidence, code-point offsets). Runs every boot — like the psych
# profiles above — so a demo DB seeded before this module existed gets TTPs on a
# plain redeploy; the command is idempotent and a no-op once observations exist.
echo "[demo] Seeding scammer TTP observations..."
php bin/console scambuster:ttp:demo-seed 2>&1 | tail -3 || true

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
