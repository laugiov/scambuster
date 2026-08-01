export type BadgeVariant = 'engaging' | 'waiting' | 'done' | 'closed' | 'default';

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
