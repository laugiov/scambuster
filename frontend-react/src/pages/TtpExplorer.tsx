import { useState, useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import {
  BarChart, Bar, Cell, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid,
} from 'recharts';
import { useTtpTaxonomy } from '@/hooks/useTtps';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { SearchBar } from '@/components/ui/SearchBar';
import { Pagination } from '@/components/ui/Pagination';
import { ClusterTtpMatrix } from '@/components/ttp/ClusterTtpMatrix';
import { PhaseTransitionsMatrix } from '@/components/ttp/PhaseTransitionsMatrix';
import { PhaseTrendChart } from '@/components/ttp/PhaseTrendChart';
import { PersonaTtpMatrix } from '@/components/ttp/PersonaTtpMatrix';
import { StimulusTtpMatrix } from '@/components/ttp/StimulusTtpMatrix';
import { ReviewQueueTable } from '@/components/ttp/ReviewQueueTable';
import { SequencesPanel } from '@/components/ttp/SequencesPanel';
import { SubTabNav } from '@/components/ttp/SubTabNav';
import { PHASE_HEX, PHASE_ORDER, ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';
import { timeSince, formatShortTimestamp } from '@/lib/time';
import type { TtpTaxonomyRow } from '@/types/ttp';

const TTP_PAGE_SIZE = 15;
const GRID_COLOR = '#31353c';
const AXIS_COLOR = '#6b7280';
const TOOLTIP_BG = '#181c22';

// The four deep-linkable Explorer tabs (?tab=…). Anything else in the URL
// simply renders the taxonomy — the URL is never rewritten.
const VALID_TABS = ['taxonomy', 'analytics', 'playbooks', 'review'] as const;
type TabId = (typeof VALID_TABS)[number];

function isTabId(value: string | null): value is TabId {
  return (VALID_TABS as readonly string[]).includes(value ?? '');
}

// Per-tab in-tab sub-views (?view=…), scoped to the active ?tab=. The first id
// is the default. Tabs absent from this map (taxonomy, review) have no sub-tabs
// and ignore ?view= entirely. Like the main tab, the active sub-view is DERIVED
// IN RENDER: an invalid or absent ?view= resolves to the tab's first sub-view
// WITHOUT rewriting the URL.
const SUBTABS = {
  playbooks: ['matrix', 'sequences', 'phases'],
  analytics: ['activity', 'persona', 'stimulus'],
} as const satisfies Partial<Record<TabId, readonly string[]>>;

function resolveView(tab: TabId, viewParam: string | null): string {
  const valid = (SUBTABS as Partial<Record<TabId, readonly string[]>>)[tab];
  if (!valid) return '';
  return valid.includes(viewParam ?? '') ? (viewParam as string) : valid[0];
}

type SortKey = 'observation_count' | 'conversation_count' | 'review_count' | 'last_seen';

function ChartTooltip({ active, payload, label }: { active?: boolean; payload?: { value: number }[]; label?: string }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="bg-surface-low border border-outline-variant rounded px-3 py-2 text-xs shadow-lg" style={{ backgroundColor: TOOLTIP_BG }}>
      <p className="text-on-surface-dim mb-1">{label}</p>
      <p className="text-on-surface font-mono">{payload[0].value.toLocaleString()}</p>
    </div>
  );
}

function SortTh({ label, sortKey: key, current, dir, onSort }: {
  label: string; sortKey: SortKey; current: SortKey; dir: 'asc' | 'desc'; onSort: (k: SortKey) => void;
}) {
  const isActive = current === key;
  return (
    <th className="px-5 py-3 font-medium cursor-pointer select-none hover:text-on-surface transition-colors" onClick={() => onSort(key)}>
      {label}
      <span className={`ml-1.5 inline-block text-[0.6rem] ${isActive ? 'text-accent' : 'text-on-surface-dim'}`}>
        {isActive ? (dir === 'desc' ? '▼' : '▲') : '⇅'}
      </span>
    </th>
  );
}

export function TtpExplorer() {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const { data, isLoading, error, refetch } = useTtpTaxonomy();

  // The active tab is DERIVED in render from the URL — no local tab state and
  // no URL-normalizing effect: an invalid or absent ?tab= renders the taxonomy
  // while the URL stays untouched.
  const tabParam = searchParams.get('tab');
  const activeTab: TabId = isTabId(tabParam) ? tabParam : 'taxonomy';

  // The active sub-view is derived from ?view= in the context of the active tab
  // (see resolveView) — no local state, no URL-normalizing effect.
  const activeView = resolveView(activeTab, searchParams.get('view'));

  const rows = useMemo(() => data?.ttps ?? [], [data?.ttps]);

  const totalReview = useMemo(
    () => rows.reduce((sum, r) => sum + r.review_count, 0),
    [rows],
  );

  // Switching the MAIN tab drops any stale ?view=, so each tab opens on its own
  // default sub-view rather than inheriting a sibling tab's sub-view.
  const selectTab = (tab: TabId) => {
    setSearchParams((prev) => {
      prev.set('tab', tab);
      prev.delete('view');
      return prev;
    });
  };

  // Switching a SUB-tab sets ?view= while keeping ?tab= (and any other params).
  const selectView = (view: string) => {
    setSearchParams((prev) => {
      prev.set('view', view);
      return prev;
    });
  };

  if (isLoading) return <Loading message={t('ttpExplorer.loading')} />;
  if (error) return <ErrorMessage message={t('ttpExplorer.failedLoad')} onRetry={() => void refetch()} />;

  const tabs: { id: TabId; label: string; count?: number }[] = [
    { id: 'taxonomy', label: t('ttpExplorer.tabs.taxonomy') },
    { id: 'analytics', label: t('ttpExplorer.tabs.analytics') },
    { id: 'playbooks', label: t('ttpExplorer.tabs.playbooks') },
    { id: 'review', label: t('ttpExplorer.tabs.review'), count: totalReview },
  ];

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <div className="flex items-center gap-2">
          <span className="text-xs uppercase tracking-widest text-accent/80 font-bold">{t('ttpExplorer.eyebrow')}</span>
          {data?.taxonomy_version && (
            <span className="text-[11px] text-on-surface-dim">v{data.taxonomy_version}</span>
          )}
        </div>
        <h1 className="text-2xl font-light text-on-surface tracking-tight">{t('ttpExplorer.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('ttpExplorer.subtitle')}</p>
      </header>

      {/* Tab nav */}
      <nav className="flex gap-1 border-b border-surface-high">
        {tabs.map((tab) => (
          <button
            key={tab.id}
            data-testid={`ttp-tab-${tab.id}`}
            onClick={() => selectTab(tab.id)}
            className={`px-4 py-2 text-sm font-medium transition-colors border-b-2 -mb-px ${
              activeTab === tab.id
                ? 'border-accent text-accent'
                : 'border-transparent text-on-surface-dim hover:text-on-surface'
            }`}
          >
            {tab.label}
            {tab.count !== undefined && (
              <span
                data-testid={`ttp-tab-${tab.id}-badge`}
                className="ml-1.5 text-xs bg-surface-high px-1.5 py-0.5 rounded-full"
              >
                {tab.count}
              </span>
            )}
          </button>
        ))}
      </nav>

      {/* Tab content */}
      {activeTab === 'taxonomy' && (
        <TaxonomyTab rows={rows} totalReview={totalReview} onGoToReview={() => selectTab('review')} />
      )}
      {activeTab === 'analytics' && (
        <AnalyticsTab rows={rows} view={activeView} onSelectView={selectView} />
      )}
      {activeTab === 'playbooks' && (
        <PlaybooksTab view={activeView} onSelectView={selectView} />
      )}
      {activeTab === 'review' && <ReviewQueueTable />}
    </div>
  );
}

/**
 * Taxonomy tab: the searchable, phase-filterable, sortable taxonomy table.
 * Rows navigate to the per-TTP detail page; the review pill deep-links to the
 * review tab while the review-only checkbox keeps filtering in place.
 */
function TaxonomyTab({ rows, totalReview, onGoToReview }: {
  rows: TtpTaxonomyRow[];
  totalReview: number;
  onGoToReview: () => void;
}) {
  const { t } = useTranslation();

  const [search, setSearch] = useState('');
  const [phaseFilter, setPhaseFilter] = useState<string>('All');
  const [reviewOnly, setReviewOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [sortKey, setSortKey] = useState<SortKey>('observation_count');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'));
    else { setSortKey(key); setSortDir('desc'); }
    setPage(1);
  };

  const filtered = useMemo(() => {
    const q = search.trim().toLowerCase();
    return rows.filter((row) => {
      if (phaseFilter !== 'All' && row.phase !== phaseFilter) return false;
      if (reviewOnly && row.review_count === 0) return false;
      if (q) {
        const haystack = `${row.ttp_code} ${row.ttp_label} ${row.definition}`.toLowerCase();
        if (!haystack.includes(q)) return false;
      }
      return true;
    });
  }, [rows, search, phaseFilter, reviewOnly]);

  const sorted = useMemo(() => {
    return [...filtered].sort((a, b) => {
      let va: number, vb: number;
      switch (sortKey) {
        case 'conversation_count': va = a.conversation_count; vb = b.conversation_count; break;
        case 'review_count': va = a.review_count; vb = b.review_count; break;
        case 'last_seen':
          va = a.last_seen ? new Date(a.last_seen).getTime() : 0;
          vb = b.last_seen ? new Date(b.last_seen).getTime() : 0;
          break;
        default: va = a.observation_count; vb = b.observation_count;
      }
      if (va === vb) return a.ttp_code.localeCompare(b.ttp_code);
      return sortDir === 'desc' ? vb - va : va - vb;
    });
  }, [filtered, sortKey, sortDir]);

  const paged = sorted.slice((page - 1) * TTP_PAGE_SIZE, page * TTP_PAGE_SIZE);

  return (
    <div className="space-y-6">
      {/* Filter bar */}
      <div className="flex flex-wrap items-center gap-4">
        <SearchBar
          value={search}
          onChange={(v) => { setSearch(v); setPage(1); }}
          placeholder={t('ttpExplorer.searchPlaceholder')}
          ariaLabel="Search TTPs"
        />
        <div className="flex items-center gap-2">
          <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('ttpExplorer.phaseFilter')}</span>
          <div className="flex flex-wrap gap-1.5">
            {['All', ...PHASE_ORDER].map((ph) => (
              <button
                key={ph}
                onClick={() => { setPhaseFilter(ph); setPage(1); }}
                className={`px-3 py-1 text-xs rounded-full transition-colors cursor-pointer ${
                  phaseFilter === ph
                    ? 'bg-accent-muted text-on-surface font-medium'
                    : 'bg-surface-high hover:bg-surface-highest text-on-surface-variant'
                }`}
              >
                {ph === 'All' ? t('ttpExplorer.all') : ttpPhaseLabel(ph)}
              </button>
            ))}
          </div>
        </div>

        <div className="flex items-center gap-4 ml-auto">
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="checkbox"
              checked={reviewOnly}
              onChange={(e) => { setReviewOnly(e.target.checked); setPage(1); }}
              className="rounded accent-accent"
            />
            <span className="text-xs text-on-surface-dim">{t('ttpExplorer.reviewOnly')}</span>
          </label>
          <button
            type="button"
            onClick={onGoToReview}
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium transition-colors cursor-pointer ${
              totalReview > 0
                ? 'bg-amber-500/20 text-amber-400 hover:bg-amber-500/30'
                : 'bg-surface-high text-on-surface-dim hover:bg-surface-highest'
            }`}
            title={t('ttpExplorer.reviewBacklogTooltip')}
          >
            {t('ttpExplorer.reviewBacklog', { count: totalReview })}
          </button>
        </div>
      </div>

      <Pagination page={page} pageSize={TTP_PAGE_SIZE} totalItems={sorted.length} onPageChange={setPage} />

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full text-left">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.codeColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.labelColumn')}</th>
              <th className="px-5 py-3 font-medium">{t('ttpExplorer.phaseColumn')}</th>
              <SortTh label={t('ttpExplorer.observationsColumn')} sortKey="observation_count" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpExplorer.conversationsColumn')} sortKey="conversation_count" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpExplorer.reviewColumn')} sortKey="review_count" current={sortKey} dir={sortDir} onSort={toggleSort} />
              <SortTh label={t('ttpExplorer.lastSeenColumn')} sortKey="last_seen" current={sortKey} dir={sortDir} onSort={toggleSort} />
            </tr>
          </thead>
          <tbody className="text-sm">
            {paged.map((row) => (
              <TtpRow key={row.ttp_code} row={row} />
            ))}
            {paged.length === 0 && (
              <tr>
                <td colSpan={7} className="px-5 py-12 text-center text-on-surface-dim">
                  {t('ttpExplorer.noMatch')}
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
      <Pagination page={page} pageSize={TTP_PAGE_SIZE} totalItems={sorted.length} onPageChange={setPage} />
    </div>
  );
}

/** One taxonomy row; clicking (or Enter/Space) navigates to the TTP detail page. */
function TtpRow({ row }: { row: TtpTaxonomyRow }) {
  const navigate = useNavigate();
  const unused = row.observation_count === 0;

  const goToDetail = () => navigate(`/ttps/${row.ttp_code}`);

  return (
    <tr
      data-testid="ttp-row"
      onClick={goToDetail}
      onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); goToDetail(); } }}
      tabIndex={0}
      role="link"
      className={`transition-colors cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-accent hover:bg-surface-high/50 ${unused ? 'opacity-60' : ''}`}
    >
      <td className="px-5 py-3 font-mono text-xs text-on-surface-dim">{row.ttp_code}</td>
      <td className="px-5 py-3 text-on-surface">{row.ttp_label}</td>
      <td className="px-5 py-3">
        <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(row.phase)}`}>
          {ttpPhaseLabel(row.phase)}
        </span>
      </td>
      <td className="px-5 py-3 font-mono text-on-surface-variant">{row.observation_count.toLocaleString()}</td>
      <td className="px-5 py-3 font-mono text-on-surface-variant">{row.conversation_count.toLocaleString()}</td>
      <td className="px-5 py-3">
        {row.review_count > 0 ? (
          <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium bg-amber-500/20 text-amber-400">
            {row.review_count.toLocaleString()}
          </span>
        ) : (
          <span className="font-mono text-on-surface-dim">0</span>
        )}
      </td>
      <td className="px-5 py-3 text-on-surface-dim text-xs">
        {row.last_seen ? (
          <span title={timeSince(row.last_seen)}>{formatShortTimestamp(row.last_seen)}</span>
        ) : '--'}
      </td>
    </tr>
  );
}

