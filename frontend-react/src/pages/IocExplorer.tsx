import { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAllIocs } from '@/hooks/useIocs';
import { useMetaConfig } from '@/hooks/useMetaConfig';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { SearchBar } from '@/components/ui/SearchBar';
import { Pagination } from '@/components/ui/Pagination';
import type { Ioc } from '@/types/api';
import { timeSince, formatShortTimestamp } from '@/lib/time';
import { ExportCsvButton } from '@/components/ui/ExportCsvButton';
import { iocSeverity as computeIocSeverityInfo } from '@/lib/iocSeverity';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { iocTypeLabel } from '@/lib/iocTypeLabels';

function computeIocSeverity(type: string, vt: number, urlscan: number): string {
  return computeIocSeverityInfo(type, vt, urlscan).label;
}

const INFRA_IOC_FILTER = (ioc: Ioc) => {
  const val = ioc.value.toLowerCase();
  return !val.includes('@scambuster.local');
};

const IOC_PAGE_SIZE = 30;

const HEADER_IOC_TYPES = new Set([
  'message_id', 'subject', 'spf_result', 'dkim_result', 'dmarc_result', 'x_mailer', 'return_path',
]);

const CATEGORY_MAP: Record<string, string> = {
  ipv4: 'IP', ipv6: 'IP',
  domain: 'Domain',
  md5: 'Hash', sha1: 'Hash', sha256: 'Hash',
  email: 'Email', whois_email: 'Email',
  url: 'URL',
  iban: 'Financial', bic: 'Financial', wallet_btc: 'Financial', wallet_eth: 'Financial', wallet_xmr: 'Financial',
  bank_account: 'Financial', credit_card: 'Financial',
};

function buildTypeFilters(iocTypes: string[]): string[] {
  const categories = new Set<string>();
  let hasOther = false;
  for (const t of iocTypes) {
    const cat = CATEGORY_MAP[t.toLowerCase()];
    if (cat) {
      categories.add(cat);
    } else {
      hasOther = true;
    }
  }
  const ordered = ['IP', 'Domain', 'Hash', 'Email', 'URL', 'Financial'].filter((c) => categories.has(c));
  if (hasOther) ordered.push('Other');
  return ['All', ...ordered];
}

function matchesType(iocType: string, filter: string): boolean {
  if (filter === 'All') return true;
  const cat = CATEGORY_MAP[iocType.toLowerCase()];
  if (filter === 'Other') return !cat;
  return cat === filter;
}

function scoreSeverity(iocType: string, vtScore: number, urlscanScore: number): { label: string; color: string; barColor: string } {
  const sev = computeIocSeverityInfo(iocType, vtScore, urlscanScore);
  switch (sev.label) {
    case 'HIGH': return { label: 'High', color: 'text-error', barColor: 'bg-error' };
    case 'MEDIUM': return { label: 'Medium', color: 'text-warning', barColor: 'bg-warning' };
    default: return { label: 'Low', color: 'text-on-surface-dim', barColor: 'bg-on-surface-dim' };
  }
}

function confidenceColor(score: number): { barColor: string; textColor: string } {
  if (score > 0.7) return { barColor: 'bg-success', textColor: 'text-success' };
  if (score > 0.4) return { barColor: 'bg-warning', textColor: 'text-warning' };
  return { barColor: 'bg-error', textColor: 'text-error' };
}

