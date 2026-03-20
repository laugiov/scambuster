type BadgeVariant = 'engaging' | 'waiting' | 'done' | 'closed' | 'default';

const VARIANT_STYLES: Record<BadgeVariant, string> = {
  engaging: 'bg-status-engaging/20 text-status-engaging',
  waiting: 'bg-status-waiting/20 text-status-waiting',
  done: 'bg-surface-highest text-on-surface-variant',
  closed: 'bg-status-closed/20 text-status-closed',
  default: 'bg-surface-highest text-on-surface-variant',
};

interface BadgeProps {
  label: string;
  variant?: BadgeVariant;
}

export function Badge({ label, variant = 'default' }: BadgeProps) {
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded text-xs font-medium uppercase tracking-wider ${VARIANT_STYLES[variant]}`}>
      {label}
    </span>
  );
}

export function statusToBadgeVariant(status: string): BadgeVariant {
  switch (status.toLowerCase()) {
    case 'open':
    case 'engaging':
      return 'engaging';
    case 'waiting':
      return 'waiting';
    case 'closed':
      return 'closed';
    case 'done':
    case 'abandoned':
      return 'done';
    default:
      return 'default';
  }
}
