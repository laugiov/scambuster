#!/bin/sh
set -e

echo "╔══════════════════════════════════════════════╗"
echo "║       ScamBuster Demo — Starting...           ║"
echo "╚══════════════════════════════════════════════╝"

# ─── 1. Wait for PostgreSQL ───
echo "[demo] Waiting for database..."
retries=0
until php bin/console doctrine:query:sql "SELECT 1" > /dev/null 2>&1; do
  retries=$((retries + 1))
  if [ $retries -ge 30 ]; then
    echo "[demo] ERROR: Database not reachable after 60s"
    exit 1
  fi
  sleep 2
done
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

  if [ "${DEMO_FORCE_RESEED:-false}" = "true" ] && [ "$CONV_COUNT" != "0" ]; then
    echo "[demo] Force reseed requested — dropping and recreating schema..."
    php bin/console doctrine:database:drop --force --if-exists 2>/dev/null || true
    php bin/console doctrine:database:create --if-not-exists 2>/dev/null
    php bin/console doctrine:migrations:migrate --no-interaction 2>&1 | tail -2
  fi

  echo "[demo] Loading fixtures..."
  php bin/console doctrine:fixtures:load --no-interaction 2>&1 | tail -5

  echo "[demo] Loading demo dataset (150 conversations)..."
  php bin/console scambuster:demo:load 2>&1 | tail -3

  echo "[demo] Cleaning fixture test data..."
  php bin/console doctrine:query:sql "DELETE FROM conversation WHERE stix_id NOT LIKE 'demo-%'" --no-interaction 2>/dev/null || true

  echo "[demo] Database seeded."
else
  echo "[demo] Database has $CONV_COUNT conversations — skipping seed."
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

exec php -S 0.0.0.0:8080 -t public
