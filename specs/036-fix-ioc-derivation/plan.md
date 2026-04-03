# 036 — Plan: Fix IOC Post-Processing

## Step 1: Implement domain/IP derivation from URLs

1. Create `deriveAdditionalIocs(array $iocs): array` in `IocExtractor` or `IocHandler`
2. For each URL IOC: parse host → if not IP, add as domain IOC; if IP, add as ipv4 IOC
3. Deduplicate against already-extracted IOCs
4. Call this after LLM extraction completes

## Step 2: Implement domain derivation from emails

1. For each email IOC: extract domain part after @
2. Skip common providers (gmail.com, yahoo.com, outlook.com, hotmail.com, proton.me, protonmail.com, live.com)
3. Add remaining domains as IOC if not already present

## Step 3: Enhance LLM prompt for missing types

1. Read current LLM prompt in `IocExtractor.php`
2. Add explicit examples for:
   - Telegram: `@username → telegram_username`
   - Discord: `user#1234 → discord_username`
   - CVE: `CVE-2024-12345 → cve`
3. Add example for Skype: `skype:username → skype_id`

## Step 4: Write unit tests

1. Test: `deriveAdditionalIocs` with URL → domain derived
2. Test: URL with IP host → ipv4 derived
3. Test: email with suspicious domain → domain derived
4. Test: email with gmail.com → domain NOT derived
5. Test: duplicate domain not added twice
6. Test: already-extracted domain not duplicated

## Step 5: Write integration test with real email template

1. Ingest Email 1 template (has 2 URLs, 2 emails)
2. Verify all expected IOCs including derived domains
3. Verify recall ≥ 90%

## Step 6: CI verification
