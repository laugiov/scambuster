/**
 * IOC value masking for the Theater (and any future feature
 * that needs to show sensitive IOC values without leaking them on
 * screen-share / talks / recordings).
 *
 * Mask rules (deterministic):
 * - phone (any length):       first 3 + "*****" + last 3 (e.g. "+91*****123")
 * - length < 6:               "***"
 * - 6 ≤ length < 12:          first 3 + "***"
 * - length ≥ 12:              first 6 + "***" + last 3
 *
 * Sensitive types (always masked by default):
 *   phone, iban, bic, bank_account, routing_number, wallet_btc, wallet_eth,
 *   wallet_xmr, wallet, credit_card
 */

const SENSITIVE_TYPES = new Set([
  'phone', 'iban', 'bic', 'bank_account', 'routing_number',
  'wallet_btc', 'wallet_eth', 'wallet_xmr', 'wallet', 'credit_card',
]);

export function isSensitiveType(type: string): boolean {
  return SENSITIVE_TYPES.has((type ?? '').toLowerCase().trim());
}

export function maskValue(value: string, type: string): string {
  if (!value) return value;
  const normalizedType = (type ?? '').toLowerCase().trim();

  if (normalizedType === 'phone') {
    if (value.length <= 6) return '***';
    return `${value.slice(0, 3)}*****${value.slice(-3)}`;
  }

  if (value.length < 6) return '***';
  if (value.length < 12) return `${value.slice(0, 3)}***`;
  return `${value.slice(0, 6)}***${value.slice(-3)}`;
}

export function displayValue(value: string, type: string, masked: boolean): string {
  if (!masked || !isSensitiveType(type)) return value;
  return maskValue(value, type);
}
