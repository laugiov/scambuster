/**
 * Format a token count for display: 0, 999, 1.5K, 2.5M
 */
export function formatTokenCount(n: number): string {
  if (n < 1000) return String(n);
  if (n < 1_000_000) return `${(n / 1000).toFixed(1).replace(/\.0$/, '')}K`;
  return `${(n / 1_000_000).toFixed(1).replace(/\.0$/, '')}M`;
}

/**
 * Format a purpose key for display: "ioc_extraction" -> "Ioc Extraction"
 */
export function formatPurposeName(purpose: string): string {
  return purpose
    .split('_')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

/**
 * Format a short month-day date: "2026-03-22" -> "Mar 22"
 */
export function formatShortDate(dateStr: string): string {
  const d = new Date(dateStr + 'T00:00:00');
  if (isNaN(d.getTime())) return dateStr;
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}
