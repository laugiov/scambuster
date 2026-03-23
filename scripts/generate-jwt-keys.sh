#!/usr/bin/env bash
# Generate RSA 2048-bit key pair for JWT RS256 signing.
# Keys are stored in backend-symfony/config/jwt/
#
# Usage: bash scripts/generate-jwt-keys.sh [passphrase]
# If no passphrase given, reads from JWT_PASSPHRASE env or prompts.

set -euo pipefail

KEY_DIR="/var/www/html/scambuster-github/backend-symfony/config/jwt"
PASSPHRASE="${1:-${JWT_PASSPHRASE:-}}"

if [ -z "$PASSPHRASE" ]; then
    echo "Enter JWT passphrase (or set JWT_PASSPHRASE env):"
    read -rs PASSPHRASE
    if [ -z "$PASSPHRASE" ]; then
        echo "Error: passphrase cannot be empty"
        exit 1
    fi
fi

mkdir -p "$KEY_DIR"

echo "Generating RSA 2048-bit private key..."
openssl genpkey -algorithm RSA -out "$KEY_DIR/private.pem" \
    -aes-256-cbc -pass "pass:$PASSPHRASE" \
    -pkeyopt rsa_keygen_bits:2048 2>/dev/null

echo "Extracting public key..."
openssl rsa -in "$KEY_DIR/private.pem" -pubout -out "$KEY_DIR/public.pem" \
    -passin "pass:$PASSPHRASE" 2>/dev/null

chmod 600 "$KEY_DIR/private.pem"
chmod 644 "$KEY_DIR/public.pem"

echo ""
echo "Keys generated:"
echo "  Private: $KEY_DIR/private.pem (600)"
echo "  Public:  $KEY_DIR/public.pem (644)"
echo ""
echo "Add to .env:"
echo "  JWT_PASSPHRASE=$PASSPHRASE"
