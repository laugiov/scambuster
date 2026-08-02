# EndToEnd (E2E) Tests for ScamBuster API Authentication and Security

- Each subfolder reflects the tested functional domain (e.g., `Auth/`).
- E2E tests use the real application stack (DB, hash, JWT, roles, etc.).

### Running End-to-End Tests in a Dedicated Environment

1. Create the database and apply migrations:
   ```bash
   make endToEndTest
   ```
   This command loads E2E fixtures, creates the test database, applies migrations, and runs the full E2E test suite.

## Execution
- E2E tests are tagged with `@group endtoend`.
- They run in the `test` environment but activate the real business logic via the environment variable `E2E_AUTH_REAL=1`.
- Do not mock business services in these tests.
- One test = one complete user scenario.

## References
- See the project coding standards and testing guidelines in the `docs/` directory.
