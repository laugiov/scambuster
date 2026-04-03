# 036 — Fix IOC Post-Processing: Domain Derivation + Missing Types

## Problem

Three IOC extraction gaps identified during the pipeline validation test (6 emails, 78% recall):

### 1. Domains not derived from URLs/emails (5/6 emails affected)

When the LLM extracts `https://secure-test-verify.com/restore`, the domain `secure-test-verify.com` is NOT automatically extracted as a separate IOC. The LLM sometimes extracts domains independently (1/6 emails) but this is non-deterministic and unreliable.

Domains are the **most reusable IOC for threat intelligence** — they outlive any specific URL or email address. Missing them is a significant CTI gap.

### 2. Telegram username not extracted (Email 4)

`@sarah_mitchell_real` was not captured as `telegram_username`. The LLM either doesn't recognize Telegram handles or the validator rejects them.

### 3. CVE not extracted (Email 5)

`CVE-2024-TEST-9999` was not captured. Either the LLM doesn't extract CVE identifiers or the format with "TEST" fails validation.

### 4. IP not derived from URL (Email 1)

`http://203.0.113.88/backup-verify` was captured as URL but the IP `203.0.113.88` was not extracted as a separate `ipv4` IOC.

## Solution

### A. Post-extraction domain/IP derivation (deterministic, not LLM-dependent)

Add a post-processing step in `IocExtractor` or `IocHandler` that runs **after** LLM extraction:

```php
private function deriveAdditionalIocs(array $extractedIocs): array
{
    $derived = [];
    $existingValues = array_column($extractedIocs, 'value');

    foreach ($extractedIocs as $ioc) {
        // Derive domain from URL
        if ($ioc['type'] === 'url') {
            $parsed = parse_url($ioc['value']);
            $host = $parsed['host'] ?? null;
            if ($host && !in_array($host, $existingValues) && !filter_var($host, FILTER_VALIDATE_IP)) {
                $derived[] = ['type' => 'domain', 'value' => $host];
                $existingValues[] = $host;
            }
            // Derive IP from URL (when host is an IP)
            if ($host && filter_var($host, FILTER_VALIDATE_IP)) {
                if (!in_array($host, $existingValues)) {
                    $derived[] = ['type' => 'ipv4', 'value' => $host];
                    $existingValues[] = $host;
                }
            }
        }

        // Derive domain from email
        if ($ioc['type'] === 'email') {
            $parts = explode('@', $ioc['value']);
            $domain = $parts[1] ?? null;
            if ($domain && !in_array($domain, $existingValues)) {
                // Skip common providers (gmail, yahoo, outlook, proton)
                $skipDomains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'proton.me', 'protonmail.com'];
                if (!in_array(strtolower($domain), $skipDomains)) {
                    $derived[] = ['type' => 'domain', 'value' => $domain];
                    $existingValues[] = $domain;
                }
            }
        }
    }

    return array_merge($extractedIocs, $derived);
}
```

### B. Enhance LLM prompt for Telegram/Discord/CVE

Add explicit examples in the `IocExtractor` LLM prompt:

```
- Telegram: @username_here → type "telegram_username"
- Discord: user#1234 → type "discord_username"
- CVE: CVE-2024-12345 → type "cve"
```

### C. Skip common email provider domains

When deriving domains from emails, skip known legitimate providers (gmail.com, yahoo.com, outlook.com, proton.me, etc.) — these are not IOCs.

## Files to modify

| File | Change |
|------|--------|
| `src/Application/Communication/IocExtractor.php` | Add `deriveAdditionalIocs()` post-processing; enhance LLM prompt for telegram/cve |
| `src/Application/Communication/IocHandler.php` | Call derivation after extraction |
| `tests/Unit/Application/Communication/IocExtractorTest.php` | Test domain derivation from URL/email; test IP derivation from URL |
| `tests/Unit/Application/Communication/IocDerivationTest.php` | New test: domain from URL, domain from email, IP from URL, skip gmail.com |

## Acceptance criteria

1. Every URL IOC produces a derived domain IOC (unless domain already extracted)
2. URLs with IP hosts (e.g., `http://203.0.113.88/path`) produce a derived ipv4 IOC
3. Email IOCs from suspicious domains produce derived domain IOCs
4. Common email providers (gmail, yahoo, outlook, proton) are NOT derived as domain IOCs
5. Telegram usernames (`@handle`) are extracted
6. CVE identifiers are extracted
7. Recall on the 6 test emails improves from 78% to ≥ 90%
8. Zero false positives maintained
9. `make test` passes
10. CI green (PHPStan, CS-Fixer, tests)
