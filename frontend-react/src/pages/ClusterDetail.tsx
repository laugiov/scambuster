import { useParams, Link } from 'react-router-dom';
import { useClusterDetail } from '@/hooks/useClusters';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { scamTypeLabels } from '@/lib/scamTypeLabels';
import { iocTypeLabels } from '@/lib/iocTypeLabels';

function formatDate(iso: string | null): string {
  if (!iso) return '--';
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: 'short', day: '2-digit',
  });
}

export function ClusterDetail() {
  const { id } = useParams<{ id: string }>();
  const { data: cluster, isLoading, error, refetch } = useClusterDetail(id ?? '');

  if (isLoading) return <Loading message="Loading cluster..." />;
  if (error || !cluster) return <ErrorMessage message="Cluster not found" onRetry={() => void refetch()} />;

  const stixExportUrl = `/api/v1/clusters/${cluster.cluster_id}/export/stix`;

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <Link to="/clusters" className="text-xs text-accent hover:underline">
            &larr; Back to Clusters
          </Link>
          <h1 className="text-xl font-semibold text-on-surface mt-1">{cluster.name}</h1>
          <div className="flex items-center gap-3 mt-1 text-xs text-on-surface-dim">
            <span className="capitalize">{cluster.sophistication}</span>
            <span>&middot;</span>
            <span>{formatDate(cluster.first_seen)} – {formatDate(cluster.last_seen)}</span>
            <span>&middot;</span>
            <span>v{cluster.algorithm_version}</span>
          </div>
        </div>
        <a
          href={stixExportUrl}
          target="_blank"
          rel="noopener noreferrer"
          className="px-3 py-1.5 rounded-md text-xs font-medium bg-accent text-on-accent hover:bg-accent/90 transition-colors"
        >
          Export STIX
        </a>
      </header>

      <div className="flex items-center gap-2 text-xs">
        <span className="text-on-surface-dim">STIX ID:</span>
        <code className="bg-surface-dim px-2 py-0.5 rounded text-on-surface font-mono text-xs select-all">
          {cluster.stix_id}
        </code>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Anchor IOCs */}
        <div className="rounded-lg border border-border">
          <div className="px-4 py-3 bg-surface-dim border-b border-border">
            <h2 className="text-sm font-medium text-on-surface">
              Anchor IOCs ({cluster.anchor_iocs.length})
            </h2>
          </div>
          <div className="divide-y divide-border">
            {cluster.anchor_iocs.map((ioc) => (
              <div key={ioc.value_norm_hash} className="px-4 py-3 flex items-center justify-between">
                <div>
                  <span className="px-1.5 py-0.5 text-xs rounded bg-accent/10 text-accent mr-2">
                    {iocTypeLabels[ioc.ioc_type] ?? ioc.ioc_type}
                  </span>
                  <span className="text-xs text-on-surface-dim font-mono">
                    {ioc.value_norm_hash.slice(0, 12)}...
                  </span>
                </div>
                <span className="text-xs text-on-surface-dim">
                  {ioc.conv_count} conv.
                </span>
              </div>
            ))}
          </div>
        </div>

        {/* Conversations */}
        <div className="rounded-lg border border-border">
          <div className="px-4 py-3 bg-surface-dim border-b border-border">
            <h2 className="text-sm font-medium text-on-surface">
              Conversations ({cluster.conversations.length})
            </h2>
          </div>
          <div className="divide-y divide-border">
            {cluster.conversations.map((conv) => {
              const scamLabel = scamTypeLabels[conv.scam_type];
              return (
                <Link
                  key={conv.conv_id}
                  to={`/conversations/${conv.conv_id}`}
                  className="px-4 py-3 flex items-center justify-between hover:bg-surface-dim/50 transition-colors block"
                >
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-xs text-on-surface">
                      {conv.conv_id.slice(0, 8)}
                    </span>
                    {scamLabel && (
                      <span
                        className="px-1.5 py-0.5 text-xs rounded"
                        style={{
                          backgroundColor: `${scamLabel.color}20`,
                          color: scamLabel.color,
                        }}
                      >
                        {scamLabel.label}
                      </span>
                    )}
                  </div>
                  <div className="flex items-center gap-3 text-xs text-on-surface-dim">
                    <span className="capitalize">{conv.status}</span>
                    <span>Risk: {conv.score_risk}</span>
                  </div>
                </Link>
              );
            })}
          </div>
        </div>
      </div>
    </div>
  );
}

export default ClusterDetail;
