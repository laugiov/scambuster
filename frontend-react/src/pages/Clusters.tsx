import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useClusters, useClusterStats, type Cluster } from '@/hooks/useClusters';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { DedupHeroCard } from '@/components/clusters/DedupHeroCard';
import { FreshnessDot } from '@/components/clusters/FreshnessDot';
import { bucketRecency, recencyRank, type RecencyBucket } from '@/lib/clusterRecency';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { iocTypeLabel } from '@/lib/iocTypeLabels';
import { iocFamilyBadge, sophisticationBadge } from '@/lib/actorColors';

/** A colour-accented metric tile for the top-of-page stats. */
function MetricTile({ label, value, subtitle, tone, hint }: { label: string; value: React.ReactNode; subtitle?: string; tone: 'accent' | 'info' | 'dim'; hint?: string }) {
  const box = tone === 'accent' ? 'border-l-accent bg-accent/10' : tone === 'info' ? 'border-l-info bg-info/10' : 'border-l-on-surface-dim bg-surface-low';
  const val = tone === 'accent' ? 'text-accent' : tone === 'info' ? 'text-info' : 'text-on-surface';
  return (
    <div className={`rounded-lg border border-border border-l-4 ${box} px-4 py-3`} title={hint}>
      <div className="text-[10px] uppercase tracking-wide text-on-surface-dim">{label}</div>
      <div className={`mt-1 text-3xl font-bold tabular-nums ${val}`}>{value}</div>
      {subtitle && <div className="mt-0.5 text-[11px] text-on-surface-dim">{subtitle}</div>}
    </div>
  );
}

const FINANCIAL_ANCHOR_TYPES = new Set([
  'bank_account',
  'iban',
  'wallet_btc',
  'wallet_eth',
  'wallet_xmr',
  'credit_card',
]);

function formatDate(iso: string | null): string {
  if (!iso) return '--';
  return new Date(iso).toLocaleDateString('en-GB', { year: 'numeric', month: 'short' });
}

function isFinancialAnchor(anchorTypes: string[]): boolean {
  return anchorTypes.some((t) => FINANCIAL_ANCHOR_TYPES.has(t));
}

const SOPHISTICATION_STYLE: Record<string, { label: string; cls: string }> = {
  none: { label: 'None', cls: 'bg-surface-dim text-on-surface-dim' },
  minimal: { label: 'Minimal', cls: 'bg-surface-dim text-on-surface-variant' },
  intermediate: { label: 'Intermediate', cls: 'bg-warning/20 text-warning' },
  advanced: { label: 'Advanced', cls: 'bg-error/20 text-error font-medium' },
};

function compareClusters(a: Cluster & { _bucket: RecencyBucket }, b: Cluster & { _bucket: RecencyBucket }) {
  const r = recencyRank(b._bucket) - recencyRank(a._bucket);
  if (r !== 0) return r;
  return b.conversation_count - a.conversation_count;
}

