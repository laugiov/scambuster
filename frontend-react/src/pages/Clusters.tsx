import { Link } from 'react-router-dom';
import { useClusters, useClusterStats } from '@/hooks/useClusters';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { iocTypeLabel } from '@/lib/iocTypeLabels';

function formatDate(iso: string | null): string {
  if (!iso) return '--';
  return new Date(iso).toLocaleDateString('en-GB', {
    year: 'numeric', month: 'short',
  });
}

export function Clusters() {
  const { data: clusters, isLoading, error, refetch } = useClusters();
  const { data: stats } = useClusterStats();

  if (isLoading) return <Loading message="Loading clusters..." />;
  if (error) return <ErrorMessage message="Failed to load clusters" onRetry={() => void refetch()} />;

  const safeClusters = clusters ?? [];
  const safeStats = stats ?? null;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">Threat Actor Clusters</h1>
        <p className="text-xs text-on-surface-dim mt-1">
          Conversations grouped by shared financial IOCs (IBAN, crypto wallets, phone numbers)
        </p>
      </header>

      {safeStats && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <div title="Groups of conversations linked by shared financial IOCs (IBAN, crypto wallets, phone numbers)">
            <StatCard label="Active Clusters" value={safeStats.total_clusters} />
          </div>
          <div title="Conversations that belong to at least one cluster (share a financial IOC with another conversation)">
            <StatCard label="Clustered Conversations" value={safeStats.clustered_conversations} />
          </div>
          <div title="Conversations with no shared financial IOCs — each generates its own threat actor in the TAXII feed">
            <StatCard
              label="Unclustered"
              value={safeStats.singleton_conversations}
              subtitle="No shared financial IOCs"
            />
          </div>
          <div title={`Before clustering: ${safeStats.total_conversations} threat actors (1 per conversation). After: ${safeStats.total_clusters + safeStats.singleton_conversations} actors (${safeStats.total_clusters} clusters + ${safeStats.singleton_conversations} singletons). Fewer actors = less noise for TAXII consumers (OpenCTI, MISP).`}>
            <StatCard
              label="Actor Deduplication"
              value={`${safeStats.total_conversations} → ${safeStats.total_clusters + safeStats.singleton_conversations}`}
              subtitle={safeStats.suspect_clusters > 0
                ? `−${safeStats.taxii_noise_reduction_pct}% · ${safeStats.suspect_clusters} suspect — provisional`
                : `−${safeStats.taxii_noise_reduction_pct}% threat actors in TAXII feed`
              }
            />
          </div>
        </div>
      )}

      {safeClusters.length === 0 ? (
        <div className="text-center py-12 text-on-surface-dim">
          No clusters yet. Run the backfill command or wait for new emails with shared IOCs.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-lg border border-border">
          <table className="min-w-full text-sm">
            <thead className="bg-surface-dim text-on-surface-dim text-left">
              <tr>
                <th className="px-4 py-3 font-medium">Cluster</th>
                <th className="px-4 py-3 font-medium">Scam Types</th>
                <th className="px-4 py-3 font-medium text-right">Conversations</th>
                <th className="px-4 py-3 font-medium">Sophistication</th>
                <th className="px-4 py-3 font-medium">Anchor IOCs</th>
                <th className="px-4 py-3 font-medium">Period</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-border">
              {safeClusters.map((c) => (
                <tr key={c.cluster_id} className="hover:bg-surface-dim/50 transition-colors">
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
                    <div className="flex flex-wrap gap-1">
                      {c.primary_scam_types.map((st) => (
                        <span
                          key={st}
                          className={`px-1.5 py-0.5 text-xs rounded font-medium ${scamTypeColor(st)}`}
                        >
                          {scamTypeLabel(st)}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-right font-mono">{c.conversation_count}</td>
                  <td className="px-4 py-3 capitalize">{c.sophistication}</td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {c.anchor_ioc_types.map((t) => (
                        <span key={t} className="px-1.5 py-0.5 text-xs rounded bg-accent/10 text-accent">
                          {iocTypeLabel(t)}
                        </span>
                      ))}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-on-surface-dim text-xs">
                    {formatDate(c.first_seen)} – {formatDate(c.last_seen)}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default Clusters;
