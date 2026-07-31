#!/usr/bin/env bash
# Prevent re-introduction of Vault dead code.
# This script fails the CI if any Vault reference appears in src/.
#
# Rationale: Vault was removed (April 2026) because it was
# dead code since the 2026-03-31 n8n migration (commit b090e31). n8n now
# stores its own IMAP credentials encrypted with N8N_ENCRYPTION_KEY in
# ./data/n8n/.
#
# If a future spec intentionally re-introduces Vault, it MUST update or
# delete this script and document the reason in its plan.md.

set -euo pipefail

PATTERN='VaultClient\|MailAccountSecretResolver\|VaultAddImapSecret\|VaultDeleteImapSecret\|MailAccountOnboardCommand\|hashicorp/vault'

if grep -rln "$PATTERN" backend-symfony/src/ 2>/dev/null; then
    echo ""
    echo "ERROR: Vault references found in backend-symfony/src/."
    echo ""
    echo "Vault was removed during the security & quality hardening work."
    echo "The n8n IMAP intake holds the production credentials now."
    echo ""
    echo "If you intentionally re-introduce Vault for a different use case"
    echo "(e.g., TOTP encryption, HMAC keys), you MUST:"
    echo "  1. Update or delete .github/scripts/check-no-vault-resurrection.sh"
    echo "  2. Document the reason in your spec's plan.md"
    echo "  3. Re-add the docker-compose.yml vault service if needed"
    echo ""
    exit 1
fi

# Also reject any new VAULT_* env var inside src/ (no test fixtures excepted —
# tests may legitimately reference Vault env vars from past tests if any).
if grep -rln 'VAULT_TOKEN\|VAULT_ADDR' backend-symfony/src/ 2>/dev/null; then
    echo ""
    echo "ERROR: VAULT_TOKEN or VAULT_ADDR env var reference found in src/."
    echo "See above guidance for intentional re-introduction."
    exit 1
fi

echo "OK: no Vault references in backend-symfony/src/"
