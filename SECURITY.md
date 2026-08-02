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

See [docs/04_security_guardrails.md](docs/04_security_guardrails.md) for the full security documentation.

## Responsible Use

ScamBuster is a **defensive research tool** for engaging inbound scam email in a controlled, auditable way. Because it automates conversation with unknown senders, its use carries legal and ethical responsibility that rests entirely with the operator:

- **Legality is your responsibility.** Automated engagement, data collection, and honeypot operation are regulated differently across jurisdictions. Confirm your deployment is lawful where you operate before running it. This project does not constitute legal advice.
- **Inbound only.** The tool engages only after a sender initiates contact. Do not use it to initiate contact, target individuals, harass, dox, or retaliate.
- **Data protection.** You are the data controller for anything you ingest. Apply data minimization, retention limits, and lawful-basis requirements (e.g. GDPR) as they apply to you.
- **No warranty.** Provided "as is" under the MIT License, without warranty of any kind. The authors are not liable for misuse or for any consequence of operating it.

By deploying ScamBuster you accept sole responsibility for operating it lawfully and ethically. See [DISCLAIMER.md](DISCLAIMER.md).