export function IocExplorer() {
  const { t } = useTranslation();
  const { data: iocs, isLoading, error, refetch } = useAllIocs();
  const { data: config } = useMetaConfig();
  const typeFilters = useMemo(() => buildTypeFilters(config?.ioc_types ?? []), [config?.ioc_types]);
  const [typeFilter, setTypeFilter] = useState<string>('All');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [severity, setSeverity] = useState<string>('All');
  const [minConfidence, setMinConfidence] = useState<string>('All');
  const [dateRange, setDateRange] = useState<string>('All');
  const [scamTypeFilter, setScamTypeFilter] = useState<string>('All');
  const [hideHeaders, setHideHeaders] = useState(true);
  const [hasContextOnly, setHasContextOnly] = useState(false);
  const [sortKey, setSortKey] = useState<'ts_observed' | 'confidence' | 'severity'>('ts_observed');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc');

  const toggleSort = (key: typeof sortKey) => {
    if (sortKey === key) setSortDir((d) => (d === 'desc' ? 'asc' : 'desc'));
    else { setSortKey(key); setSortDir('desc'); }
    setPage(1);
  };

  const filtered = useMemo(() => {
    if (!iocs) return [];

    function daysAgo(days: number): string {
      const d = new Date();
      d.setDate(d.getDate() - days);
      return d.toISOString();
    }

    const dateThresholds: Record<string, string> = {
      '7d': daysAgo(7),
      '30d': daysAgo(30),
      '90d': daysAgo(90),
    };

    return iocs.filter(INFRA_IOC_FILTER).filter((ioc) => {
      if (hideHeaders && HEADER_IOC_TYPES.has(ioc.type.toLowerCase())) return false;
      if (!matchesType(ioc.type, typeFilter)) return false;
      if (search && !ioc.value.toLowerCase().includes(search.toLowerCase())) return false;

      const iocSev = (ioc as { severity?: string }).severity ?? computeIocSeverity(ioc.type, ioc.score?.vt ?? 0, ioc.score?.urlscan ?? 0);
      if (severity === 'High' && iocSev !== 'HIGH') return false;
      if (severity === 'Medium' && iocSev !== 'MEDIUM') return false;
      if (severity === 'Low' && iocSev !== 'LOW') return false;

      const effectiveScore = ioc.effective_score ?? ioc.confidence ?? 0;
      if (minConfidence === '>0.9' && effectiveScore <= 0.9) return false;
      if (minConfidence === '>0.7' && effectiveScore <= 0.7) return false;
      if (minConfidence === '>0.5' && effectiveScore <= 0.5) return false;

      if (dateRange !== 'All' && dateThresholds[dateRange]) {
        if (ioc.ts_observed < dateThresholds[dateRange]) return false;
      }

      if (hasContextOnly && !ioc.has_context) return false;

      if (scamTypeFilter !== 'All' && (ioc.category ?? '') !== scamTypeFilter) return false;

      return true;
    });
  }, [iocs, typeFilter, search, severity, minConfidence, dateRange, hideHeaders, hasContextOnly, scamTypeFilter]);

  // Available scam types in current dataset (alphabetically sorted by label)
  const availableScamTypes = useMemo(() => {
    if (!iocs) return [] as string[];
    const codes = new Set<string>();
    for (const ioc of iocs) {
      const c = (ioc.category ?? '').trim();
      if (c) codes.add(c);
    }
    return Array.from(codes).sort((a, b) => scamTypeLabel(a).localeCompare(scamTypeLabel(b)));
  }, [iocs]);

  const sorted = useMemo(() => {
    const SEVERITY_ORDER: Record<string, number> = { HIGH: 3, MEDIUM: 2, LOW: 1 };
    return [...filtered].sort((a, b) => {
      let va: number, vb: number;
      switch (sortKey) {
        case 'confidence': va = a.effective_score ?? a.confidence ?? 0; vb = b.effective_score ?? b.confidence ?? 0; break;
        case 'severity': {
          const sa = computeIocSeverity(a.type, a.score?.vt ?? 0, a.score?.urlscan ?? 0);
          const sb = computeIocSeverity(b.type, b.score?.vt ?? 0, b.score?.urlscan ?? 0);
          va = SEVERITY_ORDER[sa] ?? 0; vb = SEVERITY_ORDER[sb] ?? 0; break;
        }
        default: va = new Date(a.ts_observed).getTime(); vb = new Date(b.ts_observed).getTime();
      }
      return sortDir === 'desc' ? vb - va : va - vb;
    });
  }, [filtered, sortKey, sortDir]);

  if (isLoading) return <Loading message={t('iocExplorer.loading')} />;
  if (error) return <ErrorMessage message={t('iocExplorer.failedLoad')} onRetry={() => void refetch()} />;

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 bg-accent rounded-full animate-pulse" />
          <span className="text-xs uppercase tracking-widest text-accent/80 font-bold" title="IOC list updates automatically as new indicators are extracted">{t('iocExplorer.realTimeAnalysis')}</span>
        </div>
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-light text-on-surface tracking-tight">{t('iocExplorer.title')}</h1>
          <div className="ml-8 flex items-center gap-3">
            <SearchBar value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder={t('iocExplorer.searchPlaceholder')} ariaLabel="Search IOCs" />
            <ExportCsvButton
              data={sorted as unknown as Record<string, unknown>[]}
              columns={[
                { key: 'type', header: 'Type' },
                { key: 'value', header: 'Value' },
                { key: 'category', header: 'Category' },
                { key: 'confidence', header: 'Confidence' },
                { key: 'effective_score', header: 'Effective Score' },
                { key: 'ts_observed', header: 'Observed At' },
              ]}
              filename={`scambuster-iocs-${new Date().toISOString().slice(0, 10)}.csv`}
            />
            <ExportStixButton indicatorIds={sorted.map((ioc) => ioc.ioc_id)} count={sorted.length} />
          </div>
        </div>
      </header>

      <FilterBar typeFilter={typeFilter} onTypeChange={setTypeFilter} total={sorted.length} typeFilters={typeFilters} />

      <AdvancedFilters
        severity={severity}
        onSeverityChange={(v) => { setSeverity(v); setPage(1); }}
        minConfidence={minConfidence}
        onMinConfidenceChange={(v) => { setMinConfidence(v); setPage(1); }}
        dateRange={dateRange}
        onDateRangeChange={(v) => { setDateRange(v); setPage(1); }}
        scamTypeFilter={scamTypeFilter}
        onScamTypeChange={(v) => { setScamTypeFilter(v); setPage(1); }}
        availableScamTypes={availableScamTypes}
        hideHeaders={hideHeaders}
        onHideHeadersChange={(v) => { setHideHeaders(v); setPage(1); }}
        hasContextOnly={hasContextOnly}
        onHasContextOnlyChange={(v) => { setHasContextOnly(v); setPage(1); }}
      />

      <Pagination page={page} pageSize={IOC_PAGE_SIZE} totalItems={sorted.length} onPageChange={setPage} />

      <IocTable iocs={sorted.slice((page - 1) * IOC_PAGE_SIZE, page * IOC_PAGE_SIZE)} sortKey={sortKey} sortDir={sortDir} onSort={toggleSort} />
      <Pagination page={page} pageSize={IOC_PAGE_SIZE} totalItems={sorted.length} onPageChange={setPage} />
    </div>
  );
}