/**
 * Playbooks tab: three heavy analytical modules split into secondary sub-tabs
 * (matrix | sequences | phases) rather than stacked vertically. Only the active
 * sub-view's self-fetching panel mounts, so it fetches lazily.
 */
function PlaybooksTab({ view, onSelectView }: {
  view: string;
  onSelectView: (view: string) => void;
}) {
  const { t } = useTranslation();

  const subtabs = [
    { id: 'matrix', label: t('ttpExplorer.subtabs.matrix') },
    { id: 'sequences', label: t('ttpExplorer.subtabs.sequences') },
    { id: 'phases', label: t('ttpExplorer.subtabs.phases') },
  ];

  return (
    <div className="space-y-6">
      <SubTabNav tabs={subtabs} active={view} onSelect={onSelectView} />
      {view === 'matrix' && <ClusterTtpMatrix />}
      {view === 'sequences' && <SequencesPanel />}
      {view === 'phases' && <PhaseTransitionsMatrix />}
    </div>
  );
}

/**
 * Analytics tab: three modules split into secondary sub-tabs rather than
 * stacked — activity (phase-distribution chart + 8-week phase-evolution trend)
 * | persona (persona × TTP matrix) | stimulus (stimulus × TTP matrix). The
 * phase-distribution chart derives per-phase confirmed totals from the taxonomy
 * payload; the other panels self-fetch and mount only when their sub-tab is
 * active.
 */
