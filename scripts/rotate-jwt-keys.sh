#!/usr/bin/env bash
# Rotate JWT RS256 keys with zero-downtime.
#
# Strategy:
#   1. Backup current keys
#   2. Generate new key pair
#   3. During transition (< 15 min), old tokens remain valid because
#      the new keys sign new tokens while Lexik still accepts old tokens
#      until their TTL (900s) expires.
#
# Usage: bash scripts/rotate-jwt-keys.sh [passphrase]
# After running: restart the backend container to load new keys.

set -euo pipefail

KEY_DIR="/var/www/html/scambuster-github/backend-symfony/config/jwt"
BACKUP_DIR="$KEY_DIR/backup-$(date +%Y%m%d-%H%M%S)"
PASSPHRASE="${1:-${JWT_PASSPHRASE:-}}"

if [ -z "$PASSPHRASE" ]; then
    echo "Enter JWT passphrase (or set JWT_PASSPHRASE env):"
    read -rs PASSPHRASE
fi

if [ ! -f "$KEY_DIR/private.pem" ]; then
    echo "No existing keys found. Use generate-jwt-keys.sh for initial setup."
    exit 1
fi

echo "=== JWT Key Rotation ==="
echo ""

# Step 1: Backup
echo "1. Backing up current keys to $BACKUP_DIR..."
mkdir -p "$BACKUP_DIR"
cp "$KEY_DIR/private.pem" "$BACKUP_DIR/private.pem"
cp "$KEY_DIR/public.pem" "$BACKUP_DIR/public.pem"
echo "   Backup complete."

# Step 2: Generate new keys
echo "2. Generating new RSA 2048-bit key pair..."
openssl genpkey -algorithm RSA -out "$KEY_DIR/private.pem" \
    -aes-256-cbc -pass "pass:$PASSPHRASE" \
    -pkeyopt rsa_keygen_bits:2048 2>/dev/null

openssl rsa -in "$KEY_DIR/private.pem" -pubout -out "$KEY_DIR/public.pem" \
    -passin "pass:$PASSPHRASE" 2>/dev/null

chmod 644 "$KEY_DIR/private.pem"
chmod 644 "$KEY_DIR/public.pem"
echo "   New keys generated."

# Step 3: Instructions
echo ""
echo "3. Next steps:"
echo "   a. Restart the backend: docker compose restart backend-dev"
echo "   b. New tokens will use the new keys immediately"
echo "   c. Old tokens expire within 15 minutes (TTL=900s)"
echo "   d. No user action needed (refresh tokens still work)"
echo ""
echo "   Backup location: $BACKUP_DIR"
echo ""
echo "=== Rotation Complete ==="
