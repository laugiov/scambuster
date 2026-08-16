# ScamBuster Constitution

This constitution governs how changes are specified, built and accepted in this
repository. It describes the rules that already hold in the codebase; it does not
propose new ones. Where a rule and the code disagree, that is a defect in one of
them and must be raised, not silently reconciled.

**Scope**: `backend-symfony/` and `frontend-react/`. `n8n/`, `infra/` and
`scripts/` are out of scope for the pipelines but remain subject to the security
rules below when a change touches them.

## Core Principles

### I. The layering is the architecture (NON-NEGOTIABLE)

`backend-symfony/src/` has five layers, and dependencies point in one direction
only:

- **`Domain/`** — entities, value objects, domain services, repository
  *interfaces*, domain events. Depends on nothing but PHP and its own types. No
  Doctrine, no Symfony HTTP, no HTTP client, no filesystem, no clock, no
  randomness reached directly. Anything the domain needs from the outside world
  is expressed as an interface it owns.
- **`Application/`** — use cases and orchestration. Depends on `Domain` and on
  the port interfaces under `Application/<Context>/Port/`. Never on
  `Infrastructure`, never on `UI`.
- **`Infrastructure/`** — the adapters. Implements the ports and the `Domain`
  repository interfaces (Doctrine, LLM providers, mailer, SIEM, audit). May
  depend on `Domain` and `Application`. Nothing depends on it at compile time,
  only through the container.
- **`UI/`** — `UI/Http/<Context>/*Controller.php` and `UI/Console/*Command.php`.
  A translation layer: validate input, call one `Application` service, shape the
  response. No business rules, no direct Doctrine access, no LLM calls.
- **`Security/`** — Symfony security plumbing (voters, authenticators, handlers).
  An adapter like `Infrastructure`, kept separate because Symfony wires it
  differently.

Nothing depends on `UI`. Repository interfaces live in `Domain/<Context>/Repository`,
their Doctrine implementations in `Infrastructure/Doctrine/Repository`. This is
ports and adapters; the repository's own vocabulary for it is DDD.

There is no automated enforcement of this rule today — no Deptrac, no PHPStan
boundary rules. It is enforced by review, which is exactly why it is principle I
and why `architecture-reviewer` reads every change that crosses a layer.

### II. Language and framework standards

- PHP **8.3** is the runtime (all three images pin `php:8.3.27`). `composer.json`
  declares a `>=8.2` floor; do not rely on 8.3-only syntax without raising the
  floor first, and raising it is its own change with its own spec.
- `declare(strict_types=1)` in every PHP file. Enforced by PHP-CS-Fixer.
- **PSR-12** plus the rules already configured in
  `backend-symfony/.php-cs-fixer.dist.php`: `strict_param`, ordered imports, no
  unused imports, short array syntax, single quotes, the configured blank-line
  policy. The fixer config is the authority; this document does not restate it.
- Symfony **7.4** idioms: constructor injection, autowiring, attributes for
  routing and validation. No service locator, no `Container::get()` in
  application code.
- Frontend: TypeScript strict (`tsc --noEmit` must pass), ESLint clean, React 19
  function components.

### III. Security rules (NON-NEGOTIABLE)

- **Password hashing must resolve to Argon2id.** `security.yaml` uses
  `algorithm: auto`, which resolves to Argon2id while libsodium is available in
  the runtime image. Any change to hashing configuration, or to the extensions
  present in the image, must state in the PR which algorithm the change resolves
  to and ship a test that asserts it.
- **No reflection-based entity mutation.** Entities are constructed and changed
  through their own constructors and methods — in application code, in fixtures
  and in tests alike. `ReflectionProperty::setValue` on a domain object is a
  blocking objection wherever it appears. A test that needs a state the domain
  cannot express is telling you the domain is missing a method.
- **No check-then-insert.** A read followed by a conditional write is a race, not
  a guard. Uniqueness is enforced by a database constraint and handled on
  violation, or serialized with `symfony/lock` (already a dependency). "It has
  never happened in practice" is not evidence.