export function Clusters() {
  const { data: clusters, isLoading, error, refetch } = useClusters();
  const { data: stats } = useClusterStats();

  const sortedClusters = useMemo(() => {
    if (!clusters) return [];
    const now = new Date();
    return clusters
      .map((c) => ({ ...c, _bucket: bucketRecency(c.last_seen, now) }))
      .sort(compareClusters);
  }, [clusters]);

  if (isLoading) return <Loading message="Loading clusters..." />;
  if (error) return <ErrorMessage message="Failed to load clusters" onRetry={() => void refetch()} />;

  const safeStats = stats ?? null;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="flex items-center gap-2.5 text-xl font-semibold text-on-surface">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-accent/15 text-accent">
            <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-5.13a4 4 0 10-4-4 4 4 0 004 4zm6 4a3 3 0 10-3-3M8 12a3 3 0 10-3-3" />
            </svg>
          </span>
          Threat Actor Clusters
        </h1>
        <p className="ml-11 mt-1 text-xs text-on-surface-dim">
          Conversations grouped by shared financial IOCs (IBAN, crypto wallets, phone numbers)
        </p>
      </header>

      {safeStats && <DedupHeroCard stats={safeStats} />}

      {safeStats && (
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3" data-testid="secondary-metrics">
          <MetricTile label="Active Clusters" value={safeStats.total_clusters} tone="accent" hint="Groups of conversations linked by shared financial IOCs." />
          <MetricTile label="Clustered Conversations" value={safeStats.clustered_conversations} tone="info" hint="Conversations that belong to at least one cluster." />
          <MetricTile label="Unclustered" value={safeStats.singleton_conversations} subtitle="No shared financial IOCs" tone="dim" hint="Conversations with no shared financial IOCs — each generates its own threat actor in the TAXII feed." />
        </div>
      )}

      {sortedClusters.length === 0 ? (
        <div className="text-center py-12 text-on-surface-dim">
          No clusters yet. Run the backfill command or wait for new emails with shared IOCs.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="min-w-full text-sm">
            <thead className="border-b-2 border-accent/30 bg-surface-dim text-left text-[11px] uppercase tracking-wide text-on-surface-dim">
              <tr>
                <th className="px-4 py-3 font-medium w-8" aria-label="Freshness" />
                <th className="px-4 py-3 font-medium">Cluster</th>
                <th className="px-4 py-3 font-medium">Scam Types</th>
                <th className="px-4 py-3 font-medium text-right">Conversations</th>
                <th className="px-4 py-3 font-medium">Sophistication</th>
                <th className="px-4 py-3 font-medium">Anchor IOCs</th>
                <th className="px-4 py-3 font-medium">Period</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {sortedClusters.map((c) => {
                const isFinancial = isFinancialAnchor(c.anchor_ioc_types);
                const multiType = c.primary_scam_types.length > 1;

                return (
                  <tr key={c.cluster_id} className="hover:bg-surface-dim/50 transition-colors">
                    <td className="px-4 py-3 align-middle">
                      <FreshnessDot bucket={c._bucket} />
                    </td>
                    <td className="px-4 py-3">
                      <Link
                        to={`/clusters/${c.cluster_id}`}
                        className="text-accent hover:underline font-medium"
                      >
                        {c.name}
                      </Link>
                      {c.status === 'suspect' && (
                        <span
                          className="ml-2 px-1.5 py-0.5 text-xs rounded bg-warning/20 text-warning cursor-help"
                          title={`Cluster exceeds 50 conversations (${c.conversation_count}). Possible over-agglomeration via transitive IOC links. Review anchor IOCs for false connections.`}
                        >
                          SUSPECT
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {multiType ? (
                        <div className="flex items-center gap-2 min-w-0">
                          <span className="whitespace-nowrap rounded border border-accent/30 bg-accent/5 px-1.5 py-0.5 text-xs font-medium text-accent">
                            Multi-type · {c.primary_scam_types.length}
                          </span>
                          <span
                            className="text-xs text-on-surface-dim truncate"
                            title={c.primary_scam_types.map(scamTypeLabel).join(', ')}
                          >
                            {c.primary_scam_types.map(scamTypeLabel).join(', ')}
                          </span>
                        </div>
                      ) : (
                        <span
                          className={`px-1.5 py-0.5 text-xs rounded font-medium ${scamTypeColor(c.primary_scam_types[0] ?? '')}`}
                        >
                          {scamTypeLabel(c.primary_scam_types[0] ?? 'Unknown')}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right font-mono font-semibold text-on-surface">{c.conversation_count}</td>
                    <td className="px-4 py-3">
                      {(() => {
                        const s = SOPHISTICATION_STYLE[c.sophistication] ?? SOPHISTICATION_STYLE.minimal;
                        return (
                          <span
                            className={`rounded-full border px-2 py-0.5 text-xs font-medium ${sophisticationBadge(c.sophistication)}`}
                            data-sophistication={c.sophistication}
                          >
                            {s.label}
                          </span>
                        );
                      })()}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1" data-anchor-kind={isFinancial ? 'financial' : 'phone'}>
                        {c.anchor_ioc_types.map((t) => (
                          <span key={t} className={`rounded border px-1.5 py-0.5 text-xs font-medium ${iocFamilyBadge(t)}`}>
                            {iocTypeLabel(t)}
                          </span>
                        ))}
                      </div>
                    </td>
                    <td className="px-4 py-3 text-on-surface-dim text-xs whitespace-nowrap">
                      {formatDate(c.first_seen)} – {formatDate(c.last_seen)}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default Clusters;
