/**
 * Returns a human-readable relative time string.
 * Handles NaN dates and future dates gracefully.
 */
export function timeSince(iso: string): string {
  const ms = Date.now() - new Date(iso).getTime();
  if (isNaN(ms)) return '--';
  if (ms < 0) return 'just now';
  const seconds = Math.floor(ms / 1000);
  if (seconds < 60) return `${seconds}s ago`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

/**
 * Format an ISO date to a short abbreviated timestamp.
 * Recent (< 7 days): "Apr 7, 14:23"
 * Older: "Mar 28"
 * Invalid: "--"
 */
export function formatShortTimestamp(iso: string): string {
  const date = new Date(iso);
  if (isNaN(date.getTime())) return '--';
  const now = new Date();
  const diffDays = Math.floor((now.getTime() - date.getTime()) / 86400000);
  const month = date.toLocaleString('en-US', { month: 'short' });
  const day = date.getDate();
  if (diffDays < 7) {
    const time = date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
    return `${month} ${day}, ${time}`;
  }
  return `${month} ${day}`;
}

/**
 * Format an ISO date to a localized short datetime.
 */
export function formatDate(iso: string): string {
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  });
}

/**
 * Format an ISO date to HH:MM time.
 */
export function formatTime(iso: string): string {
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}