- **Input is validated at the adapter boundary.** `UI/Http` DTOs plus
  `symfony/validator`, before anything reaches `Application`. Layers below assume
  validated input and must not re-derive it defensively.
- **Secrets never enter the repository.** Gitleaks scans full history on every PR
  and fails the build on any finding. Encrypted persistence goes through
  `Infrastructure/Doctrine/Type/EncryptedStringType`.
- **This system sends email to third parties.** Changes to prompts, to
  `Application/LLM/PolicyGuard`, to `Application/Guard/**` or to the mailer path
  are safety changes: they decide what an unattended system says to a real person
  and who receives it. They are reviewed as security changes, not as features.

### IV. Testing rules (NON-NEGOTIABLE)

- Every behaviour change ships with tests in the same PR.
- **A bug fix ships with a failing reproduction test, committed before the fix.**
  The test's first commit must demonstrate the bug; the fix's commit turns it
  green. A fix whose test was written after the fix is not accepted, because it
  proves only that the code does what it now does.
- A security fix ships with a failing exploit test (PoC) before the fix, same
  rule, same reason.
- New HTTP controllers get a functional test, even though CI does not run the
  `functional` suite (`phpunit.ci.xml` omits it deliberately). `make test` runs it
  locally and the human gate covers it.
- Tests state intent. A test named after the method it calls documents nothing.

### V. Traceability

Every requirement in a spec carries an ID. Every task cites the requirement IDs
it implements. Every commit in a feature pipeline cites at least one. A change
nobody can trace back to a requirement is either undocumented scope or dead work,
and CI fails the PR for it.

## Definition of Done

A change is done when all of the following hold. This list is the contract the
gates check; it is not aspirational.

1. `make test` is green (unit + integration + functional, in the test container).
2. `make stan` is clean — **PHPStan level 8 over `backend-symfony/src`**, which is
   what `phpstan.neon` configures and what CI actually runs. (`phpstan.dist.neon`
   at level 6 is unused and the `composer phpstan` script's `--level=max` is not
   the gate; see `factory/found-issues.md`.)
3. Code style is clean: `php-cs-fixer fix --dry-run --diff` reports nothing, which
   is what CI runs. Note that **`make cs-fixer` is the fixer, not the check** — it
   runs without `--dry-run` and rewrites files. Run it, then confirm it left the
   worktree unchanged.
4. `composer audit` reports no new advisory; Gitleaks is clean. Both fail the CI
   `security` job. Whether that job is *required* to merge is a branch-protection
   setting outside this repository's files.
5. If `frontend-react/` was touched: `npm run typecheck`, `npm run lint`,
   `npm run test` and `npm run build` all pass.
6. Coverage is not below the base branch.
7. Requirement coverage is traceable end to end: spec → tasks → commits.
8. The gate reports for the pipeline are attached to the PR.

## Governance

This constitution supersedes habit and precedent. Where it conflicts with a
review comment, the constitution wins; where it conflicts with the code, someone
raises it rather than choosing silently.

- Amendments are pull requests against this file, with the reason stated. Bumping
  a rule from advisory to non-negotiable, or removing a non-negotiable rule, is a
  MAJOR version bump.
- The reviewer subagents in `.claude/agents/` and the gates in `factory/gates.yaml`
  implement this document. When it changes, they are updated in the same PR or the
  PR is incomplete.
- Human gates are never auto-passed. In the security pipeline they cannot be
  skipped by any configuration.
- **The factory never merges.** Every pipeline ends in a pull request; a human
  merges it.
- The unresolved questions recorded in `factory/DISCOVERY.md` §6 and the defects
  in `factory/found-issues.md` are open items against this document, not
  exceptions to it.

**Version**: 1.0.0 | **Ratified**: 2026-08-16 | **Last Amended**: 2026-08-16
