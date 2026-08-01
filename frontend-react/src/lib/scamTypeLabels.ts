interface ScamTypeConfig {
  label: string;
  color: string; // Tailwind classes
}

const SCAM_TYPE_MAP: Record<string, ScamTypeConfig> = {
  PHISHING: { label: 'Phishing', color: 'bg-amber-500/20 text-amber-400' },
  PHISH_MALWARE: { label: 'Phish / Malware', color: 'bg-amber-500/20 text-amber-400' },
  PHISH_CREDENTIALS: { label: 'Credential Phish', color: 'bg-amber-500/20 text-amber-400' },
  INVOICE_FRAUD: { label: 'Invoice Fraud', color: 'bg-red-500/20 text-red-400' },
  INVESTMENT: { label: 'Investment Scam', color: 'bg-red-500/20 text-red-400' },
  ADVANCE_FEE: { label: 'Advance Fee', color: 'bg-red-500/20 text-red-400' },
  ADVANCE_FEE_419: { label: 'Advance Fee (419)', color: 'bg-red-500/20 text-red-400' },
  EXTORTION: { label: 'Extortion', color: 'bg-red-500/20 text-red-400' },
  ROMANCE: { label: 'Romance Scam', color: 'bg-purple-500/20 text-purple-400' },
  JOB_OFFER: { label: 'Job Offer', color: 'bg-blue-500/20 text-blue-400' },
  TECH_SUPPORT: { label: 'Tech Support', color: 'bg-blue-500/20 text-blue-400' },
  LOTTERY: { label: 'Lottery', color: 'bg-orange-500/20 text-orange-400' },
  CRYPTO: { label: 'Crypto Scam', color: 'bg-yellow-500/20 text-yellow-400' },
  OTHER: { label: 'Other', color: 'bg-surface-highest text-on-surface-variant' },
};

export function humanize(code: string): string {
  return code
    .split('_')
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ');
}

export function scamTypeLabel(code: string): string {
  return SCAM_TYPE_MAP[code]?.label ?? humanize(code);
}

export function scamTypeColor(code: string): string {
  return SCAM_TYPE_MAP[code]?.color ?? 'bg-surface-highest text-on-surface-variant';
}