function AnalyticsTab({ rows, view, onSelectView }: {
  rows: TtpTaxonomyRow[];
  view: string;
  onSelectView: (view: string) => void;
}) {
  const { t } = useTranslation();

  // Observations per kill-chain phase, always in canonical order (zero-safe).
  const phaseChartData = useMemo(() => {
    const totals = new Map<string, number>();
    for (const row of rows) {
      totals.set(row.phase, (totals.get(row.phase) ?? 0) + row.observation_count);
    }
    return PHASE_ORDER.map((phase) => ({
      phase,
      label: ttpPhaseLabel(phase),
      count: totals.get(phase) ?? 0,
    }));
  }, [rows]);

  const totalObservations = useMemo(
    () => rows.reduce((sum, r) => sum + r.observation_count, 0),
    [rows],
  );

  const subtabs = [
    { id: 'activity', label: t('ttpExplorer.subtabs.activity') },
    { id: 'persona', label: t('ttpExplorer.subtabs.persona') },
    { id: 'stimulus', label: t('ttpExplorer.subtabs.stimulus') },
  ];

  return (
    <div className="space-y-6">
      <SubTabNav tabs={subtabs} active={view} onSelect={onSelectView} />

      {view === 'activity' && (
        <div className="space-y-6">
          {/* Phase distribution chart */}
          <div className="bg-surface-low rounded-lg p-5">
            <div className="flex items-center justify-between mb-4">
              <h3 className="text-sm font-medium text-on-surface">{t('ttpExplorer.phaseChartTitle')}</h3>
              <span className="text-xs text-on-surface-dim">{t('ttpExplorer.totalObservations', { count: totalObservations })}</span>
            </div>
            <div className="h-64">
              {totalObservations > 0 ? (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={phaseChartData}>
                    <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                    <XAxis dataKey="label" tick={{ fill: AXIS_COLOR, fontSize: 10 }} interval={0} />
                    <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} allowDecimals={false} />
                    <Tooltip content={<ChartTooltip />} cursor={{ fill: 'rgba(255,255,255,0.04)' }} />
                    <Bar dataKey="count" name={t('ttpExplorer.observationsColumn')} radius={[4, 4, 0, 0]}>
                      {phaseChartData.map((entry) => (
                        <Cell key={entry.phase} fill={PHASE_HEX[entry.phase] ?? '#94a3b8'} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div className="h-full flex items-center justify-center text-on-surface-dim text-sm">
                  {t('ttpExplorer.chartEmpty')}
                </div>
              )}
            </div>
          </div>

          {/* 8-week phase evolution (weekly stacked bars, backend-bucketed) */}
          <PhaseTrendChart />
        </div>
      )}

      {/* Persona × TTP and stimulus × TTP matrices (self-fetching, honesty-gated) */}
      {view === 'persona' && <PersonaTtpMatrix />}
      {view === 'stimulus' && <StimulusTtpMatrix />}
    </div>
  );
}

export default TtpExplorer;
