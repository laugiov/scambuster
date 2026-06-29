import { useState, useMemo } from 'react';
import { useParams, Link, useNavigate } from 'react-router-dom';
import { useClusterDetail } from '@/hooks/useClusters';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { iocTypeLabel } from '@/lib/iocTypeLabels';
import { VerdictBanner } from '@/components/clusters/VerdictBanner';
import { SignalTiles } from '@/components/clusters/SignalTiles';
import { AnchorReachRow } from '@/components/clusters/AnchorReachRow';
import { CampaignExcerptsPanel } from '@/components/clusters/CampaignExcerptsPanel';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';

function formatDate(iso: string | null): string {
  if (!iso) return '--';
  return new Date(iso).toLocaleString('en-GB', {
    year: 'numeric', month: 'short', day: '2-digit',
  });
}


type SortField = 'risk' | 'scam_type' | 'status';

export function ClusterDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { data: cluster, isLoading, error, refetch } = useClusterDetail(id ?? '');

  const [selectedIndicatorId, setSelectedIndicatorId] = useState<string | null>(null);
  const [sortBy, setSortBy] = useState<SortField>('risk');
  const [scamTypeFilter, setScamTypeFilter] = useState<string>('');
  const [showAllConversations, setShowAllConversations] = useState(false);
  const CONV_PREVIEW_LIMIT = 6;

  const filteredConversations = useMemo(() => {
    if (!cluster) return [];

    let convs = [...cluster.conversations];

    // Filter by selected anchor IOC
    if (selectedIndicatorId) {
      const anchor = cluster.anchor_iocs.find((a) => a.indicator_id === selectedIndicatorId);
      if (anchor) {
        const allowedIds = new Set(anchor.conv_ids);
        convs = convs.filter((c) => allowedIds.has(c.conv_id));
      }
    }

    // Filter by scam type
    if (scamTypeFilter) {
      convs = convs.filter((c) => c.scam_type === scamTypeFilter);
    }

    // Sort
    convs.sort((a, b) => {
      if (sortBy === 'risk') return b.score_risk - a.score_risk;
      if (sortBy === 'scam_type') return a.scam_type.localeCompare(b.scam_type);
      return a.status.localeCompare(b.status);
    });

    return convs;
  }, [cluster, selectedIndicatorId, scamTypeFilter, sortBy]);

  const scamTypes = useMemo(() => {
    if (!cluster) return [];
    return [...new Set(cluster.conversations.map((c) => c.scam_type))].sort();
  }, [cluster]);

  if (isLoading) return <Loading message="Loading cluster..." />;
  if (error || !cluster) return <ErrorMessage message="Cluster not found" onRetry={() => void refetch()} />;

  async function handleExportStix() {
    if (!cluster) return;
    try {
      const cid = cluster.cluster_id;
      const { data } = await client.get(ENDPOINTS.clusters.exportStix(cid));
      const json = JSON.stringify(data, null, 2);
      const blob = new Blob([json], { type: 'application/stix+json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `cluster-${cid.slice(0, 8)}.stix.json`;
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      // silently fail
    }
  }

  function handleAnchorClick(indicatorId: string) {
    setSelectedIndicatorId((prev) => (prev === indicatorId ? null : indicatorId));
  }

  const selectedAnchor = cluster.anchor_iocs.find((a) => a.indicator_id === selectedIndicatorId);
  const hasActiveFilter = selectedIndicatorId || scamTypeFilter;

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <Link to="/clusters" className="text-xs text-accent hover:underline">
            &larr; Back to Clusters
          </Link>
          <h1 className="text-xl font-semibold text-on-surface mt-1">{cluster.name}</h1>
          <div className="flex items-center gap-3 mt-1 text-xs text-on-surface-dim">
            {cluster.sophistication && cluster.sophistication !== 'minimal' && (
              <>
                <span className="capitalize">{cluster.sophistication}</span>
                <span>&middot;</span>
              </>
            )}
            <span>{formatDate(cluster.first_seen)} – {formatDate(cluster.last_seen)}</span>
            <span>&middot;</span>
            <span>v{cluster.algorithm_version}</span>
          </div>
        </div>
        <button
          onClick={() => void handleExportStix()}
          className="flex items-center gap-1.5 px-3 py-2 text-xs font-medium text-on-surface-variant bg-surface-low hover:bg-surface-high rounded-lg transition-colors cursor-pointer"
        >
          <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
          Export STIX
        </button>
      </header>

      <VerdictBanner cluster={cluster} />

      <div className="flex items-center gap-2 text-xs">
        <span className="text-on-surface-dim">STIX ID:</span>
        <code className="bg-surface-dim px-2 py-0.5 rounded text-on-surface font-mono text-xs select-all">
          {cluster.stix_id}
        </code>
      </div>

      {cluster.behavioral_profile && cluster.behavioral_profile.total_enriched_iocs > 0 && (
        <SignalTiles
          profile={cluster.behavioral_profile}
          anchors={cluster.anchor_iocs}
          conversationCount={cluster.conversation_count}
        />
      )}

      {cluster.behavioral_profile && cluster.behavioral_profile.total_enriched_iocs > 0 &&
       (cluster.behavioral_profile.hesitation_count > 0 || cluster.behavioral_profile.language_switch_count > 0) && (
        <div className="flex items-center gap-4 text-xs text-on-surface-dim">
          <span className={cluster.behavioral_profile.hesitation_count > 0 ? 'text-warning font-medium' : ''}>
            Hesitation: {cluster.behavioral_profile.hesitation_count} conversation{cluster.behavioral_profile.hesitation_count !== 1 ? 's' : ''}
          </span>
          <span>&middot;</span>
          <span className={cluster.behavioral_profile.language_switch_count > 0 ? 'text-warning font-medium' : ''}>
            Language switches: {cluster.behavioral_profile.language_switch_count}
          </span>
        </div>
      )}

      {cluster.sample_excerpts && cluster.sample_excerpts.length > 0 && (
        <CampaignExcerptsPanel
          excerpts={cluster.sample_excerpts}
          templatedExcerptCount={cluster.behavioral_profile?.templated_excerpt_count ?? 0}
          totalVariantCount={cluster.behavioral_profile?.total_excerpt_variant_count ?? null}
        />
      )}

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
              <AnchorReachRow
                key={ioc.indicator_id}
                anchor={ioc}
                totalConversations={cluster.conversation_count}
                isSelected={selectedIndicatorId === ioc.indicator_id}
                onSelect={handleAnchorClick}
                onOpenDetail={(id) => navigate(`/ioc-explorer/${id}`)}
              />
            ))}
          </div>
        </div>

        {/* Conversations */}
        <div className="rounded-lg border border-border">
          <div className="px-4 py-3 bg-surface-dim border-b border-border space-y-2">
            <div className="flex items-center justify-between">
              <h2 className="text-sm font-medium text-on-surface">
                Conversations ({filteredConversations.length}{filteredConversations.length !== cluster.conversations.length ? ` / ${cluster.conversations.length}` : ''})
              </h2>
              {hasActiveFilter && (
                <button
                  onClick={() => { setSelectedIndicatorId(null); setScamTypeFilter(''); }}
                  className="text-xs text-accent hover:underline cursor-pointer"
                >
                  Clear filters
                </button>
              )}
            </div>
            <div className="flex items-center gap-2">
              <select
                value={scamTypeFilter}
                onChange={(e) => setScamTypeFilter(e.target.value)}
                className={`text-xs px-3 py-1.5 rounded-lg border cursor-pointer transition-colors ${
                  scamTypeFilter ? 'border-accent bg-accent/10 text-accent' : 'border-border bg-surface-dim text-on-surface-dim'
                }`}
                style={{ colorScheme: 'dark' }}
              >
                <option value="" className="bg-neutral-800 text-neutral-200">All scam types</option>
                {scamTypes.map((st) => (
                  <option key={st} value={st} className="bg-neutral-800 text-neutral-200">{scamTypeLabel(st)}</option>
                ))}
              </select>
              <select
                value={sortBy}
                onChange={(e) => setSortBy(e.target.value as SortField)}
                className="text-xs px-3 py-1.5 rounded-lg border border-border bg-surface-dim text-on-surface-dim cursor-pointer"
                style={{ colorScheme: 'dark' }}
              >
                <option value="risk" className="bg-neutral-800 text-neutral-200">Sort: Risk (high first)</option>
                <option value="scam_type" className="bg-neutral-800 text-neutral-200">Sort: Scam type</option>
                <option value="status" className="bg-neutral-800 text-neutral-200">Sort: Status</option>
              </select>
            </div>
            {selectedAnchor && (
              <div className="text-xs text-accent">
                Filtered by: {iocTypeLabel(selectedAnchor.ioc_type)} {selectedAnchor.ioc_value}
              </div>
            )}
          </div>
          <div>
            <div className="divide-y divide-border">
              {(showAllConversations
                ? filteredConversations
                : filteredConversations.slice(0, CONV_PREVIEW_LIMIT)
              ).map((conv) => (
                <Link
                  key={conv.conv_id}
                  to={`/conversations/${conv.conv_id}`}
                  className="px-4 py-3 flex items-center justify-between hover:bg-surface-dim/50 transition-colors"
                >
                  <div className="flex items-center gap-2">
                    <span className="font-mono text-xs text-on-surface">
                      {conv.conv_id.slice(0, 8)}
                    </span>
                    <span className={`px-1.5 py-0.5 text-xs rounded font-medium ${scamTypeColor(conv.scam_type)}`}>
                      {scamTypeLabel(conv.scam_type)}
                    </span>
                  </div>
                  <div className="flex items-center gap-3 text-xs text-on-surface-dim">
                    <span className="capitalize">{conv.status}</span>
                    <span>Risk: {conv.score_risk}</span>
                  </div>
                </Link>
              ))}
              {filteredConversations.length === 0 && (
                <div className="px-4 py-8 text-center text-xs text-on-surface-dim">
                  No conversations match the current filters.
                </div>
              )}
            </div>
            {filteredConversations.length > CONV_PREVIEW_LIMIT && (
              <button
                type="button"
                onClick={() => setShowAllConversations((v) => !v)}
                className="w-full px-4 py-2.5 text-xs text-accent hover:bg-surface-dim/40 border-t border-border transition-colors cursor-pointer"
                data-testid="show-all-conversations"
              >
                {showAllConversations
                  ? `Show top ${CONV_PREVIEW_LIMIT} only`
                  : `Show all ${filteredConversations.length} conversations`}
              </button>
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

export default ClusterDetail;
