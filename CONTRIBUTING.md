# Contributing to ScamBuster

Thank you for your interest in contributing to ScamBuster!

## Getting Started

1. Fork the repository
2. Clone your fork: `git clone https://github.com/<your-username>/scambuster.git`
3. Copy the environment template: `cp .env.dist .env` and fill in your values
4. Start the stack: `make up`
5. Install dependencies: `make composer-install`
6. Run migrations: `make migration`
7. Load fixtures: `make fixtures`
8. Run tests to verify everything works: `make test`

## Development Workflow

### Branch naming

- `feat/<description>` for new features
- `fix/<description>` for bug fixes
- `docs/<description>` for documentation changes
- `refactor/<description>` for refactoring

### Commit messages

Use conventional commits:

```
feat(domain): add new scam type classification
fix(ingest): handle duplicate message-id correctly
docs(readme): update setup instructions
test(persona): add unit tests for Thompson sampling
```

### Code quality checks

Before submitting a PR, ensure all checks pass:

```bash
make test          # Run PHPUnit (unit + integration)
make stan          # Static analysis (PHPStan)
make cs-fixer      # Code style (PHP-CS-Fixer)
```

### Architecture guidelines

- Follow **Domain-Driven Design** (DDD) patterns
- Domain layer has zero infrastructure dependencies
- All external calls go through Application services
- New features need unit tests at minimum
- See [docs/03_high_level_architecture.md](docs/03_high_level_architecture.md) for detailed architecture documentation

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes with appropriate tests
3. Ensure all CI checks pass
4. Write a clear PR description explaining the "why"
5. Request a review

## Code of Conduct

- Be respectful and constructive
- Focus on the technical merit of contributions
- ScamBuster is a **defensive research tool** -- contributions must align with ethical and legal guidelines (see [SECURITY.md](SECURITY.md))

## Questions?

Open a GitHub Discussion or Issue for questions about contributing.
