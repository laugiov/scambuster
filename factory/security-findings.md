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
| `SEC-002` | Service reachable without authentication because the network is assumed trusted (Redis, no `requirepass`) | `docker-compose.yml:41-54` — the `redis` service declares no `ports:` and no `command:`, so it runs with authentication off and is reachable by anything on the `scambuster` network. Same in `docker-compose.demo.yml:38-46` and `docker-compose.prod.yml:63-72`; `requirepass` appears in no compose file, `.env.dist` or config file in the repository. | **Low — a hardening gap, not an exposure.** Redis is published on the host in **no** compose file, so nothing outside the Docker network can reach it; the one place a host port exists is commented out in `docker-compose.override.yml.example` as an opt-in for local debugging. The reachable-by-n8n half of the report is true of the **development** stack only, where `redis` (`:53-54`) and `n8n` (`:258-259`) share the `scambuster` bridge: a compromised n8n there could poison the cache and manipulate the `LOCK_DSN` locks. Production already blocks that path by topology — `redis` is on `data` (`docker-compose.prod.yml:67`), which is `internal: true` (`:146-147`), and n8n is on `edge` (`:110`), with no route between them. So the defect is that the only control is network placement, with nothing behind it: one added `ports:` line, one service moved onto the wrong network, or one container escape and there is no second layer. | reported (security review of PR #62) | open |

<!--
When adding an entry, keep the register append-only: never delete a row, change
its status instead. A removed row is indistinguishable from a finding nobody
ever looked at.
-->
