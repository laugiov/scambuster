import type { BadgeVariant } from './badgeUtils';

const VARIANT_STYLES: Record<BadgeVariant, string> = {
  engaging: 'bg-status-engaging/20 text-status-engaging',
  waiting: 'bg-status-waiting/20 text-status-waiting',
  done: 'bg-surface-highest text-on-surface-variant',
  closed: 'bg-surface-highest text-on-surface-variant',
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
