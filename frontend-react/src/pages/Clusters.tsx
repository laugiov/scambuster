import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import { useClusters, useClusterStats, type Cluster } from '@/hooks/useClusters';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { DedupHeroCard } from '@/components/clusters/DedupHeroCard';
import { FreshnessDot } from '@/components/clusters/FreshnessDot';
import { bucketRecency, recencyRank, type RecencyBucket } from '@/lib/clusterRecency';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { iocTypeLabel } from '@/lib/iocTypeLabels';

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
        <h1 className="text-xl font-semibold text-on-surface">Threat Actor Clusters</h1>
        <p className="text-xs text-on-surface-dim mt-1">
          Conversations grouped by shared financial IOCs (IBAN, crypto wallets, phone numbers)
        </p>
      </header>

      {safeStats && <DedupHeroCard stats={safeStats} />}

      {safeStats && (
        <div className="grid grid-cols-2 sm:grid-cols-3 gap-3" data-testid="secondary-metrics">
          <div title="Groups of conversations linked by shared financial IOCs">
            <StatCard label="Active Clusters" value={safeStats.total_clusters} />
          </div>
          <div title="Conversations that belong to at least one cluster">
            <StatCard label="Clustered Conversations" value={safeStats.clustered_conversations} />
          </div>
          <div title="Conversations with no shared financial IOCs — each generates its own threat actor in the TAXII feed">
            <StatCard
              label="Unclustered"
              value={safeStats.singleton_conversations}
              subtitle="No shared financial IOCs"
            />
          </div>
        </div>
      )}

      {sortedClusters.length === 0 ? (
        <div className="text-center py-12 text-on-surface-dim">
          No clusters yet. Run the backfill command or wait for new emails with shared IOCs.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="min-w-full text-sm">
            <thead className="bg-surface-dim text-on-surface-dim text-left">
              <tr>
                <th className="px-4 py-3 font-medium w-8" aria-label="Freshness" />
                <th className="px-4 py-3 font-medium">Cluster</th>
                <th className="px-4 py-3 font-medium">Scam Types</th>
                <th className="px-4 py-3 font-medium text-right">Conversations</th>
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
                          <span className="px-1.5 py-0.5 text-xs rounded bg-surface-dim text-on-surface-variant whitespace-nowrap">
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
                    <td className="px-4 py-3 text-right font-mono">{c.conversation_count}</td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1" data-anchor-kind={isFinancial ? 'financial' : 'phone'}>
                        {c.anchor_ioc_types.map((t) => {
                          const isFin = FINANCIAL_ANCHOR_TYPES.has(t);
                          return (
                            <span
                              key={t}
                              className={
                                isFin
                                  ? 'px-1.5 py-0.5 text-xs rounded bg-accent/15 text-accent font-medium'
                                  : 'px-1.5 py-0.5 text-xs rounded bg-surface-dim text-on-surface-variant'
                              }
                            >
                              {iocTypeLabel(t)}
                            </span>
                          );
                        })}
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