function FilterBar({ typeFilter, onTypeChange, total, typeFilters }: {
  typeFilter: string;
  onTypeChange: (t: string) => void;
  total: number;
  typeFilters: string[];
}) {
  const { t } = useTranslation();

  return (
    <div className="flex items-center gap-6">
      <div className="flex items-center gap-2">
        <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.typeFilter')}</span>
        <div className="flex gap-1.5">
          {typeFilters.map((tf) => (
            <button
              key={tf}
              onClick={() => onTypeChange(tf)}
              className={`px-3 py-1 text-xs rounded-full transition-colors cursor-pointer ${
                typeFilter === tf
                  ? 'bg-accent-muted text-on-surface font-medium'
                  : 'bg-surface-high hover:bg-surface-highest text-on-surface-variant'
              }`}
            >
              {tf}
            </button>
          ))}
        </div>
      </div>
      <span className="ml-auto text-xs text-on-surface-dim">{t('iocExplorer.indicators', { count: total })}</span>
    </div>
  );
}

function AdvancedFilters({
  severity, onSeverityChange,
  minConfidence, onMinConfidenceChange,
  dateRange, onDateRangeChange,
  scamTypeFilter, onScamTypeChange, availableScamTypes,
  hideHeaders, onHideHeadersChange,
  hasContextOnly, onHasContextOnlyChange,
}: {
  severity: string; onSeverityChange: (v: string) => void;
  minConfidence: string; onMinConfidenceChange: (v: string) => void;
  dateRange: string; onDateRangeChange: (v: string) => void;
  scamTypeFilter: string; onScamTypeChange: (v: string) => void; availableScamTypes: string[];
  hideHeaders: boolean; onHideHeadersChange: (v: boolean) => void;
  hasContextOnly: boolean; onHasContextOnlyChange: (v: boolean) => void;
}) {
  const { t } = useTranslation();

  const pillBtn = (active: boolean) =>
    `px-3 py-1 text-xs rounded-full transition-colors cursor-pointer ${
      active ? 'bg-accent-muted text-on-surface font-medium' : 'bg-surface-high hover:bg-surface-highest text-on-surface-variant'
    }`;

  return (
    <div className="flex flex-wrap items-center gap-4">
      {/* Severity */}
      <div className="flex items-center gap-2">
        <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.severity')}</span>
        <div className="flex gap-1">
          {['All', 'High', 'Medium', 'Low'].map((s) => (
            <button key={s} onClick={() => onSeverityChange(s)} className={pillBtn(severity === s)}>{s}</button>
          ))}
        </div>
      </div>

      {/* Confidence */}
      <div className="flex items-center gap-2">
        <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.confidence')}</span>
        <select
          value={minConfidence}
          onChange={(e) => onMinConfidenceChange(e.target.value)}
          className="text-xs bg-surface-high text-on-surface rounded px-2 py-1 border-none cursor-pointer"
        >
          <option value="All">{t('iocExplorer.all')}</option>
          <option value=">0.9">&gt; 0.9</option>
          <option value=">0.7">&gt; 0.7</option>
          <option value=">0.5">&gt; 0.5</option>
        </select>
      </div>

      {/* Scam type */}
      <div className="flex items-center gap-2">
        <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.scamType')}:</span>
        <select
          value={scamTypeFilter}
          onChange={(e) => onScamTypeChange(e.target.value)}
          className="text-xs bg-surface-high text-on-surface rounded px-2 py-1 border-none cursor-pointer"
        >
          <option value="All">{t('iocExplorer.all')}</option>
          {availableScamTypes.map((code) => (
            <option key={code} value={code}>{scamTypeLabel(code)}</option>
          ))}
        </select>
      </div>

      {/* Date range */}
      <div className="flex items-center gap-2">
        <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.dateRange')}</span>
        <div className="flex gap-1">
          {['7d', '30d', '90d', 'All'].map((d) => (
            <button key={d} onClick={() => onDateRangeChange(d)} className={pillBtn(dateRange === d)}>{d}</button>
          ))}
        </div>
      </div>

      {/* Checkbox filters */}
      <div className="flex items-center gap-4 ml-auto">
        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={hasContextOnly}
            onChange={(e) => onHasContextOnlyChange(e.target.checked)}
            className="rounded accent-accent"
          />
          <span className="text-xs text-on-surface-dim"><span className="text-[0.5rem] px-1 py-0.5 bg-accent-muted/20 text-accent rounded font-bold mr-1">CTX</span>{t('iocContext.hasContext')}</span>
        </label>
        <label className="flex items-center gap-2 cursor-pointer">
          <input
            type="checkbox"
            checked={hideHeaders}
            onChange={(e) => onHideHeadersChange(e.target.checked)}
            className="rounded accent-accent"
          />
          <span className="text-xs text-on-surface-dim">{t('iocExplorer.hideHeaders')}</span>
        </label>
      </div>
    </div>
  );
}

