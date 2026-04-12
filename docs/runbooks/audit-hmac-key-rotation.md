# Audit HMAC key rotation runbook

> **Spec 065f** — operational guide for managing `AUDIT_HMAC_KEY`.

## Background

Since spec 065f, every row in `audit_log` carries a `row_hmac` field
computed as `HMAC-SHA256(key, prev_hmac || canonical_json(row))`. This
forms a tamper-evident chain: modifying any row invalidates every
subsequent HMAC.

The key is `AUDIT_HMAC_KEY` (32 bytes = 64 hex chars) in `.env`.

## Verification

```bash
docker compose exec backend-dev php bin/console app:audit:verify-chain
# Expected: "Verified N rows, 0 mismatches"
# Exit code 0 = chain intact, 1 = tamper detected
```

## Routine rotation

1. Stop the backend (prevents concurrent writes):
   ```bash
   docker compose stop backend-dev scheduler
   ```
2. Generate a new key:
   ```bash
   NEW_KEY=$(openssl rand -hex 32)
   ```
3. Run a rebuild script that re-chains all rows with the new key
   (same logic as the backfill in `Version2026041200100000.php`).
4. Update `.env` with the new key.
5. Restart the backend.
6. Verify: `app:audit:verify-chain` → 0 mismatches.

## Post-incident rotation (key leaked)

Same as routine rotation, but additionally review the audit log
for suspicious modifications between the leak time and the rotation.

## Key loss

If the key is lost, the chain cannot be verified for historical rows.
New rows will start a fresh chain with the new key. The historical
rows' `row_hmac` values become unverifiable but the data itself is
not lost.

## References

- Spec 065f: `specs/065f-sprint2-audit-immutability/`
- Command: `app:audit:verify-chain`
- Migration: `Version2026041200100000.php`
