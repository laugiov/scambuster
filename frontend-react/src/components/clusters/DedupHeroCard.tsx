import type { ClusterStats } from '@/hooks/useClusters';

interface DedupHeroCardProps {
  stats: ClusterStats;
}

/**
 * Hero card on /clusters that surfaces the core value proposition for TAXII
 * feed consumers: actor deduplication. Replaces the de-emphasised
 * "Actor Deduplication" StatCard in the previous design.
 *
 * Numbers shown:
 *   before = total_conversations (one threat-actor per conv pre-clustering)
 *   after  = total_clusters + singleton_conversations
 *   pct    = stats.taxii_noise_reduction_pct
 *
 * Renders nothing if `before <= 0` (defensive — should never happen on a
 * non-empty deployment but avoids divide-by-zero visual artifacts).
 */
export function DedupHeroCard({ stats }: DedupHeroCardProps) {
  const before = stats.total_conversations;
  const after = stats.total_clusters + stats.singleton_conversations;

  if (before <= 0) {
    return null;
  }

  const pct = stats.taxii_noise_reduction_pct;
  const afterPctWidth = Math.max(0, Math.min(100, (after / before) * 100));

  return (
    <section
      data-testid="dedup-hero"
      className="rounded-lg border border-border bg-surface-low px-6 py-5 flex flex-col md:flex-row md:items-center gap-6"
    >
      <div className="md:min-w-[140px]">
        <div
          className="text-5xl font-light text-on-surface leading-none tabular-nums"
          aria-label={`${pct} percent fewer threat actors`}
        >
          −{pct}%
        </div>
        <div className="text-xs text-on-surface-dim mt-2 max-w-[180px] leading-snug">
          fewer threat actors in your TAXII feed
        </div>
      </div>

      <div className="flex-1 min-w-0 space-y-3">
        <DedupRow label={`${before.toLocaleString()} before`} pct={100} muted />
        <DedupRow label={`${after.toLocaleString()} after`} pct={afterPctWidth} />
        <p className="text-xs text-on-surface-dim leading-snug">
          {stats.total_clusters} cluster{stats.total_clusters === 1 ? '' : 's'} +{' '}
          {stats.singleton_conversations.toLocaleString()} singleton
          {stats.singleton_conversations === 1 ? '' : 's'}. Fewer actors, less noise for
          OpenCTI and MISP.
        </p>
      </div>
    </section>
  );
}

function DedupRow({ label, pct, muted = false }: { label: string; pct: number; muted?: boolean }) {
  return (
    <div className="flex items-center gap-3">
      <span className="text-xs text-on-surface-variant w-28 shrink-0 tabular-nums">{label}</span>
      <span className="flex-1 h-2 bg-surface-dim rounded overflow-hidden">
        <span
          className={`block h-full rounded ${muted ? 'bg-on-surface-dim/40' : 'bg-accent'}`}
          style={{ width: `${pct}%` }}
        />
      </span>
    </div>
  );
}