type SortKey = 'ts_observed' | 'confidence' | 'severity';

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

function IocTable({ iocs, sortKey, sortDir, onSort }: { iocs: Ioc[]; sortKey: SortKey; sortDir: 'asc' | 'desc'; onSort: (k: SortKey) => void }) {
  const { t } = useTranslation();
  const navigate = useNavigate();

  return (
    <div className="bg-surface-low rounded-lg overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
            <th className="px-5 py-3 font-medium">{t('iocExplorer.id')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.type')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.value')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.scamType')}</th>
            <SortTh label={t('iocExplorer.score')} sortKey="severity" current={sortKey} dir={sortDir} onSort={onSort} />
            <SortTh label={t('iocExplorer.confidence')} sortKey="confidence" current={sortKey} dir={sortDir} onSort={onSort} />
            <SortTh label={t('iocExplorer.lastSeen')} sortKey="ts_observed" current={sortKey} dir={sortDir} onSort={onSort} />
            <th className="px-5 py-3 font-medium text-center"></th>
          </tr>
        </thead>
        <tbody className="text-sm">
          {iocs.map((ioc) => {
            const sev = scoreSeverity(ioc.type, ioc.score?.vt ?? 0, ioc.score?.urlscan ?? 0);
            return (
              <tr
                key={ioc.obs_id}
                onClick={() => navigate(`/ioc-explorer/${ioc.ioc_id}`)}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); navigate(`/ioc-explorer/${ioc.ioc_id}`); } }}
                tabIndex={0}
                role="link"
                className="transition-colors cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-accent hover:bg-surface-high/50"
              >
                <td className="px-5 py-3 text-on-surface-dim font-mono text-xs">
                  {ioc.obs_id.slice(0, 8)}
                </td>
                <td className="px-5 py-3">
                  <span className="text-xs text-on-surface-variant">{iocTypeLabel(ioc.type)}</span>
                  {ioc.has_context && (
                    <span className="ml-1 text-[0.5rem] px-1 py-0.5 bg-accent-muted/20 text-accent rounded font-bold" title="Has contextual enrichment">CTX</span>
                  )}
                </td>
                <td className="px-5 py-3 font-mono text-on-surface truncate max-w-[200px]">
                  {ioc.value}
                </td>
                <td className="px-5 py-3">
                  {ioc.category ? (
                    <span className={`inline-flex items-center px-1.5 py-0.5 rounded text-[0.625rem] font-medium ${scamTypeColor(ioc.category)}`}>
                      {scamTypeLabel(ioc.category)}
                    </span>
                  ) : '--'}
                </td>
                <td className="px-5 py-3">
                  <span className={`text-xs font-bold ${sev.color}`}>{sev.label}</span>
                </td>
                <td className="px-5 py-3">
                  {(() => {
                    const es = ioc.effective_score ?? ioc.confidence ?? 0;
                    const cc = confidenceColor(es);
                    return (
                      <div className="flex items-center gap-2">
                        <div className="w-16 h-1.5 bg-surface-highest rounded-full overflow-hidden">
                          <div
                            className={`h-full rounded-full ${cc.barColor}`}
                            style={{ width: `${Math.min(es * 100, 100)}%` }}
                          />
                        </div>
                        <span className={`text-xs font-bold ${cc.textColor}`}>
                          {es.toFixed(2)}
                        </span>
                      </div>
                    );
                  })()}
                </td>
                <td className="px-5 py-3 text-on-surface-dim text-xs">
                  <span title={timeSince(ioc.ts_observed)}>{formatShortTimestamp(ioc.ts_observed)}</span>
                </td>
                <td className="px-5 py-3 text-center text-on-surface-dim">
                  <svg className="w-4 h-4 inline-block" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
                    <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                  </svg>
                </td>
              </tr>
            );
          })}
          {iocs.length === 0 && (
            <tr>
              <td colSpan={8} className="px-5 py-12 text-center text-on-surface-dim">
                {t('iocExplorer.noMatch')}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function ExportStixButton({ indicatorIds, count }: { indicatorIds: string[]; count: number }) {
  const [exporting, setExporting] = useState(false);

  const handleExport = async () => {
    if (count === 0 || exporting) return;
    setExporting(true);

    try {
      const { default: client } = await import('@/api/client');
      const { ENDPOINTS } = await import('@/api/endpoints');
      const { data } = await client.post(ENDPOINTS.iocs.exportStix, {
        indicator_ids: [...new Set(indicatorIds)].slice(0, 500),
      });
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `scambuster-stix-${count}-iocs-${new Date().toISOString().slice(0, 10)}.json`;
      a.click();
      URL.revokeObjectURL(url);
    } finally {
      setExporting(false);
    }
  };

  return (
    <button
      type="button"
      onClick={() => void handleExport()}
      disabled={count === 0 || exporting}
      className="flex items-center gap-1.5 px-3 py-1.5 text-xs rounded bg-surface-high hover:bg-surface-highest text-on-surface-variant hover:text-accent transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
    >
      <svg className="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
        <path strokeLinecap="round" strokeLinejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
      </svg>
      {exporting ? 'Exporting...' : `STIX 2.1 (${count})`}
    </button>
  );
}

export default IocExplorer;
