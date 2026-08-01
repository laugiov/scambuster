# TOTP encryption key rotation runbook

> Operational guide for managing `TOTP_ENCRYPTION_KEY`.

## Background

`app_users.totp_secret` is encrypted at rest using libsodium `secretbox`
with the `TOTP_ENCRYPTION_KEY` env var as the key. The key is a 32-byte random value stored as 64 hex chars
in `.env`.

The encryption layer is transparent to the application: Doctrine's
`EncryptedStringType` custom type encrypts on write and decrypts on
read. No application code handles ciphertext directly.

## Routine rotation (every 6 months)

1. **Stop the backend** to prevent concurrent read/writes:
   ```bash
   docker compose stop backend-dev scheduler
   ```

2. **Export current secrets** (decrypted in memory):
   ```bash
   docker compose exec postgres psql -U postgres scambuster -c \
     "COPY (SELECT id, totp_secret FROM app_users WHERE totp_secret IS NOT NULL) TO '/tmp/totp-export.csv' WITH CSV"
   ```
   Note: this CSV contains ciphertext, not plaintext. To get the
   plaintext you need to decrypt with the OLD key via a PHP script.

3. **Generate a new key**:
   ```bash
   NEW_KEY=$(openssl rand -hex 32)
   echo "New key: $NEW_KEY"
   ```

4. **Write a one-shot PHP migration** that:
   - Reads each row's `totp_secret` ciphertext
   - Decrypts with the OLD key
   - Re-encrypts with the NEW key
   - Updates the row
   This is essentially the same logic as `Version2026041200000000.php`
   but with a key swap.

5. **Update `.env`** with the new key:
   ```bash
   sed -i "s/^TOTP_ENCRYPTION_KEY=.*/TOTP_ENCRYPTION_KEY=$NEW_KEY/" .env
   ```

6. **Restart the backend**:
   ```bash
   docker compose up -d backend-dev scheduler
   ```

7. **Verify** by logging in as a user with TOTP enabled.

8. **Update the password manager** entry with the new key.

9. **Securely delete** the old key from any local notes.

## Post-incident rotation (key leaked)

1. Follow the routine rotation steps above immediately.
2. **Additionally**: if the DB was also leaked, rotate the individual
   TOTP secrets themselves (re-enroll affected users via the admin UI).
3. File an incident report.

## Recovery: key lost

If `TOTP_ENCRYPTION_KEY` is lost, all TOTP secrets are unrecoverable.

1. Disable TOTP for all users:
   ```bash
   docker compose exec postgres psql -U postgres scambuster -c \
     "UPDATE app_users SET totp_secret = NULL;"
   ```
2. Generate a new key and update `.env`.
3. Restart the backend.
4. Notify all affected users to re-enroll their authenticator apps.
5. File an incident report.

## Backup procedure

- Store `TOTP_ENCRYPTION_KEY` in a password manager (1Password,
  Bitwarden, KeePass) under a dedicated entry.
- **NEVER** include `.env` in the same backup archive as the DB dump.
- If both are leaked together, the TOTP secrets are compromised.

## References

- `EncryptedStringType`: `src/Infrastructure/Doctrine/Type/EncryptedStringType.php`
- Migration: `migrations/Version2026041200000000.php`
