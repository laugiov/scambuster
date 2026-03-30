import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useConvergenceHistory } from '@/hooks/useConvergenceHistory';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { Pagination } from '@/components/ui/Pagination';

const PAGE_SIZE = 25;

export function ConvergenceHistory() {
  const { t } = useTranslation();
  const { data, isLoading, error, refetch } = useConvergenceHistory();
  const [page, setPage] = useState(1);

  if (isLoading) return <Loading message={t('convergence.loading')} />;
  if (error) return <ErrorMessage message={t('convergence.failedLoad')} onRetry={() => void refetch()} />;

  const entries = data?.by_scam_type ?? {};

  // Flatten and sort by date descending, then scam type
  const rows = Object.entries(entries)
    .flatMap(([scamType, logs]) =>
      logs.map((log) => ({ ...log, scam_type: scamType })),
    )
    .sort((a, b) => b.date.localeCompare(a.date) || a.scam_type.localeCompare(b.scam_type));

  const paged = rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  const convergedCount = rows.filter((r) => r.converged).length;
  const exploringCount = rows.length - convergedCount;

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-on-surface">{t('convergence.title')}</h1>
          <p className="text-xs text-on-surface-dim mt-1">{t('convergence.subtitle')}</p>
        </div>
        <div className="flex items-center gap-4 text-xs text-on-surface-dim">
          <span>{t('convergence.totalEntries', { count: rows.length })}</span>
          <span className="text-success">{t('convergence.converged', { count: convergedCount })}</span>
          <span>{t('convergence.exploring', { count: exploringCount })}</span>
        </div>
      </header>

      <Pagination page={page} pageSize={PAGE_SIZE} totalItems={rows.length} onPageChange={setPage} />

      <div className="bg-surface-low rounded-lg overflow-hidden">
        <table className="w-full">
          <thead>
            <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="text-left px-5 py-3 font-medium">{t('convergence.date')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('convergence.scamType')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('convergence.dominantPersona')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('convergence.dominance')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('convergence.sessions')}</th>
              <th className="text-left px-5 py-3 font-medium">{t('convergence.status')}</th>
            </tr>
          </thead>
          <tbody className="text-sm">
            {paged.map((row, i) => (
              <tr key={`${row.scam_type}-${row.date}-${i}`} className="hover:bg-surface-high/50 transition-colors">
                <td className="px-5 py-3 text-on-surface-variant">{row.date}</td>
                <td className="px-5 py-3">
                  <span className="px-2 py-0.5 rounded-full text-xs bg-surface-high text-on-surface">{row.scam_type}</span>
                </td>
                <td className="px-5 py-3 text-on-surface">{row.dominant_persona}</td>
                <td className="px-5 py-3 font-mono text-on-surface">{(row.dominant_pct * 100).toFixed(1)}%</td>
                <td className="px-5 py-3 font-mono text-on-surface-variant">{row.sessions_count}</td>
                <td className="px-5 py-3">
                  {row.converged
                    ? <span className="text-success text-xs font-medium">CONVERGED</span>
                    : <span className="text-on-surface-dim text-xs">exploring</span>}
                </td>
              </tr>
            ))}
            {paged.length === 0 && (
              <tr>
                <td colSpan={6} className="px-5 py-12 text-center text-on-surface-dim">
                  {t('convergence.noData')}
                </td>
              </tr>
            )}
          </tbody>
        </table>
        <Pagination page={page} pageSize={PAGE_SIZE} totalItems={rows.length} onPageChange={setPage} />
      </div>
    </div>
  );
}

export default ConvergenceHistory;
