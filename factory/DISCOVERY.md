# Factory Discovery — ScamBuster

Read-only survey of the repository, produced in Phase 0 of the software-factory
setup. Everything below was verified against files in the worktree at commit
`c361f21` unless explicitly marked UNVERIFIED.

## 1. Repository shape

This is a **monorepo**, not a single Symfony application. There is no
`composer.json` at the root.

| Component | Path | Stack |
|---|---|---|
| Backend | `backend-symfony/` | PHP / Symfony |
| Frontend | `frontend-react/` | React 19, TypeScript, Vite, Vitest |
| Infra | `infra/docker/`, `docker-compose*.yml` | Docker Compose, Postgres, Redis |
| Workflows | `n8n/` | n8n workflow JSON |
| Scripts | `scripts/`, `Makefile` (root, 344 lines, the entry point for everything) |

## 2. Backend versions and tooling (verified)

- **Symfony 7.4** — `extra.symfony.require: "7.4.*"`, all `symfony/*` at `^7.4.x`.
- **PHP**: `composer.json` requires `php: ">=8.2"`. All three Docker images
  (`infra/docker/backend/Dockerfile`, `Dockerfile.prod`,
  `infra/docker/demo/Dockerfile.backend`) pin `php:8.3.27`. The host PHP in this
  container is 8.4.19, which is *not* the runtime.
- **Tests**: PHPUnit 10.5. Four suites in `phpunit.xml.dist`: `unit`,
  `integration`, `functional`, `endtoend`. Plus `tests/Smoke/` (real-LLM
  harnesses, run via `make smoke-*`, cost money) and `tests/EndToEnd/`.
  - `make test` → `phpunit --testsuite integration,functional,unit` inside the
    `backend-test` container (Docker required; needs Postgres + fixtures).
  - `make endToEndTest` → `endtoend` suite in the `backend-e2e` container.
  - CI uses a *different* config, `phpunit.ci.xml`, and deliberately **does not
    run the `functional` suite** (~855 controller tests) — documented in
    `.github/workflows/ci.yml`. `make test` is the only place they run.
- **Static analysis**: PHPStan 1.12 (+ `phpstan-doctrine`, `phpstan-symfony`).
  Two configs exist and disagree — see §6.
- **Code style**: PHP-CS-Fixer 3.95, `@PSR12` + `declare_strict_types`,
  `strict_param`, ordered imports, short arrays. Config covers `src/` only.
- **Mutation testing**: Infection 0.31 (`make mutation`, min MSI 70 / covered 80,
  unit tests only). Not in CI.
- **Rector** 1.2 is installed with a `rector.php`; no Make target, not in CI.
- **Migrations**: Doctrine, 67 versions in `backend-symfony/migrations/`.

## 3. CI (verified — `.github/workflows/ci.yml`)

Six jobs, all Docker-Compose based, on push to `main`/`demo` and PRs to `main`:

1. `static-analysis` — `phpstan analyse src --memory-limit=1G`, then a
   "vault resurrection" guard script.
2. `code-style` — `php-cs-fixer fix --dry-run --diff`.
3. `backend-tests` — unit + integration with clover coverage, compiler-pass tests
   isolated, then E2E with coverage; both uploaded to **Codecov**
   (`codecov.yml`, `target: auto` for project and patch).
4. `security` — `composer audit` (fails on new advisories) + **Gitleaks** CLI
   over full history with `.gitleaks.toml`.
5. `frontend` — `tsc --noEmit`, eslint, vitest, production build.
6. `container-security` — **Trivy** CRITICAL/HIGH (`ignore-unfixed`, `.trivyignore`)
   on the dev/prod/demo images + CycloneDX SBOM artifacts.

`guard-nightly.yml` runs the weekly real-LLM GUARD regression (Sundays 05:00 UTC,
needs `LLM_API_KEY`, skipped gracefully without it).

Existing PR template: `.github/pull_request_template.md` (Summary / Changes /
Type of Change / Checklist). No machine-readable fields today.

## 4. Architecture (verified by directory layout)

`backend-symfony/src/` has exactly five entries plus `Kernel.php`:

```
Domain/          entities, value objects, repository *interfaces*, domain services
Application/     use cases, DTOs, ports (Application/<Ctx>/Port/*)
Infrastructure/  Doctrine entities+repositories, LLM providers, mailer, SIEM, auth adapters
UI/              UI/Http/<Context>/*Controller.php, UI/Console/*Command.php
Security/        Symfony security plumbing (voters, authenticators, handlers)
DataFixtures/    Doctrine fixtures
```

