/**
 * Spec 099 S6 — Frontend-side IOC tiering.
 *
 * Two-tier classification used by the Theater Intelligence panel:
 *
 *   - ACTIONABLE : IOCs an analyst can pivot on right away
 *                  (financial, contact channels, infrastructure).
 *                  Counted in the headline number.
 *
 *   - CONTEXT    : Header / metadata artifacts that ARE useful for
 *                  cross-mailbox correlation and spam-signature matching,
 *                  but inflate the headline count if mixed in (subject,
 *                  message_id, x_mailer, return_path, dmarc/spf/dkim
 *                  results, whois_* fields, file metadata).
 *                  Shown below, collapsible, NOT in headline.
 *
 * Why a frontend mapping (not a backend field): keeping the tier rules
 * frontend-local means this slice ships in one PR without a migration.
 * When the policy stabilises, the same mapping can move to
 * `IocCategorizer` (backend) and the frontend reads it from
 * `meta.iocs_count_actionable`. Documented as R2 in spec.md.
 */

export type IocTier = 'actionable' | 'context';

const ACTIONABLE_TYPES = new Set<string>([
  // Financial
  'iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'bank_account', 'credit_card',
  // Contact channels
  'phone', 'telegram_username', 'discord_username', 'skype_id', 'email',
  // Infrastructure
  'url', 'domain', 'ipv4', 'ipv6',
]);

const CONTEXT_TYPES = new Set<string>([
  // Email headers / authentication
  'subject', 'message_id', 'x_mailer', 'return_path',
  'spf_result', 'dkim_result', 'dmarc_result',
  // Domain provenance
  'whois_email', 'whois_registrar_name', 'registrar',
  // File metadata
  'filename', 'mimetype',
  // Tracking / threat-intel references
  'cve', 'malware_family', 'mitre_attack_id', 'tracking_number',
  // Hashes are contextual unless directly actionable in IR — keep in Context
  'md5', 'sha1', 'sha256',
]);

export function tierForIocType(type: string): IocTier {
  if (ACTIONABLE_TYPES.has(type)) return 'actionable';
  if (CONTEXT_TYPES.has(type)) return 'context';
  // Default: any unknown / new IOC type is treated as actionable so we
  // don't accidentally bury something new under "Context" until the
  // mapping is updated.
  return 'actionable';
}
