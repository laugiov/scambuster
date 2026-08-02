import { useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  TTP_CONVERSATIONS_PAGE_SIZE,
  useTtpClusters,
  useTtpConversations,
  useTtpTaxonomy,
} from '@/hooks/useTtps';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { Pagination } from '@/components/ui/Pagination';
import { TtpIocsSection } from '@/components/ttp/TtpIocsSection';
import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';
import { scamTypeColor, scamTypeLabel } from '@/lib/scamTypeLabels';
import { timeSince } from '@/lib/time';
import type {
  TtpClusters,
  TtpConversations,
  TtpExternalRef,
  TtpTaxonomyRow,
} from '@/types/ttp';

type TabId = 'overview' | 'iocs' | 'clusters' | 'conversations';

/** "Mar 1, 2026" — month name keeps the day/month order unambiguous. */
function formatNonAmbiguousDate(iso: string | null): string {
  if (!iso) return '--';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '--';
  const month = d.toLocaleString('en-US', { month: 'short' });
  return `${month} ${d.getDate()}, ${d.getFullYear()}`;
}

/**
 * Per-TTP detail page (/ttps/:code). The overview reads its taxonomy row from
 * the cached useTtpTaxonomy list (no per-code endpoint exists — a bare
 * /ttps/{code} route would swallow the literal sibling routes). The three
 * pivot tabs are fed by the TTP-keyed endpoints; the co-occurring IOCs panel
 * is mounted lazily so its fetch only fires when that tab is opened.
 */
