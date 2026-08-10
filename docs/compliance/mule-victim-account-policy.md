# Mule / Victim Account Policy

**Status**: Active · **Enforced in code**: yes (see below) · **Owner**: operator

## The problem this policy addresses

The platform elicits and stores financial identifiers (IBANs, wallet addresses,
account numbers) revealed by scammers. A significant share of the bank accounts
used in fraud are **money-mule accounts**, and mule-account holders are
frequently **scam victims themselves** (romance-scam "money transfer agents",
job-scam "payment processors"). Treating every elicited IBAN as "threat-actor
infrastructure" and sharing it in CTI feeds can therefore put an innocent
person's bank account on consumer blocklists — a concrete, hard-to-reverse harm
to a data subject who is a victim, not a perpetrator.

This is the platform's most significant residual privacy risk, and it is *not*
solved by the inbound-only architecture: inbound-only prevents contacting third
parties, it does not prevent third-party data from *entering* the store via the
adversary's own messages.

## Policy

1. **Classification.** Financial IOCs (`IocCategory::FINANCIAL_TYPES`: iban,
   bic, swift, bank_account, routing_number, wallet_*, credit_card) are treated
   as **potential third-party victim data**, not presumptively as threat-actor
   infrastructure. The semantic-role enrichment (`MONEY_MULE_ACCOUNT` role in
   the IOC context) makes the suspicion explicit where the LLM detects it.

2. **Hold before export.** A financial IOC is **withheld from every egress
   surface** (TAXII feed, STIX bundle exports, MISP export, flat IOC feed,
   cluster STIX export) until a human analyst records a `confirmed` verdict via
   the analyst-feedback loop (docs/24_analyst_feedback.md). Internal UI views
   are intentionally NOT filtered — the analyst must see held IOCs to review
   them.

3. **False positives never ship.** An IOC with an analyst `false_positive`
   verdict is excluded from every egress surface, whatever its type. (Before
   this policy it was still exported with a reduced confidence score.)

4. **Analyst review standard.** Before confirming a financial IOC, the analyst
   should weigh: recurrence across independent conversations/clusters, the
   semantic role assigned by enrichment, and any indication the account holder
   is a directed third party ("my agent", "my assistant's account", romance
   framing). When in doubt: do not confirm; report to the account's institution
   instead (see §6).

5. **Retention.** Financial IOCs follow the platform's standard IOC retention.
   A `false_positive` verdict is the signal that an identifier should also be
   considered for purge under the operator's data-hygiene process.

6. **Proper disclosure channel.** The abuse-report path (targeted disclosure to
   the hosting provider / financial institution of record) is intentionally
   NOT gated by this policy: reporting a suspect account *to its own bank* is
   the correct, harm-reducing channel for a suspected mule account, and is a
   directed communication — not feed-wide broadcast.

## Enforcement (code)

- `App\Domain\Communication\Policy\IocExportPolicy` — single source of truth
  (PHP predicate + SQL condition).
- Wired into: `TaxiiService` (IOC collection), `IocStixExportHandler`,
  `ConversationStixExportHandler`, `IocFeedExporter` (CSV/NDJSON),
  `ClusterQueryService::getStixExportData` (cluster STIX), `ExportMispController`.
- Cluster TAXII/console exports embed indicator *references* only (no values);
  the reference-only shape is unaffected.
- Tests: `IocExportPolicyTest` (truth table),
  `TaxiiFinancialIocGateTest` (feed-level hold/release/false-positive).

## GDPR reconciliation

The DPIA (docs/09_dpia_template.md) no longer claims "no processing of data
from innocent third parties". The accurate claim is: the system never
*contacts* third parties (inbound-only, code-enforced), and data that may
belong to third parties — above all financial identifiers — is **quarantined
from dissemination** until human review. This hold is a safeguard in the
Art. 6(1)(f) balancing test for the data-subject category "possible mule/victim
account holder".