`CONTRIBUTING.md` calls this **DDD** ("Domain layer has zero infrastructure
dependencies", "all external calls go through Application services"), and the
port/adapter split is real (`Application/*/Port` interfaces implemented in
`Infrastructure/*`). It is hexagonal in substance; the repo's own vocabulary is
DDD. **No automated boundary enforcement exists** (no Deptrac, no PHPAT, no
PHPStan boundary rules) — the layering is convention only.

Conventions in use: conventional commits, branch prefixes `feat/`, `fix/`,
`docs/`, `refactor/` (`CONTRIBUTING.md`).

## 5. Proposed security-sensitive paths

Starting set was auth, crypto, session, persona, upload. Adjusted to what exists.
All paths are repo-relative. **Confirmed** = I found the code and it is clearly
security-relevant; **proposed** = judgement call, tell me to keep or drop.

### Confirmed

| Area | Paths |
|---|---|
| Auth (code) | `backend-symfony/src/UI/Http/Auth/**`, `src/Application/Auth/**` (incl. `Oidc/`, `TotpVerifier.php`, `LoginHashGenerator.php`), `src/Infrastructure/Auth/**`, `src/Security/**` (voters, `TaxiiApiKeyAuthenticator`, `CustomAccessDeniedHandler`, `SecretPolicy`) |
| Auth (config) | `backend-symfony/config/packages/security.yaml`, `lexik_jwt_authentication.yaml`, `scheb_2fa.yaml`, `nelmio_cors.yaml`, `rate_limiter.yaml`, `config/packages/{test,prod,e2e}/security.yaml` |
| Identity model | `backend-symfony/src/Domain/User/**` (`User`, `Permission`, `RefreshToken`, repositories) |
| Crypto | `src/Infrastructure/Doctrine/Type/EncryptedStringType.php`, `src/Application/Communication/Smtp/SmtpDsnEncryptor.php`, `src/Application/Audit/AuditHmacChainer.php`, `src/Application/Audit/**`, `src/Infrastructure/Audit/**` |
| Upload | `src/UI/Http/Communication/UploadAttachmentController.php`, `src/Application/Communication/AttachmentHandler.php`, `src/Application/Communication/Dto/AttachmentInput.php` |
| Persona | `src/UI/Http/Communication/{CreatePersona,UpdatePersona,TogglePersonaActive}Controller.php`, `src/UI/Http/Personas/**`, `src/UI/Http/Scambaiting/SelectPersonaController.php` |
| DB schema | `backend-symfony/migrations/**` |
| Secrets policy | `.gitleaks.toml`, `.env.dist`, `.env.test`, `.env.e2e` |

### Proposed additions (specific to this project — confirm)

| Area | Paths | Why |
|---|---|---|
| Outbound content safety | `src/Application/LLM/Prompt/**`, `src/Application/LLM/PolicyGuard.php`, `src/Application/Guard/**`, `src/Infrastructure/Guard/**`, `src/Domain/Prompt/**` | This system generates and sends email to third parties. A prompt or guard regression is a safety incident, and the repo already treats prompt changes as gate-worthy (GUARD baseline, `make guard`, `guard-nightly.yml`). |
| Outbound mail | `src/Application/Communication/Smtp/**`, `src/Infrastructure/Mailer/**`, `config/packages/mailer.yaml` | Controls who receives generated replies; commit `9fbe52c` was exactly this class of bug. |
| Unauthenticated / machine surfaces | `src/UI/Http/Internal/**`, `src/UI/Http/Taxii/**` | Reached by n8n and by TAXII clients with a static API key, not by an analyst JWT. |
| Supply chain / deploy | `.github/workflows/**`, `infra/docker/**`, `docker-compose*.yml`, `backend-symfony/composer.json`, `composer.lock` | A change here changes what runs in production or what gates a PR. |

### Dropped / uncertain — need your call

- **Session**: the API firewalls are `stateless: true` (JWT). Sessions exist in
  `framework.yaml` (`session: true`, mock-file storage in test) and CSRF is used
  on the login path (`ApiCsrfTokenListener`, `CsrfTokenController`). I propose
  covering this via `security.yaml` + `src/UI/Http/Auth/**` rather than as a
  separate "session" path. Confirm.
- **Frontend**: `frontend-react/src/**` handles the JWT client-side. Not in the
  starting set. Should token storage / auth UI count as sensitive?
- **`n8n/**` workflow JSON**: orchestrates ingestion and sending. Sensitive or not?

## 6. Contradictions with the prompt's assumptions — I need decisions

These block me from writing the constitution. I have not adapted silently.

1. **PHP version.** The prompt says PHP 8.3. The runtime images are 8.3.27, but
   `composer.json` declares `php: ">=8.2"`. Do I write "PHP 8.3 (runtime), floor
   8.2 (composer)" into the constitution as-is, or is aligning `composer.json`
   intended later? (Changing it would be an application-code change — out of
   scope for me.)

2. **Symfony version.** The prompt says "Symfony 7"; the repo pins **7.4.\***.
   I will write 7.4 unless you object.

3. **PHPStan level — three different answers in the repo.**
   - `phpstan.neon` (the one CI and `make stan` actually use, since neither
     passes `-c` and `phpstan.neon` wins over `phpstan.dist.neon`): **level 8**,
     bleedingEdge, paths `src` + `tests`, with an exclusion list.
   - `phpstan.dist.neon`: level 6, paths `bin config public src tests`.
   - `composer.json` script `"phpstan"`: `phpstan analyse src --level=max`.
   Also, both CI and `make stan` pass `src` as an argument, which **overrides**
   the config's `paths` — so `tests/` is never analysed in practice.
   Which is the "configured level" the Definition of Done must reference? My
   proposal: **level 8 over `src` via `phpstan.neon`**, i.e. exactly what CI runs.
   Confirm before I freeze it.

4. **Argon2id.** The prompt's constitution rule says Argon2id for password
   hashing. `security.yaml` configures `algorithm: auto`. On PHP 8.3 with
   libsodium available, `auto` resolves to Argon2id — but I could **not verify**
   that the `sodium` extension is present in the runtime image (the Dockerfile
   installs `mailparse redis pcov` via pecl and `pdo pdo_pgsql zip` via
   `docker-php-ext-install`; `sodium` is bundled in the official `php:8.3-cli`
   image, which I cannot confirm without building it). Options: (a) constitution
   states "password hashing must resolve to Argon2id; `auto` is acceptable only
   while sodium is present", (b) constitution requires an explicit
   `algorithm: argon2id` — which implies an application-config change I am not
   allowed to make. Your call.

5. **Hexagonal vs DDD vocabulary.** The prompt says hexagonal; the repo says DDD
   with the same structure. I propose the constitution use the repo's layer names
   (`Domain` / `Application` / `Infrastructure` / `UI` / `Security`) and state the
   dependency rule explicitly, mentioning "ports and adapters" once. Confirm.

6. **Monorepo.** The prompt assumes one Symfony app. Does the factory govern the
   frontend and n8n too, or backend only? This changes the CI gates in Phase 4
   (coverage comparison, test commands) and the sensitive-path list.

7. **`factory/setup` branch.** The prompt's mode d'emploi says work on
   `factory/setup`; this session was started on
   `claude/scambuster-factory-setup-5eind5` and I am instructed not to push
   elsewhere. I am staying on `claude/scambuster-factory-setup-5eind5`. Say if you
   want it renamed.

## 7. What I could not verify here

- Nothing was executed: `make test`, `make stan`, and `php-cs-fixer` all run
  inside Docker Compose containers that need Postgres and Redis. Docker is
  installed in this environment but I did not start the stack (read-only phase,
  and network/service availability is unknown). **No test or analysis run in this
  session backs any statement above** — all of it is read from configuration.
- Whether the `sodium` extension is present at runtime (see §6.4).
- Actual current coverage numbers (Codecov-side, `target: auto` means "compare to
  base", so there is no numeric floor committed in the repo).

## 8. Proposed constitution outline (for Phase 1)

Subject to the decisions in §6:

1. **Scope** — backend-symfony (+ frontend/n8n per your answer to §6.6).
2. **Layering** — `Domain` depends on nothing but PHP and its own value objects;
   no Doctrine, no Symfony HTTP, no HTTP client, no filesystem in `Domain`.
   `Application` depends on `Domain` and on its own `Port` interfaces only.
   `Infrastructure` implements `Port` interfaces and may depend on
   `Domain`+`Application`. `UI` (Http/Console) is an adapter: it validates input,
   delegates to `Application`, and holds no business logic. `Security` is a
   framework adapter. Nothing depends on `UI`. Repository interfaces live in
   `Domain/*/Repository`, implementations in `Infrastructure/Doctrine/Repository`.
3. **Language & framework standards** — PHP 8.3 runtime, `declare(strict_types=1)`
   everywhere, `@PSR12` + the fixer rules already configured, Symfony 7.4 idioms,
   constructor injection, readonly/final where the codebase already does.
4. **Security rules** — password hashing must resolve to Argon2id; no
   reflection-based entity mutation (setters/named constructors only, including
   in tests and fixtures); write paths must not use check-then-insert (DB unique
   constraint + upsert, or `symfony/lock` which is already a dependency); input
   validation at the adapter boundary (`UI/Http` DTOs + `symfony/validator`),
   never deeper; secrets never in code (Gitleaks is a merge gate); encrypted
   fields go through `EncryptedStringType`.
5. **Testing rules** — every behaviour change ships with tests; a bug fix ships
   with a failing reproduction test committed *before* the fix; new controllers
   need a functional test even though CI does not run that suite (`make test`
   does — the human gate covers it).
6. **Definition of done** — `make test` green, `make stan` clean at level 8 over
   `src`, `make cs-fixer` clean, `composer audit` clean, Gitleaks clean, frontend
   gates green if touched, and requirement-ID traceability from spec → tasks →
   commits.

---

PHASE 0 DONE — see `factory/found-issues.md` for the incidental issues found.
