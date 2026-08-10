# Compliance & Governance

Operator-facing compliance material for deploying ScamBuster responsibly. ScamBuster ships
the technical controls; the **operator is the data controller** and completes the
operator-specific fields.

| Document | Purpose |
|----------|---------|
| [data-classification.md](data-classification.md) | Data classes, inventory, why bodies are plaintext, retention |
| [gdpr-record-of-processing.md](gdpr-record-of-processing.md) | GDPR Article 30 record template (lawful basis Art 6(1)(f)) |
| [data-processing-agreements.md](data-processing-agreements.md) | LLM-provider DPAs — or avoid them with Ollama/mock |
| [breach-notification-procedure.md](breach-notification-procedure.md) | GDPR Article 33/34 breach handling |
| [risk-register.md](risk-register.md) | Living risk register (seeded from the security audit) |
| [mule-victim-account-policy.md](mule-victim-account-policy.md) | Financial IOCs as potential mule/victim data — export hold until analyst confirmation |

Related runbooks: [incident-response-plan.md](../runbooks/incident-response-plan.md) (NIST IRP
+ tabletop), [post-mortem-template.md](../runbooks/post-mortem-template.md),
[RACI.md](../runbooks/RACI.md), and the key-rotation runbooks.

See also: [DPIA template](../09_dpia_template.md), [threat model](../10_threat_model.md),
[SECURITY.md](../../SECURITY.md), [GOVERNANCE.md](../../GOVERNANCE.md).
