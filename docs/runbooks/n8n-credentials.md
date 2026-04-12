# n8n credentials runbook

> **Spec 065c** — operational guide for managing n8n stored credentials
> (IMAP, SMTP, API tokens used by ScamBuster workflows).

## Background

Since the 2026-03-31 migration (commit `b090e31`), n8n holds the
production IMAP credentials for the honeypot mailboxes. The previous
HashiCorp Vault adapter was removed in spec 065c (April 2026) because
it was dead code on the n8n hot path.

n8n stores **all workflow credentials** (IMAP, SMTP, OpenAI API key,
Anthropic API key, etc.) encrypted at rest in
`./data/n8n/credentials/` using the `N8N_ENCRYPTION_KEY` environment
variable as the key.

## Threat model

- **DB exfiltration**: n8n credentials are NOT in PostgreSQL — they
  live in the host filesystem under `./data/n8n/`. A SQL injection
  on the application DB does not leak them.
- **Filesystem exfiltration**: if `./data/n8n/credentials/` is leaked
  WITHOUT `.env`, the attacker has only ciphertext.
- **Joint exfiltration**: if BOTH `./data/n8n/credentials/` AND `.env`
  are leaked together, all credentials are compromised. This is the
  primary scenario the rotation procedure protects against.
- **Operator backup discipline**: backups MUST store the encryption
  key separately from the credential blob. A single tar.gz of the
  whole project directory defeats the encryption.

## Backup procedure

Two separate backups must exist at all times:

1. **Encrypted credential blob** (low rotation, weekly):
   ```bash
   tar -czf "n8n-creds-$(date +%Y-%m-%d).tar.gz" ./data/n8n/credentials/
   gpg --encrypt --recipient ops@example.com "n8n-creds-$(date +%Y-%m-%d).tar.gz"
   # Upload to backup destination
   ```

2. **Encryption key** (separate destination):
   - Store `N8N_ENCRYPTION_KEY` value in a password manager
     (1Password, Bitwarden, KeePass) under a dedicated entry titled
     "ScamBuster — N8N_ENCRYPTION_KEY"
   - The password manager backup is your separate destination
   - **NEVER** include `.env` in the same tar.gz as `./data/n8n/`

## Routine rotation procedure

Run this every 6 months OR after any operator handover.

1. **Stop n8n** (the only service that needs to stop):
   ```bash
   docker compose stop n8n
   ```

2. **Export the current credentials with the OLD key** (decrypted):
   ```bash
   docker compose run --rm n8n n8n export:credentials \
     --output=/tmp/n8n-creds-decrypted.json \
     --decrypted
   ```
   This file is in **plaintext** — handle with care, never push to git.

3. **Generate a new key**:
   ```bash
   openssl rand -hex 32
   # Copy the output, you will paste it in step 4
   ```

4. **Update `.env`** with the new key:
   ```bash
   # Edit .env, replace N8N_ENCRYPTION_KEY=...
   ```

5. **Wipe the old encrypted credentials**:
   ```bash
   rm -rf ./data/n8n/credentials/*
   ```

6. **Restart n8n** (it will boot with the new key, no credentials):
   ```bash
   docker compose up -d n8n
   sleep 10  # let it bootstrap
   ```

7. **Re-import the credentials with the new key**:
   ```bash
   docker compose run --rm n8n n8n import:credentials \
     --input=/tmp/n8n-creds-decrypted.json
   ```

8. **Verify** by triggering a test workflow execution from the n8n UI
   and confirming the credentials decrypt correctly.

9. **Securely delete the plaintext export**:
   ```bash
   shred -u /tmp/n8n-creds-decrypted.json
   ```

10. **Update the password manager** entry with the new key value and
    the rotation date.

11. **Document** the rotation in `local/operations-log.md` (or
    equivalent).

## Post-incident rotation procedure

Run this immediately if you suspect `.env` has been leaked or any
operator with key access has departed.

1. Follow the routine rotation steps 1-11 above.
2. **Additionally rotate the underlying credentials themselves** —
   the encryption key change does not invalidate the credential
   passwords on the upstream services (Gmail, OpenAI, etc.). For
   each credential:
   - Gmail / IMAP honeypots: change the app password
   - OpenAI / Anthropic API keys: revoke and regenerate
   - SMTP relays: rotate the auth token
3. Re-import the freshly-rotated credentials in step 7.
4. Audit the git history for any accidental key commits:
   ```bash
   git log -p --all -- .env 2>&1 | grep -i "n8n_encryption_key" || echo "OK"
   ```
5. File an incident report.

## Recovery: encryption key lost

This is the worst-case scenario. There is no way to decrypt
`./data/n8n/credentials/` without the original key.

1. **Wipe the entire credentials directory**:
   ```bash
   docker compose stop n8n
   rm -rf ./data/n8n/credentials/*
   ```
2. **Generate a fresh key** and update `.env`:
   ```bash
   openssl rand -hex 32  # paste into .env
   ```
3. **Restart n8n**:
   ```bash
   docker compose up -d n8n
   ```
4. **Re-create every credential by hand** in the n8n UI:
   - IMAP honeypot accounts (one per honeypot)
   - SMTP relay (if used for outbound replies)
   - LLM API keys (OpenAI, Anthropic)
5. **Trigger a test workflow** for each credential and verify it
   succeeds.
6. **Document** the loss + recovery in the incident log.

## Verification commands

```bash
# Confirm n8n is running with the current key
docker compose exec n8n env | grep N8N_ENCRYPTION_KEY

# List existing credentials (without exposing values)
docker compose exec n8n n8n list:credentials

# Test a credential by manually triggering a workflow execution
# from the n8n UI: http://localhost:5678
```

## References

- n8n CLI documentation: https://docs.n8n.io/hosting/cli-commands/
- Spec 065c: `specs/065c-sprint1-ops-hardening/`
- Migration commit that introduced n8n IMAP storage: `b090e31` (2026-03-31)
- Removal of legacy Vault dead code: `065c-merged` tag on `roadmap/065-security-quality`
