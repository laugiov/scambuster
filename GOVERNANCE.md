# Governance

## Decision-Making

ScamBuster follows a **Benevolent Dictator** governance model. The project maintainer makes final decisions on:

- Feature acceptance and roadmap priorities
- Architecture and technology choices
- Release timing and versioning
- Community guidelines enforcement

## Contribution Workflow

1. **Discuss first**: Open an issue or discussion before starting significant work
2. **Fork and branch**: Create a feature branch from `main`
3. **Follow conventions**: See [CONTRIBUTING.md](CONTRIBUTING.md) for code style and commit format
4. **Pull request**: Submit a PR with a clear description and test evidence
5. **Review**: The maintainer reviews within 48 hours on business days
6. **Merge**: Approved PRs are merged by the maintainer

## Release Process

- **Versioning**: [Semantic Versioning](https://semver.org/) (MAJOR.MINOR.PATCH)
- **Changelog**: All changes documented in [CHANGELOG.md](CHANGELOG.md) following [Keep a Changelog](https://keepachangelog.com/)
- **Tags**: Each release is tagged in Git (e.g., `v2.3.0`)
- **Branches**: `main` is the stable branch. Feature work happens on feature branches.

## Security

Security vulnerabilities are handled via [SECURITY.md](SECURITY.md). Do NOT open public issues for security reports.

## Compliance & Security

Operators are the data controllers for their deployment. The compliance and
governance material lives in [docs/compliance/](docs/compliance/README.md):
data classification, GDPR record of processing (Art 30) + DPAs, breach-notification
procedure (Art 33/34), and a living risk register. Operational security procedures --
the [Incident Response Plan](docs/runbooks/incident-response-plan.md), post-mortem
template, [RACI](docs/runbooks/RACI.md), and key-rotation runbooks -- are under
[docs/runbooks/](docs/runbooks/).

## Code of Conduct

All participants must follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Amendments

This governance document can be amended by the maintainer with notice in the changelog.
