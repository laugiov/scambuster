import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAllConversations, PAGE_SIZE } from '@/hooks/useConversations';
import { useMailAccounts } from '@/hooks/useMailAccounts';
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
  const [sortKey, setSortKey] = useState<'ts_last' | 'score_risk' | 'ioc_count' | 'message_count'>('ts_last');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');
  const navigate = useNavigate();
  const { data: conversations, isLoading, error, refetch } = useAllConversations();
  const { data: config } = useMetaConfig();
  const { data: stats } = useAutonomyStats();
  const { data: mailAccounts } = useMailAccounts();

  const statusFilter = searchParams.get('status') ?? '';
  const scamTypeFilter = searchParams.get('scam_type') ?? '';
  const accountFilter = searchParams.get('account') ?? '';

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

  const toggleSort = (key: typeof sortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'));
    else { setSortKey(key); setSortDir('desc'); }
    setPage(1);
  };

  const sorted = [...(conversations ?? [])].sort((a, b) => {
    let va: number, vb: number;
    switch (sortKey) {
      case 'score_risk': va = a.score_risk; vb = b.score_risk; break;
      case 'ioc_count': va = a.ioc_count ?? 0; vb = b.ioc_count ?? 0; break;
      case 'message_count': va = a.message_count ?? a.turns ?? 0; vb = b.message_count ?? b.turns ?? 0; break;
      default: va = new Date(a.ts_last ?? a.updated_at ?? 0).getTime(); vb = new Date(b.ts_last ?? b.updated_at ?? 0).getTime();
    }
    return sortDir === 'desc' ? vb - va : va - vb;
  });

  let filtered = sorted;

  if (statusFilter) {
    filtered = filtered.filter((c) => c.status.toLowerCase() === statusFilter.toLowerCase());
  }
  if (scamTypeFilter) {
    filtered = filtered.filter((c) => c.scam_type === scamTypeFilter);
  }
  if (accountFilter) {
    filtered = filtered.filter((c) => c.account_label === accountFilter);
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
  const abandonedCount = stats?.conversations.abandoned ?? sorted.filter((c) => c.status === 'abandoned').length;

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
            {abandonedCount > 0 && (
              <span className="text-warning" title="Conversations where the scammer stopped responding after 7+ days">{t('conversations.abandoned', { count: abandonedCount })}</span>
            )}
          </div>
        </div>
      </header>

      <FilterBar
        statusFilter={statusFilter}
        scamTypeFilter={scamTypeFilter}
        mailboxFilter={accountFilter}
        onStatusChange={(v) => setFilter('status', v)}
        onScamTypeChange={(v) => setFilter('scam_type', v)}
        onMailboxChange={(v) => setFilter('account', v)}
        statusOptions={[
          { value: 'open', label: t('common.status.open') },
          { value: 'closed', label: t('common.status.closed') },
          { value: 'abandoned', label: t('common.status.abandoned') },
        ]}
        scamTypeOptions={(config?.scam_types ?? []).map((st) => ({ value: st.code, label: scamTypeLabel(st.code) }))}
        mailboxOptions={(mailAccounts ?? [])
          .filter((m) => m.label !== null && m.label !== '')
          .map((m) => ({ value: m.label as string, label: m.label as string }))}
        onClear={clearFilters}
        hasActiveFilters={statusFilter !== '' || scamTypeFilter !== '' || accountFilter !== ''}
      />

      {(statusFilter || scamTypeFilter || search) && (
        <p className="text-xs text-on-surface-dim">
          {filtered.length} {filtered.length === 1 ? 'conversation' : 'conversations'}
          {statusFilter && ` · ${statusFilter}`}
          {scamTypeFilter && ` · ${scamTypeLabel(scamTypeFilter)}`}
          {search && ` · "${search}"`}
        </p>
      )}

      <Pagination page={page} pageSize={PAGE_SIZE} totalItems={filtered.length} onPageChange={setPage} />

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="text-left px-5 py-3 font-medium">{t('conversations.sourceId')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.scamType')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.persona')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('conversations.mailbox')}</th>
              <SortHeader label={t('conversations.risk')} sortKey="score_risk" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortHeader label={t('conversations.iocActionable')} sortKey="ioc_count" current={sortKey} dir={sortDir} onSort={toggleSort} tooltip={t('conversations.iocActionableTooltip')} />
              <SortHeader label={t('conversations.messages')} sortKey="message_count" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortHeader label={t('conversations.lastActivity')} sortKey="ts_last" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <th className="text-left px-5 py-3 font-medium">{t('common.status.open')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {paged.map((conv) => (
              <tr key={conv.conv_id} onClick={() => navigate(`/conversations/${conv.conv_id}`)} className="hover:bg-surface-high/50 transition-colors cursor-pointer">
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
                    <div className="flex flex-wrap items-center gap-1">
                      <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${scamTypeColor(conv.scam_type)}`}>
                        {scamTypeLabel(conv.scam_type)}
                      </span>
                      {conv.secondary_scam_types?.map((st) => (
                        <span key={st.code} className={`inline-flex items-center px-1.5 py-0.5 rounded text-[0.65rem] font-medium opacity-60 ${scamTypeColor(st.code)}`} title={`${Math.round(st.confidence * 100)}%`}>
                          {scamTypeLabel(st.code)}
                        </span>
                      ))}
                    </div>
                  ) : '--'}
                </td>
                <td className="px-5 py-3 text-on-surface-variant max-w-[200px]">
                  {conv.persona ? (
                    <span className="block truncate" title={personaDisplayName(config, conv.persona)}>
                      {personaDisplayName(config, conv.persona)}
                    </span>
                  ) : '--'}
                </td>
                <td className="px-5 py-3 text-on-surface-variant max-w-[200px]">
                  {conv.account_label ? (
                    <span className="block truncate" title={conv.account_email ?? conv.account_label}>
                      {conv.account_label}
                    </span>
                  ) : '--'}
                </td>
                <td className="px-5 py-3">
                  <RiskBar score={conv.score_risk} />
                </td>
                <td className="px-5 py-3 text-on-surface-variant font-mono text-xs">
                  {conv.ioc_count ?? 0}
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
                <td colSpan={9} className="px-5 py-12 text-center text-on-surface-dim">
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

function SortHeader({ label, sortKey: key, current, dir, onSort, tooltip }: {
  label: string;
  sortKey: 'ts_last' | 'score_risk' | 'ioc_count' | 'message_count';
  current: string;
  dir: 'asc' | 'desc';
  onSort: (key: 'ts_last' | 'score_risk' | 'ioc_count' | 'message_count') => void;
  tooltip?: string;
}) {
  const isActive = current === key;
  return (
    <th
      className="text-left px-5 py-3 font-medium cursor-pointer select-none hover:text-on-surface transition-colors"
      onClick={() => onSort(key)}
      title={tooltip}
    >
      {label}
      {tooltip && <span className="ml-1 text-accent-muted opacity-70 cursor-help text-[0.65rem]" aria-label={tooltip}>ⓘ</span>}
      <span className={`ml-1.5 inline-block text-[0.6rem] ${isActive ? 'text-accent' : 'text-on-surface-dim'}`}>
        {isActive ? (dir === 'desc' ? '▼' : '▲') : '⇅'}
      </span>
    </th>
  );
}

export default Conversations;