export function TtpDetail() {
  const { code = '' } = useParams<{ code: string }>();
  const navigate = useNavigate();
  const { t } = useTranslation();
  const { data, isLoading, error, refetch } = useTtpTaxonomy();
  const clustersQuery = useTtpClusters(code);
  const [activeTab, setActiveTab] = useState<TabId>('overview');
  const [convPage, setConvPage] = useState(1);
  const conversationsQuery = useTtpConversations(code, convPage);

  if (isLoading) return <Loading message={t('ttpExplorer.loading')} />;
  if (error) return <ErrorMessage message={t('ttpExplorer.failedLoad')} onRetry={() => void refetch()} />;

  const row = data?.ttps.find((entry) => entry.ttp_code === code);
  if (!row) return <ErrorMessage message={t('ttpDetail.notFound')} onRetry={() => void refetch()} />;

  const clusterCount = clustersQuery.data?.items.length ?? 0;
  const conversationTotal = conversationsQuery.data?.total ?? 0;

  const tabs: { id: TabId; label: string; count?: number }[] = [
    { id: 'overview', label: t('ttpDetail.overview') },
    { id: 'iocs', label: t('ttpDetail.iocs') },
    { id: 'clusters', label: t('ttpDetail.clusters'), count: clusterCount > 0 ? clusterCount : undefined },
    { id: 'conversations', label: t('ttpDetail.conversations'), count: conversationTotal > 0 ? conversationTotal : undefined },
  ];

  return (
    <div className="space-y-6">
      {/* Back button */}
      <button
        data-testid="ttp-detail-back"
        onClick={() => navigate('/ttps')}
        className="flex items-center gap-1 text-sm text-on-surface-dim hover:text-accent transition-colors"
      >
        <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
        {t('ttpDetail.backToExplorer')}
      </button>

      {/* Header */}
      <header className="space-y-3">
        <div className="flex items-center gap-3 flex-wrap">
          <span className="text-xs uppercase px-2 py-0.5 bg-surface-high text-on-surface-variant rounded font-mono font-medium">
            {row.ttp_code}
          </span>
          <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ${ttpPhaseColor(row.phase)}`}>
            {ttpPhaseLabel(row.phase)}
          </span>
          {row.review_count > 0 && (
            <span className="text-xs px-2 py-0.5 rounded font-medium bg-amber-500/20 text-amber-400">
              {t('ttpExplorer.reviewBacklog', { count: row.review_count })}
            </span>
          )}
        </div>
        <h1 className="text-xl font-bold text-on-surface">{row.ttp_label}</h1>
      </header>

      {/* Tabs */}
      <nav className="flex gap-1 border-b border-surface-high">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            data-testid={`ttp-detail-tab-${tab.id}`}
            onClick={() => setActiveTab(tab.id)}
            className={`px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px ${
              activeTab === tab.id
                ? 'border-accent text-accent'
                : 'border-transparent text-on-surface-dim hover:text-on-surface'
            }`}
          >
            {tab.label}
            {tab.count !== undefined && (
              <span
                data-testid={`ttp-detail-tab-${tab.id}-badge`}
                className="ml-1.5 text-xs bg-surface-high px-1.5 py-0.5 rounded-full"
              >
                {tab.count}
              </span>
            )}
          </button>
        ))}
      </nav>

      {/* Tab content — the IOC pivot panel is mounted lazily (conditional
          render, no effect): its fetch never fires until the tab is opened. */}
      {activeTab === 'overview' && <OverviewTab row={row} />}
      {activeTab === 'iocs' && <TtpIocsSection code={code} />}
      {activeTab === 'clusters' && (
        <ClustersTab data={clustersQuery.data} isLoading={clustersQuery.isLoading} isError={clustersQuery.isError} />
      )}
      {activeTab === 'conversations' && (
        <ConversationsTab
          data={conversationsQuery.data}
          isLoading={conversationsQuery.isLoading}
          isError={conversationsQuery.isError}
          page={convPage}
          onPageChange={setConvPage}
        />
      )}
    </div>
  );
}

function MetaField({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-0.5">
      <label className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block">{label}</label>
      <p className="text-sm font-medium text-on-surface">{value}</p>
    </div>
  );
}

/** One ATT&CK-style external reference; a link when a URL is present. */
function ExternalRefItem({ refEntry }: { refEntry: TtpExternalRef }) {
  const text = [refEntry.source_name, refEntry.external_id].filter(Boolean).join(' ');
  const label = text !== '' ? text : (refEntry.url ?? '');

  if (refEntry.url) {
    return (
      <a
        href={refEntry.url}
        target="_blank"
        rel="noopener noreferrer"
        data-testid="ttp-detail-ref-link"
        className="text-xs px-2 py-1 rounded bg-surface-high text-accent hover:bg-surface-highest hover:underline transition-colors"
      >
        {label}
      </a>
    );
  }
  return (
    <span data-testid="ttp-detail-ref" className="text-xs px-2 py-1 rounded bg-surface-high text-on-surface-variant">
      {label}
    </span>
  );
}

/**
 * Overview: definition, usage counters and taxonomy metadata from the cached
 * taxonomy row. A zero-observation code renders honestly (counters at 0,
 * first/last seen '--') — never an error.
 */
function OverviewTab({ row }: { row: TtpTaxonomyRow }) {
  const { t } = useTranslation();

  return (
    <div className="space-y-6">
      {/* Usage counters */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        <MetaField label={t('ttpExplorer.firstSeen')} value={formatNonAmbiguousDate(row.first_seen)} />
        <MetaField label={t('ttpDetail.lastSeen')} value={formatNonAmbiguousDate(row.last_seen)} />
        <MetaField label={t('ttpExplorer.observationsColumn')} value={row.observation_count.toLocaleString()} />
        <MetaField label={t('ttpExplorer.conversationsColumn')} value={row.conversation_count.toLocaleString()} />
        <MetaField label={t('ttpDetail.awaitingReview')} value={row.review_count.toLocaleString()} />
      </div>

      {row.observation_count === 0 && row.review_count === 0 && (
        <p data-testid="ttp-detail-zero-note" className="text-xs text-on-surface-dim italic">
          {t('ttpDetail.neverObserved')}
        </p>
      )}

      {/* Definition */}
      <section className="bg-surface-low rounded-lg p-5 space-y-2">
        <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
          {t('ttpExplorer.definition')}
        </h3>
        <p className="text-sm text-on-surface-variant">
          {row.definition !== '' ? row.definition : t('ttpExplorer.noDefinition')}
        </p>
      </section>

      {/* Example formulations (taxonomy metadata) */}
      {row.examples.length > 0 && (
        <section className="bg-surface-low rounded-lg p-5 space-y-2" data-testid="ttp-detail-examples">
          <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
            {t('ttpDetail.examples')}
          </h3>
          <ul className="space-y-1.5">
            {row.examples.map((example) => (
              <li key={example} className="text-sm text-on-surface-variant italic">
                &ldquo;{example}&rdquo;
              </li>
            ))}
          </ul>
        </section>
      )}

      {/* External references (ATT&CK) */}
      {row.external_refs.length > 0 && (
        <section className="bg-surface-low rounded-lg p-5 space-y-2" data-testid="ttp-detail-refs">
          <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
            {t('ttpDetail.externalRefs')}
          </h3>
          <div className="flex flex-wrap gap-2">
            {row.external_refs.map((refEntry, index) => (
              <ExternalRefItem key={refEntry.external_id ?? refEntry.url ?? index} refEntry={refEntry} />
            ))}
          </div>
        </section>
      )}
    </div>
  );
}

/**
 * Clusters practicing this TTP. 404 resolves to null and renders the empty
 * state (degrade, not a crash); the server cap surfaces as a truncated note.
 */
function ClustersTab({ data, isLoading, isError }: {
  data: TtpClusters | null | undefined;
  isLoading: boolean;
  isError: boolean;
}) {
  const { t } = useTranslation();

  if (isLoading) {
    return <p className="text-sm text-on-surface-dim italic">{t('ttpDetail.clustersLoading')}</p>;
  }
  if (isError) {
    return <p className="text-sm text-error">{t('ttpDetail.clustersFailed')}</p>;
  }

  const items = data?.items ?? [];
  if (items.length === 0) {
    return (
      <div className="text-center py-12 text-on-surface-dim" data-testid="ttp-clusters-empty">
        {t('ttpDetail.clustersEmpty')}
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full text-left">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="px-5 py-3 font-medium">{t('ttpDetail.clusterColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.conversationsColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.observationsColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.firstSeen')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpDetail.lastSeen')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {items.map((cluster) => (
              <tr key={cluster.cluster_id} data-testid="ttp-cluster-row" className="hover:bg-surface-high/50 transition-colors">
                <td className="px-5 py-3">
                  <Link
                    to={`/clusters/${cluster.cluster_id}`}
                    data-testid="ttp-cluster-link"
                    className="text-accent hover:underline"
                  >
                    {cluster.label}
                  </Link>
                </td>
                <td className="px-5 py-3 font-mono text-on-surface-variant">{cluster.conversation_count.toLocaleString()}</td>
                <td className="px-5 py-3 font-mono text-on-surface-variant">{cluster.observation_count.toLocaleString()}</td>
                <td className="px-5 py-3 text-on-surface-dim text-xs">{formatNonAmbiguousDate(cluster.first_seen)}</td>
                <td className="px-5 py-3 text-on-surface-dim text-xs">
                  {cluster.last_seen ? (
                    <span title={timeSince(cluster.last_seen)}>{formatNonAmbiguousDate(cluster.last_seen)}</span>
                  ) : '--'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {data?.truncated && (
        <p className="text-[11px] text-on-surface-dim italic" data-testid="ttp-clusters-truncated">
          {t('ttpDetail.clustersTruncated', { count: items.length })}
        </p>
      )}
    </div>
  );
}

/**
 * Server-paginated conversations where this TTP was observed. The population
 * spans both statuses: a review-only conversation legitimately shows 0
 * confirmed observations next to its review count. Pagination runs off the
 * server total.
 */
function ConversationsTab({ data, isLoading, isError, page, onPageChange }: {
  data: TtpConversations | null | undefined;
  isLoading: boolean;
  isError: boolean;
  page: number;
  onPageChange: (page: number) => void;
}) {
  const { t } = useTranslation();

  if (isLoading) {
    return <p className="text-sm text-on-surface-dim italic">{t('ttpDetail.conversationsLoading')}</p>;
  }
  if (isError) {
    return <p className="text-sm text-error">{t('ttpDetail.conversationsFailed')}</p>;
  }

  const items = data?.items ?? [];
  if (items.length === 0) {
    // Keep the pager reachable when the server still reports a non-zero total
    // (data can shrink mid-session, stranding the user on an empty page > 1).
    return (
      <div className="space-y-2">
        <div className="text-center py-12 text-on-surface-dim" data-testid="ttp-conversations-empty">
          {t('ttpDetail.conversationsEmpty')}
        </div>
        {(data?.total ?? 0) > 0 && (
          <Pagination
            page={page}
            pageSize={TTP_CONVERSATIONS_PAGE_SIZE}
            totalItems={data?.total ?? 0}
            onPageChange={onPageChange}
          />
        )}
      </div>
    );
  }

  return (
    <div className="space-y-2">
      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full text-left">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="px-5 py-3 font-medium">{t('ttpDetail.subjectColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpDetail.scamTypeColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.observationsColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.reviewColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpDetail.lastSeen')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {items.map((conv) => (
              <tr key={conv.conv_id} data-testid="ttp-conversation-row" className="hover:bg-surface-high/50 transition-colors">
                <td className="px-5 py-3">
                  <Link
                    to={`/conversations/${conv.conv_id}`}
                    data-testid="ttp-conversation-link"
                    className="text-accent hover:underline"
                  >
                    {conv.subject ?? '--'}
                  </Link>
                </td>
                <td className="px-5 py-3">
                  {conv.scam_type_code ? (
                    <span className={`text-xs px-2 py-0.5 rounded font-medium ${scamTypeColor(conv.scam_type_code)}`}>
                      {scamTypeLabel(conv.scam_type_code)}
                    </span>
                  ) : (
                    <span className="text-on-surface-dim">--</span>
                  )}
                </td>
                <td className="px-5 py-3 font-mono text-on-surface-variant">{conv.observation_count.toLocaleString()}</td>
                <td className="px-5 py-3">
                  {conv.review_count > 0 ? (
                    <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium bg-amber-500/20 text-amber-400">
                      {conv.review_count.toLocaleString()}
                    </span>
                  ) : (
                    <span className="font-mono text-on-surface-dim">0</span>
                  )}
                </td>
                <td className="px-5 py-3 text-on-surface-dim text-xs">
                  {conv.last_seen ? (
                    <span title={timeSince(conv.last_seen)}>{formatNonAmbiguousDate(conv.last_seen)}</span>
                  ) : '--'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <Pagination
        page={page}
        pageSize={TTP_CONVERSATIONS_PAGE_SIZE}
        totalItems={data?.total ?? 0}
        onPageChange={onPageChange}
      />
    </div>
  );
}

export default TtpDetail;
