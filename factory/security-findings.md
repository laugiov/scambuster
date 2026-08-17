# Security findings register

Every security finding gets an ID here: the one a `/factory-security` run was
opened for, and every variant that run turned up. Variants are **logged here and
fixed in their own pipeline run** — never in the PR that fixed the original.

Statuses: `open` (no run started), `in-progress` (a run is live), `fixed` (PR
merged, exploit test in the suite), `not-a-defect` (assessed, with the reason
recorded).

## Format

| ID | Class | Location | Impact | Found by | Status |
|---|---|---|---|---|---|
| `SEC-001` | Vulnerability class, named precisely | `file:line` | What an attacker gets, and the preconditions | The `SEC-###` run whose variant analysis found it, or "reported" | one of the statuses above |

## Register

| ID | Class | Location | Impact | Found by | Status |
|---|---|---|---|---|---|
| `SEC-001` | Hardcoded credentials in source | `backend-symfony/src/Application/Meta/PreprodCopyService.php:15` | A preprod host with a throwaway password, so impact is low — but it is a credential in a public repository, and it will be copied the next time someone needs a DSN. Gitleaks does not match it: `.gitleaks.toml` looks for secret *shapes*, and a weak internal password inside a DSN is not one. | reported (setup, Semgrep rule `hardcoded-dsn-credentials`) | open |

<!--
When adding an entry, keep the register append-only: never delete a row, change
its status instead. A removed row is indistinguishable from a finding nobody
ever looked at.
-->
