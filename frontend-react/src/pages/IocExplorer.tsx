import { useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useAllIocs } from '@/hooks/useIocs';
import { useMetaConfig } from '@/hooks/useMetaConfig';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import type { Ioc } from '@/types/api';

const CATEGORY_MAP: Record<string, string> = {
  ipv4: 'IP', ipv6: 'IP',
  domain: 'Domain',
  md5: 'Hash', sha1: 'Hash', sha256: 'Hash',
  email: 'Email', whois_email: 'Email',
  url: 'URL',
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
  const ordered = ['IP', 'Domain', 'Hash', 'Email', 'URL'].filter((c) => categories.has(c));
  if (hasOther) ordered.push('Other');
  return ['All', ...ordered];
}

function matchesType(iocType: string, filter: string): boolean {
  if (filter === 'All') return true;
  const cat = CATEGORY_MAP[iocType.toLowerCase()];
  if (filter === 'Other') return !cat;
  return cat === filter;
}

function scoreSeverity(score: number): { label: string; color: string; barColor: string } {
  if (score >= 5) return { label: 'High', color: 'text-error', barColor: 'bg-error' };
  if (score >= 1) return { label: 'Medium', color: 'text-warning', barColor: 'bg-warning' };
  return { label: 'Low', color: 'text-on-surface-dim', barColor: 'bg-on-surface-dim' };
}

