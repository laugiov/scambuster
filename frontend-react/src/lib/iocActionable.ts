/**
 * Non-actionable IOC types.
 *
 * **MIRRORS** the PHP source of truth at
 * `backend-symfony/src/Domain/Communication/Policy/IocActionablePolicy.php`.
 * Keep both lists in sync — `iocActionable.test.ts` pins this set so a
 * change here that drifts from the PHP test would be caught by the
 * frontend test suite.
 *
 * The "non-actionable" set is biased toward our threat model:
 *  - Header metadata (subject, message_id, x_mailer, return_path)
 *    identifies the email envelope, not the scammer's operational
 *    infrastructure.
 *  - Auth results (SPF/DKIM/DMARC) are mail-transport checks; the
 *    scammer doesn't control them.
 *  - WHOIS metadata identifies the domain operator, useful for
 *    enrichment but not a pivot on the scammer.
 *  - File metadata (filename, mimetype) describes attachment shape,
 *    not content.
 *  - Reference identifiers (cve, malware_family, mitre_attack_id,
 *    tracking_number) are categorical labels, not artefacts.
 *  - File hashes (md5/sha1/sha256) ARE actionable for malware-delivery
 *    IOCs, but the scam-baiting platform does not currently engage in
 *    payload analysis; counting them inflates the figure with hashes
 *    of legitimate attachments (PDFs, images) the scammer copy-pasted
 *    from real corporate emails.
 */
export const NON_ACTIONABLE_IOC_TYPES: ReadonlySet<string> = new Set([
  // Header metadata
  'subject',
  'message_id',
  'x_mailer',
  'return_path',

  // Auth results
  'spf_result',
  'dkim_result',
  'dmarc_result',

  // WHOIS metadata
  'whois_email',
  'whois_registrar_name',
  'registrar',

  // File metadata
  'filename',
  'mimetype',

  // Reference identifiers (not artefacts on their own)
  'cve',
  'malware_family',
  'mitre_attack_id',
  'tracking_number',

  // File hashes (irrelevant to scam-baiting threat model)
  'md5',
  'sha1',
  'sha256',
]);

export function isActionableIocType(type: string): boolean {
  return !NON_ACTIONABLE_IOC_TYPES.has(type.toLowerCase());
}
