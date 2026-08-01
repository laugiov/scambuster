/**
 * Semantic colour classes for the threat-actor (cluster) screen.
 *
 * Single source of truth so every panel speaks the same colour language — and the
 * rule stays honest: colour ENCODES meaning (severity, IOC family, risk), it never
 * just decorates. accent = activity · amber = attention/financial · blue = contact ·
 * violet = infrastructure · red = danger.
 */

// Solid, saturated fills — colour should punch, not whisper. `border-transparent`
// hides the callers' 1px border so the chip reads as a solid block of colour.

/** Actor sophistication → badge classes: neutral → info → danger as it climbs. */
export function sophisticationBadge(soph: string | null | undefined): string {
  const s = (soph ?? '').toLowerCase();
  if (s === 'advanced' || s === 'critical') return 'border-transparent bg-error text-white';
  if (s === 'intermediate') return 'border-transparent bg-info text-white';
  return 'border-transparent bg-surface-high text-on-surface-variant';
}

/** IOC type → family badge classes: financial (amber) · contact (blue) · infra (violet). */
export function iocFamilyBadge(type: string): string {
  const t = type.toLowerCase();
  if (t.startsWith('wallet') || t === 'iban' || t === 'bank_account' || t === 'credit_card' || t === 'bic') {
    return 'border-transparent bg-warning text-surface-base';
  }
  if (
    t === 'phone' || t === 'email' || t === 'whois_email' ||
    t === 'telegram_username' || t === 'skype_id' || t === 'discord_username'
  ) {
    return 'border-transparent bg-info text-white';
  }
  return 'border-transparent bg-violet-500 text-white';
}

/** Risk score (0-100) → text colour: high = danger, mid = attention, low = muted. */
export function riskText(score: number): string {
  if (score >= 80) return 'text-error';
  if (score >= 50) return 'text-warning';
  return 'text-on-surface-variant';
}
