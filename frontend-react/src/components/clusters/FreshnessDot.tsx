import type { RecencyBucket } from '@/lib/clusterRecency';
import { recencyTooltip } from '@/lib/clusterRecency';

interface FreshnessDotProps {
  bucket: RecencyBucket;
  className?: string;
}

const COLOR_CLASS: Record<RecencyBucket, string> = {
  now: 'bg-success',
  recent: 'bg-success',
  stale: 'bg-warning',
};

/**
 * 8px circular dot indicating cluster freshness. Used as the first column on
 * the Clusters list page so triage-by-eye is one glance: green = act, amber
 * = lower priority. The semantic colour comes from the existing dark-theme
 * tokens (`success` and `warning`) so no new design tokens are introduced.
 */
export function FreshnessDot({ bucket, className = '' }: FreshnessDotProps) {
  return (
    <span
      aria-label={recencyTooltip(bucket)}
      title={recencyTooltip(bucket)}
      data-recency={bucket}
      className={`inline-block w-2 h-2 rounded-full shrink-0 ${COLOR_CLASS[bucket]} ${className}`.trim()}
    />
  );
}
