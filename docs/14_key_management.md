# Key Management

## JWT Keys (RS256)

ScamBuster uses RSA 2048-bit keys for JWT signing (RS256 algorithm). The private key signs tokens, the public key verifies them.

### Key Locations

| Key | Path | Permissions | Purpose |
|-----|------|-------------|---------|
| Private | `config/jwt/private.pem` | 644 | Signs JWT tokens |
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

### Vault Integration

IMAP credentials are stored in HashiCorp Vault (`secret/scambuster/imap/`). JWT keys are stored on filesystem. Future improvement: store JWT keys in Vault for centralized management.

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
