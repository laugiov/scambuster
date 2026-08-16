# Found issues (logged, not fixed)

The factory setup does not touch application code. Anything noticed while
surveying the repo is recorded here for you to triage separately.

| # | Phase | Severity | File | Issue |
|---|---|---|---|---|
| 1 | 0 | low | `backend-symfony/phpunit.xml.dist` | The `<source><exclude>` block lists paths that no longer exist: `src/Service/`, `src/Command/VaultAddImapSecretCommand.php`, `src/Command/VaultDeleteImapSecretCommand.php`, `src/Command/MailAccountOnboardCommand.php`, `src/Command/Preprod*.php`, `src/Command/Test*.php`. `src/` today contains only `Application`, `DataFixtures`, `Domain`, `Infrastructure`, `Security`, `UI`, `Kernel.php`. Stale exclusions silently do nothing, and if a `src/Command/` directory is ever reintroduced it would be excluded from coverage by accident. |
| 2 | 0 | low | `backend-symfony/phpstan.dist.neon` vs `phpstan.neon` vs `composer.json` | Three different PHPStan configurations coexist: level 6 (`phpstan.dist.neon`, unused — `phpstan.neon` takes precedence), level 8 (`phpstan.neon`, what CI and `make stan` actually run), and `--level=max` (the `composer phpstan` script, which also ignores both configs' `paths`). Only one of these is the real gate. |
| 3 | 0 | low | `.github/workflows/ci.yml`, `Makefile` | Both invoke `phpstan analyse src`, and a path argument overrides the config's `paths: [src, tests]`. `tests/` is therefore never statically analysed, even though `phpstan.neon` carries several `ignoreErrors` entries written specifically for `App\Tests\Integration\Auth\*` — those entries can only ever match a run that includes `tests/`. |
| 4 | 0 | informational | `.github/workflows/ci.yml` | The `functional` suite (~855 controller tests) is deliberately excluded from CI and only runs via `make test` on a developer machine. This is documented in the workflow with a rationale, so it is a known trade-off rather than a defect — but it means "CI green" is weaker than "`make test` green", which matters for the factory's definition of done. |