function timeSince(iso: string): string {
  const ms = Date.now() - new Date(iso).getTime();
  if (isNaN(ms)) return 'Unknown';
  const seconds = Math.floor(ms / 1000);
  if (seconds < 60) return `${seconds}s ago`;
  const minutes = Math.floor(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

export function IocExplorer() {
  const { t } = useTranslation();
  const { data: iocs, isLoading, error, refetch } = useAllIocs();
  const { data: config } = useMetaConfig();
  const typeFilters = useMemo(() => buildTypeFilters(config?.ioc_types ?? []), [config?.ioc_types]);
  const [typeFilter, setTypeFilter] = useState<string>('All');
  const [search, setSearch] = useState('');
  const [selectedIoc, setSelectedIoc] = useState<Ioc | null>(null);

  const filtered = useMemo(() => {
    if (!iocs) return [];
    return iocs.filter((ioc) => {
      if (!matchesType(ioc.type, typeFilter)) return false;
      if (search && !ioc.value.toLowerCase().includes(search.toLowerCase())) return false;
      return true;
    });
  }, [iocs, typeFilter, search]);

  if (isLoading) return <Loading message={t('iocExplorer.loading')} />;
  if (error) return <ErrorMessage message={t('iocExplorer.failedLoad')} onRetry={() => void refetch()} />;

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 bg-accent rounded-full animate-pulse" />
          <span className="text-xs uppercase tracking-widest text-accent/80 font-bold">{t('iocExplorer.realTimeAnalysis')}</span>
        </div>
        <div className="flex items-center justify-between">
          <h1 className="text-2xl font-light text-on-surface tracking-tight">{t('iocExplorer.title')}</h1>
          <div className="relative max-w-md flex-1 ml-8">
            <svg className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-on-surface-dim" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
              <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('iocExplorer.searchPlaceholder')}
              className="w-full bg-surface-low pl-10 pr-4 py-2.5 rounded-lg text-sm text-on-surface placeholder-on-surface-dim focus:outline-none focus:ring-2 focus:ring-accent"
              aria-label="Search IOCs"
            />
          </div>
        </div>
      </header>

      <FilterBar typeFilter={typeFilter} onTypeChange={setTypeFilter} total={filtered.length} typeFilters={typeFilters} />

      <div className="flex gap-6 items-start">
        <div className="flex-1 min-w-0">
          <IocTable iocs={filtered} selectedId={selectedIoc?.obs_id ?? null} onSelect={setSelectedIoc} />
        </div>
        {selectedIoc && (
          <div className="sticky top-0 shrink-0">
            <DetailPanel ioc={selectedIoc} onClose={() => setSelectedIoc(null)} />
          </div>
        )}
      </div>
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

function IocTable({ iocs, selectedId, onSelect }: {
  iocs: Ioc[];
  selectedId: string | null;
  onSelect: (ioc: Ioc) => void;
}) {
  const { t } = useTranslation();

  return (
    <div className="bg-surface-low rounded-lg overflow-hidden">
      <table className="w-full text-left">
        <thead>
          <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
            <th className="px-5 py-3 font-medium">{t('iocExplorer.id')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.type')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.value')}</th>
            <th className="px-5 py-3 font-medium">{t('conversationDetail.category')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.score')}</th>
            <th className="px-5 py-3 font-medium">{t('iocExplorer.lastSeen')}</th>
            <th className="px-5 py-3 font-medium text-center">{t('iocExplorer.inspect')}</th>
          </tr>
        </thead>
        <tbody className="text-sm">
          {iocs.map((ioc) => {
            const sev = scoreSeverity(ioc.score?.agg ?? 0);
            const isSelected = ioc.obs_id === selectedId;
            return (
              <tr
                key={ioc.obs_id}
                onClick={() => onSelect(ioc)}
                onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(ioc); } }}
                tabIndex={0}
                role="button"
                aria-pressed={isSelected}
                className={`transition-colors cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-accent ${
                  isSelected
                    ? 'bg-surface-high border-l-2 border-accent'
                    : 'hover:bg-surface-high/50'
                }`}
              >
                <td className="px-5 py-3 text-on-surface-dim font-mono text-xs">
                  {ioc.obs_id.slice(0, 8)}
                </td>
                <td className="px-5 py-3">
                  <span className="text-xs uppercase text-on-surface-variant">{ioc.type}</span>
                </td>
                <td className="px-5 py-3 font-mono text-on-surface truncate max-w-[200px]">
                  {ioc.value}
                </td>
                <td className="px-5 py-3 text-on-surface-variant text-xs">{ioc.category}</td>
                <td className="px-5 py-3">
                  <div className="flex items-center gap-2">
                    <div className="w-16 h-1.5 bg-surface-highest rounded-full overflow-hidden">
                      <div
                        className={`h-full rounded-full ${sev.barColor}`}
                        style={{ width: `${Math.min(Math.max((ioc.score?.agg ?? 0) * 10, 0), 100)}%` }}
                      />
                    </div>
                    <span className={`text-xs font-bold ${sev.color}`}>
                      {ioc.score?.agg ?? 0}
                    </span>
                  </div>
                </td>
                <td className="px-5 py-3 text-on-surface-dim text-xs">
                  {timeSince(ioc.ts_observed)}
                </td>
                <td className="px-5 py-3 text-center">
                  <button
                    onClick={(e) => { e.stopPropagation(); onSelect(ioc); }}
                    className={`p-1.5 rounded-lg transition-colors ${
                      isSelected
                        ? 'bg-accent-muted/20 text-accent'
                        : 'hover:bg-accent-muted/20 text-on-surface-dim hover:text-accent'
                    }`}
                    aria-label={`Inspect IOC ${ioc.value}`}
                  >
                    <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5} aria-hidden="true">
                      <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7" />
                    </svg>
                  </button>
                </td>
              </tr>
            );
          })}
          {iocs.length === 0 && (
            <tr>
              <td colSpan={7} className="px-5 py-12 text-center text-on-surface-dim">
                {t('iocExplorer.noMatch')}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function DetailPanel({ ioc, onClose }: { ioc: Ioc; onClose: () => void }) {
  const { t } = useTranslation();
  const sev = scoreSeverity(ioc.score?.agg ?? 0);

  return (
    <aside className="w-96 shrink-0 bg-surface-low rounded-lg p-6 flex flex-col gap-5 overflow-y-auto">
      <div className="flex items-center justify-between">
        <h3 className="font-bold text-on-surface text-base tracking-tight">{t('iocExplorer.intelligenceProfile')}</h3>
        <button
          onClick={onClose}
          className="p-1 hover:bg-surface-highest rounded text-on-surface-dim"
          aria-label="Close detail panel"
        >
          <svg className="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2} aria-hidden="true">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div className="p-4 bg-surface-base rounded-lg">
        <label className="text-xs font-bold text-accent-muted uppercase tracking-widest block mb-1">{t('iocExplorer.targetIdentity')}</label>
        <p className="font-mono text-sm font-bold break-all text-on-surface">{ioc.value}</p>
        <div className="mt-2 flex items-center gap-2">
          <span className={`text-xs px-2 py-0.5 rounded font-medium ${sev.color} bg-surface-high`}>
            {sev.label}
          </span>
          <span className="text-xs px-2 py-0.5 bg-surface-high text-on-surface-variant rounded">
            {ioc.type.toUpperCase()}
          </span>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <DetailField label={t('conversationDetail.firstSeen')} value={new Date(ioc.ts_observed).toLocaleDateString('en-GB')} />
        <DetailField label={t('conversationDetail.category')} value={ioc.category} />
        <DetailField label={t('conversationDetail.vtScore')} value={String(ioc.score?.vt ?? 0)} />
        <DetailField label={t('conversationDetail.urlScan')} value={String(ioc.score?.urlscan ?? 0)} />
      </div>

      <div className="space-y-2">
        <h4 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.scoreExplanation')}</h4>
        <p className="text-sm text-on-surface-variant bg-surface-base rounded-lg p-3">
          {ioc.score?.explain ?? t('conversationDetail.noAnalysis')}
        </p>
      </div>

      <div className="space-y-2 flex-1">
        <h4 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{t('iocExplorer.stixContext')}</h4>
        <pre className="flex-1 min-h-[120px] p-3 bg-surface-base rounded-lg font-mono text-xs text-accent/70 overflow-auto">
{JSON.stringify({
  type: 'indicator',
  id: `indicator--${ioc.ioc_id.slice(0, 8)}`,
  pattern: `[${ioc.type}:value = '${ioc.value_norm.replace(/'/g, "\\'")}']`,
  confidence: ioc.score?.agg ?? 0,
  labels: [ioc.category.toLowerCase().replace(/\s+/g, '-')],
}, null, 2)}
        </pre>
      </div>
    </aside>
  );
}

function DetailField({ label, value }: { label: string; value: string }) {
  return (
    <div className="space-y-0.5">
      <label className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block">{label}</label>
      <p className="text-sm font-medium text-on-surface">{value}</p>
    </div>
  );
}

export default IocExplorer;
