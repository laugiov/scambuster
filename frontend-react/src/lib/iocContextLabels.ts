/**
 * Shared color palettes and helpers for IOC context badges
 * (semantic role + stimulus type). Used by IocDetail and ClusterDetail.
 */

export const ROLE_COLORS: Record<string, string> = {
  PAYMENT_DESTINATION: 'bg-error/20 text-error',
  PAYMENT_REDIRECT_URL: 'bg-error/20 text-error',
  MONEY_MULE_ACCOUNT: 'bg-error/20 text-error',
  PHISHING_CREDENTIAL_URL: 'bg-warning/20 text-warning',
  MALWARE_DOWNLOAD_URL: 'bg-warning/20 text-warning',
  PHISHING_URL: 'bg-warning/20 text-warning',
  CONTACT_CHANNEL: 'bg-blue-500/20 text-blue-400',
  INFRASTRUCTURE_DOMAIN: 'bg-purple-500/20 text-purple-400',
  VERIFICATION_CODE_URL: 'bg-yellow-500/20 text-yellow-400',
  IDENTITY_DOCUMENT: 'bg-yellow-500/20 text-yellow-400',
  UNKNOWN: 'bg-on-surface-dim/20 text-on-surface-dim',
};

/**
 * Normalize role/stimulus to upper-snake-case for color lookup.
 */
export function normalizeContextKey(value: string | null | undefined): string {
  if (!value) return 'UNKNOWN';
  return value.replace(/[-\s]/g, '_').toUpperCase();
}

/**
 * Humanize a snake_case or kebab-case label to "Title Case".
 * e.g. "urgency-pressure" → "Urgency Pressure"
 */
export function humanizeContext(value: string | null | undefined): string {
  if (!value) return 'Unknown';
  return value
    .replace(/[-_]/g, ' ')
    .split(' ')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ');
}

/**
 * Color helper for an urgency score (0-1).
 */
export function urgencyColorClass(score: number): string {
  if (score >= 0.75) return 'bg-error';
  if (score >= 0.50) return 'bg-warning';
  return 'bg-success';
}

export function urgencyTextClass(score: number): string {
  if (score >= 0.75) return 'text-error';
  if (score >= 0.50) return 'text-warning';
  return 'text-success';
}
