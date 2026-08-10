# Disclaimer & Responsible Use

ScamBuster is a **defensive security research tool**. It engages inbound scam email — mail that a scammer sends to a honeypot mailbox first — to extract threat intelligence in a controlled, policy-gated, auditable way. It is published for researchers, CERTs, SOC teams, and defenders.

Read this before deploying it.

## Operator responsibility

Operating ScamBuster is **your** responsibility. By deploying it you accept that:

- **You confirm it is legal where you operate.** Automated engagement with third parties, collection of personal data, and honeypot operation are regulated differently across countries and sectors. Nothing here is legal advice — seek your own.
- **It is inbound-only, and must stay that way.** ScamBuster replies only to senders who contact a honeypot first. Do not modify it to initiate contact, target specific individuals, harass, intimidate, dox, or retaliate against anyone.
- **You are the data controller.** For any data you ingest you must apply the relevant lawful basis, data minimization, retention limits, and subject rights (e.g. GDPR Article 6(1)(f) where applicable). The project ships guardrails (PII filtering, retention, encryption at rest) but the obligations are yours.
- **You keep it within its safety envelope.** The rate limits, content filters (PolicyGuard, LLM validator), kill switch, and audit trail exist to keep engagement safe. Disabling them is at your own risk.

## What ScamBuster does NOT do

- It does not access, scan, or attack any external system.
- It does not initiate contact with anyone.
- It does not generate forged documents, fake identity papers, or fraudulent financial instruments.
- It does not impersonate real people or real organizations without operator-configured, fictional decoy identities.

## On forks and the guardrails

ScamBuster is MIT-licensed, and we are honest about what that means: the license
**legally permits** a fork to remove the inbound-only enforcement, PolicyGuard,
the payment-instigation guard, the export holds, or any other safeguard. We
chose a permissive license so defenders can adopt and adapt the platform freely
— not to bless weaponized derivatives.

So this is stated plainly as the project's position rather than pretended as a
license term:

- The safety envelope is **load-bearing, not decorative**. A build that
  initiates contact, targets individuals, or strips the content guards is not
  ScamBuster — it is a different tool that its operator owns morally and
  legally, alone.
- Do not use the ScamBuster name, branding, or the authors' names to
  distribute or legitimize such a derivative.
- Upstream will not accept contributions that weaken the safety envelope, and
  will not support, document, or debug removed-guardrail deployments.

## No warranty

ScamBuster is provided **"as is"** under the [MIT License](LICENSE), without warranty of any kind, express or implied. The authors and contributors are **not liable** for any claim, damage, or other liability arising from its use or misuse, including any unlawful or unethical deployment by an operator.

If you are unsure whether your intended use is lawful and ethical, **do not deploy it**.
