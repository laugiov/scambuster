const HIGH_VALUE_TYPES = new Set([
  'iban', 'bic', 'bank_account', 'credit_card',
  'wallet_btc', 'wallet_eth', 'wallet_xmr',
  'phone',
]);

const MEDIUM_VALUE_TYPES = new Set([
  'url', 'domain', 'email', 'whois_email',
  'ipv4', 'ipv6',
  'sha256', 'sha1', 'md5',
  'filename', 'registrar',
]);

export interface SeverityInfo {
  label: string;
  color: string;
  border: string;
}

const SEVERITY_STYLES: Record<string, SeverityInfo> = {
  HIGH: { label: 'HIGH', color: 'bg-error/20 text-error', border: 'border-error' },
  MEDIUM: { label: 'MEDIUM', color: 'bg-warning/20 text-warning', border: 'border-warning' },
  LOW: { label: 'LOW', color: 'bg-status-waiting/20 text-status-waiting', border: 'border-status-waiting' },
};

/**
 * Compute IOC severity from type and enrichment scores.
 * Mirrors backend IocConfidenceCalculator::computeSeverity.
 *
 * If the API already provides a severity field, use it directly.
 * Otherwise, compute from IOC type + VT/URLscan scores.
 */
export function iocSeverity(
  iocType: string,
  vtScore = 0,
  urlscanScore = 0,
  apiSeverity?: string,
): SeverityInfo {
  const level = apiSeverity ?? computeSeverity(iocType, vtScore, urlscanScore);
  return SEVERITY_STYLES[level] ?? SEVERITY_STYLES.LOW;
}

function computeSeverity(iocType: string, vtScore: number, urlscanScore: number): string {
  const type = iocType.toLowerCase();
  const enrichment = Math.max(vtScore, urlscanScore);

  if (HIGH_VALUE_TYPES.has(type)) return 'HIGH';
  if (MEDIUM_VALUE_TYPES.has(type)) return enrichment > 0 ? 'HIGH' : 'MEDIUM';
  return 'LOW';
}
