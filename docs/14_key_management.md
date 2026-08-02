# Key Management

## JWT Keys (RS256)

ScamBuster uses RSA 2048-bit keys for JWT signing (RS256 algorithm). The private key signs tokens, the public key verifies them.

### Key Locations

| Key | Path | Permissions | Purpose |
|-----|------|-------------|---------|
| Private | `config/jwt/private.pem` | 600 | Signs JWT tokens |
| Public | `config/jwt/public.pem` | 644 | Verifies JWT tokens |
| Passphrase | `.env` (`JWT_PASSPHRASE`) | gitignored | Decrypts private key |

Keys are gitignored (`backend-symfony/config/jwt/`). Each environment must generate its own keys.

### Initial Setup

```bash
bash scripts/generate-jwt-keys.sh
# Follow prompt for passphrase, then add JWT_PASSPHRASE to .env
```

### Key Rotation (Zero Downtime)

```bash
bash scripts/rotate-jwt-keys.sh
docker compose restart backend-dev
```

**How zero-downtime works**: JWT TTL is 15 minutes. When new keys are deployed, existing tokens (signed with old key) remain valid until they expire. The refresh token mechanism seamlessly issues new tokens signed with the new key.

**Rotation schedule**: Every 90 days (recommended by security-by-design framework).

### Emergency Response (Key Compromise)

If the private key is suspected compromised:

1. Generate new keys immediately: `bash scripts/generate-jwt-keys.sh`
2. Deploy to all environments
3. Restart all backend containers
4. All active sessions are invalidated (users must re-login)
5. Investigate: check audit logs for suspicious AUTH_SUCCESS events
6. Create incident report

### Secrets Management

IMAP credentials are stored in environment variables (or Docker secrets for production deployments). JWT keys are stored on filesystem. Future improvement: integrate with a secrets manager for centralized key management.

## Per-Account SMTP DSN Encryption

When multiple honeypot mailboxes are configured (each with its own SMTP relay), each mailbox's SMTP DSN is encrypted at rest in the `mail_account.smtp_dsn_encrypted` column.

**Algorithm**: XSalsa20-Poly1305 via `sodium_crypto_secretbox` (authenticated encryption with random nonce per encryption).

**Key derivation**: BLAKE2b of `APP_SECRET` via `sodium_crypto_generichash` (outputs a 32-byte key directly).

**Storage format**: `base64(nonce || ciphertext || mac)`.

### Impact of `APP_SECRET` rotation

Changing `APP_SECRET` invalidates ALL existing per-account SMTP DSNs. The encrypted ciphertexts become unreadable, and reply sending will fail with `RuntimeException: Failed to decrypt SMTP DSN`.

**Recovery procedure** (if `APP_SECRET` must be rotated):

1. Note the old `APP_SECRET` and the new one.
2. For each account with a custom SMTP DSN:
   - Use the CLI to re-add the account with the same plaintext DSN: `bin/console app:mail-account:rotate-smtp <account-id> --smtp-dsn=...`
3. Verify with `bin/console app:mail-account:list` that all accounts still report `has_custom_smtp: yes`.

A future release will provide a non-disruptive key rotation procedure (re-encrypt all rows in a single command).

### Single-mailbox installs

The default single-mailbox setup uses the global `MAILER_DSN` env var (no encryption). This setup is unaffected by `APP_SECRET` rotation.

## Configuration

```yaml
# config/packages/lexik_jwt_authentication.yaml
lexik_jwt_authentication:
    secret_key: '%kernel.project_dir%/config/jwt/private.pem'
    public_key: '%kernel.project_dir%/config/jwt/public.pem'
    pass_phrase: '%env(JWT_PASSPHRASE)%'
    encoder:
        signature_algorithm: RS256
    token_ttl: 900  # 15 minutes
```
