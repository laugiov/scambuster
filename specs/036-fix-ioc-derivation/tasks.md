# 036 — Tasks: Fix IOC Post-Processing

## Task 1: Implement domain derivation from URLs
- [ ] Create `deriveAdditionalIocs(array $extractedIocs): array` method
- [ ] For each `type=url` IOC: `parse_url()` → extract host
- [ ] If host is a domain (not IP): add `type=domain` IOC if not already present
- [ ] If host is an IP: add `type=ipv4` (or ipv6) IOC if not already present
- [ ] Handle edge cases: URL without scheme, URL with port, URL with auth

## Task 2: Implement domain derivation from emails
- [ ] For each `type=email` IOC: extract domain part after @
- [ ] Skip known legitimate providers: gmail.com, yahoo.com, outlook.com, hotmail.com, proton.me, protonmail.com, live.com, icloud.com, aol.com
- [ ] Add remaining domains as `type=domain` IOC if not already present
- [ ] Lowercase domain before comparison

## Task 3: Integrate derivation into extraction pipeline
- [ ] In `IocExtractor` or `IocHandler`, call `deriveAdditionalIocs()` after LLM extraction
- [ ] Ensure derived IOCs go through the same normalization pipeline
- [ ] Ensure derived IOCs get stored with `source=derived` (or `source=extraction`)

## Task 4: Enhance LLM prompt for Telegram/CVE
- [ ] Read current prompt in `IocExtractor.php`
- [ ] Add explicit extraction examples:
  - `@sarah_mitchell → type: "telegram_username", value: "@sarah_mitchell"`
  - `CVE-2024-12345 → type: "cve", value: "CVE-2024-12345"`
  - `user#1234 → type: "discord_username", value: "user#1234"`
- [ ] Add to the "rare but important" types section of the prompt

## Task 5: Write unit tests — domain derivation
- [ ] Test: URL `https://evil.com/path` → derives domain `evil.com`
- [ ] Test: URL `http://203.0.113.88/path` → derives ipv4 `203.0.113.88`
- [ ] Test: URL `https://evil.com:8080/path` → derives domain `evil.com` (port stripped)
- [ ] Test: email `scam@evil.com` → derives domain `evil.com`
- [ ] Test: email `user@gmail.com` → domain NOT derived (skip list)
- [ ] Test: email `user@proton.me` → domain NOT derived
- [ ] Test: URL domain already in extracted list → no duplicate
- [ ] Test: two URLs same domain → only one domain derived
- [ ] Test: empty IOC list → returns empty

## Task 6: Write integration tests
- [ ] Ingest email with URL `https://test-domain.com/page` and email `info@test-domain.com`
- [ ] Verify `test-domain.com` appears as domain IOC
- [ ] Verify no duplicate domain IOCs
- [ ] Verify IOC count includes derived IOCs

## Task 7: CI verification
- [ ] `make test` passes
- [ ] `make stan` passes
- [ ] PHP-CS-Fixer clean
- [ ] Run validation emails again → recall ≥ 90%
