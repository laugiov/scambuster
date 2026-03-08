# Security Policy

## Supported Versions

| Version | Supported          |
|---------|--------------------|
| main    | Yes                |

## Reporting a Vulnerability

If you discover a security vulnerability in ScamBuster, please report it responsibly:

1. **Do NOT open a public GitHub issue** for security vulnerabilities
2. Send a private report via [GitHub Security Advisories](../../security/advisories/new)
3. Or contact the maintainer directly via GitHub

### What to include

- Description of the vulnerability
- Steps to reproduce
- Potential impact
- Suggested fix (if any)

### Response timeline

- **Acknowledgment**: Within 48 hours
- **Initial assessment**: Within 1 week
- **Fix and disclosure**: Coordinated with reporter

## Security Design Principles

ScamBuster follows a secure-by-design approach:

- **No secrets in code**: All sensitive values via environment variables
- **Input validation**: All external inputs sanitized and validated
- **Output filtering**: LLM responses pass through PolicyGuard + LLM Validator
- **Rate limiting**: API and LLM call rate limits enforced
- **Audit trail**: All operations logged for traceability
- **Kill switch**: Immediate system halt at multiple levels
- **GDPR considerations**: Data minimization, retention limits, encryption

See [preview/docs/04_security_guardrails.md](preview/docs/04_security_guardrails.md) for the full security documentation.
