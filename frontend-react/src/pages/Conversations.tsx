import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAllConversations, PAGE_SIZE } from '@/hooks/useConversations';
import { Badge } from '@/components/ui/Badge';
import { SearchBar } from '@/components/ui/SearchBar';
import { statusToBadgeVariant } from '@/components/ui/badgeUtils';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
import { useAutonomyStats } from '@/hooks/useStats';
import { Pagination } from '@/components/ui/Pagination';
import { timeSince, formatShortTimestamp } from '@/lib/time';
import { ExportCsvButton } from '@/components/ui/ExportCsvButton';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { RiskBar } from '@/components/ui/RiskBar';
import { FilterBar } from '@/components/ui/FilterBar';
import { useSearchParams } from 'react-router-dom';

export function Conversations() {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const { data: conversations, isLoading, error, refetch } = useAllConversations();
  const { data: config } = useMetaConfig();
  const { data: stats } = useAutonomyStats();

  const statusFilter = searchParams.get('status') ?? '';
  const scamTypeFilter = searchParams.get('scam_type') ?? '';

  const setFilter = (key: string, value: string) => {
    setSearchParams((prev) => {
      if (value) prev.set(key, value);
      else prev.delete(key);
      return prev;
    });
    setPage(1);
  };

  const clearFilters = () => {
    setSearchParams({});
    setPage(1);
  };

  if (isLoading) return <Loading message={t('conversations.loading')} />;
  if (error) return <ErrorMessage message={t('conversations.failedLoad')} onRetry={() => void refetch()} />;

  const sorted = [...(conversations ?? [])].sort(
    (a, b) => new Date(b.ts_last ?? b.updated_at ?? 0).getTime() - new Date(a.ts_last ?? a.updated_at ?? 0).getTime()
  );

  let filtered = sorted;

  if (statusFilter) {
    filtered = filtered.filter((c) => c.status.toLowerCase() === statusFilter.toLowerCase());
  }
  if (scamTypeFilter) {
    filtered = filtered.filter((c) => c.scam_type === scamTypeFilter);
  }
  if (search) {
    const q = search.toLowerCase();
    filtered = filtered.filter((c) =>
      c.conv_id.toLowerCase().includes(q)
      || (c.scam_type ?? '').toLowerCase().includes(q)
      || (c.persona ?? '').toLowerCase().includes(q)
      || personaDisplayName(config, c.persona ?? '').toLowerCase().includes(q),
    );
  }

  const paged = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  const totalCount = stats?.conversations.total ?? sorted.length;
  const activeCount = stats?.conversations.open ?? stats?.conversations.active ?? sorted.filter((c) => c.status === 'open').length;
  const closedCount = stats?.conversations.closed ?? sorted.filter((c) => c.status === 'closed').length;

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-on-surface">{t('conversations.title')}</h1>
        <div className="flex items-center gap-4">
          <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Search by ID, scam type, persona..." ariaLabel="Search conversations" />
          <ExportCsvButton
            data={filtered as unknown as Record<string, unknown>[]}
            columns={[
              { key: 'conv_id', header: 'Conversation ID' },
              { key: 'status', header: 'Status' },
              { key: 'scam_type', header: 'Scam Type' },
              { key: 'persona', header: 'Persona' },
              { key: 'score_risk', header: 'Risk Score' },
              { key: 'message_count', header: 'Messages' },
              { key: 'ts_last', header: 'Last Activity' },
            ]}
            filename={`scambuster-conversations-${new Date().toISOString().slice(0, 10)}.csv`}
          />
          <div className="flex items-center gap-4 text-xs text-on-surface-dim shrink-0">
            <span>{t('conversations.total', { count: totalCount })}</span>
            <span className="text-success">{t('conversations.activeLower', { count: activeCount })}</span>
            <span>{t('conversations.closed', { count: closedCount })}</span>
          </div>
        </div>
      </header>

      <FilterBar
        statusFilter={statusFilter}
        scamTypeFilter={scamTypeFilter}
        onStatusChange={(v) => setFilter('status', v)}
        onScamTypeChange={(v) => setFilter('scam_type', v)}
        statusOptions={[
          { value: 'open', label: t('common.status.open') },
          { value: 'closed', label: t('common.status.closed') },
          { value: 'abandoned', label: t('common.status.abandoned') },
        ]}
        scamTypeOptions={(config?.scam_types ?? []).map((st) => ({ value: st.code, label: scamTypeLabel(st.code) }))}
        onClear={clearFilters}
        hasActiveFilters={statusFilter !== '' || scamTypeFilter !== ''}
      />

      <Pagination page={page} pageSize={PAGE_SIZE} totalItems={filtered.length} onPageChange={setPage} />

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="text-left px-5 py-3 font-medium">{t('conversations.sourceId')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.scamType')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.persona')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.risk')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.messages')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.lastActivity')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('common.status.open')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {paged.map((conv) => (
              <tr key={conv.conv_id} className="hover:bg-surface-high/50 transition-colors">
                <td className="px-5 py-3">
                  <Link
                    to={`/conversations/${conv.conv_id}`}
                    className="text-accent hover:text-accent-hover font-mono text-xs transition-colors"
                  >
                    {conv.conv_id.slice(0, 8)}
                  </Link>
                </td>
                <td className="px-5 py-3">
                  {conv.scam_type ? (
                    <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${scamTypeColor(conv.scam_type)}`}>
                      {scamTypeLabel(conv.scam_type)}
                    </span>
                  ) : '--'}
                </td>
                <td className="px-5 py-3 text-on-surface-variant max-w-[200px]">
                  {conv.persona ? (
                    <span className="block truncate" title={personaDisplayName(config, conv.persona)}>
                      {personaDisplayName(config, conv.persona)}
                    </span>
                  ) : '--'}
                </td>
                <td className="px-5 py-3">
                  <RiskBar score={conv.score_risk} />
                </td>
                <td className="px-5 py-3 text-on-surface-variant font-mono text-xs">
                  {conv.message_count ?? conv.turns ?? '--'}
                </td>
                <td className="px-5 py-3 text-on-surface-dim text-xs" title={conv.ts_last ? timeSince(conv.ts_last) : ''}>
                  {conv.ts_last ? formatShortTimestamp(conv.ts_last) : conv.updated_at ? formatShortTimestamp(conv.updated_at) : '--'}
                </td>
                <td className="px-5 py-3">
                  <Badge label={conv.status} variant={statusToBadgeVariant(conv.status)} />
                </td>
              </tr>
            ))}
            {paged.length === 0 && (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center text-on-surface-dim">
                  {t('conversations.noConversations')}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pagination page={page} pageSize={PAGE_SIZE} totalItems={filtered.length} onPageChange={setPage} />
      </div>
    </div>
  );
}

export default Conversations;
