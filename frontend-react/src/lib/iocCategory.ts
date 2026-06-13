/**
 * Spec 097 — Frontend mirror of App\Domain\Communication\IocCategory.
 *
 * Maps an IOC type to its visual category bucket. EXPLICIT default
 * bucket `other` so future IOC types render with a sensible style
 * without code changes here.
 */

export type IocCategoryName = 'financial' | 'contact' | 'infrastructure' | 'other';

const FINANCIAL = new Set([
  'iban', 'bic', 'swift', 'bank_account', 'routing_number',
  'wallet_btc', 'wallet_eth', 'wallet_xmr', 'wallet', 'credit_card',
]);

const CONTACT = new Set([
  'phone', 'email', 'whatsapp', 'telegram', 'telegram_username',
  'skype', 'skype_id', 'signal',
]);

const INFRASTRUCTURE = new Set([
  'url', 'domain', 'ipv4', 'ipv6', 'sha256', 'sha1', 'md5',
  'tracking_number',
]);

export function classifyIoc(type: string): IocCategoryName {
  const normalized = (type ?? '').toLowerCase().trim();
  if (FINANCIAL.has(normalized)) return 'financial';
  if (CONTACT.has(normalized)) return 'contact';
  if (INFRASTRUCTURE.has(normalized)) return 'infrastructure';
  return 'other';
}

export function categoryLabel(category: IocCategoryName): string {
  switch (category) {
    case 'financial':
      return 'Financial';
    case 'contact':
      return 'Contact';
    case 'infrastructure':
      return 'Infrastructure';
    default:
      return 'Other';
  }
}

export function categoryColorClass(category: IocCategoryName): string {
  switch (category) {
    case 'financial':
      return 'bg-amber-500/15 text-amber-300 border-amber-500/30';
    case 'contact':
      return 'bg-blue-500/15 text-blue-300 border-blue-500/30';
    case 'infrastructure':
      return 'bg-purple-500/15 text-purple-300 border-purple-500/30';
    default:
      return 'bg-surface-high text-on-surface-variant border-outline-variant';
  }
}
