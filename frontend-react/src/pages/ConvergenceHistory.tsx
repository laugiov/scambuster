import { useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useConvergenceHistory } from '@/hooks/useConvergenceHistory';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { Pagination } from '@/components/ui/Pagination';
import { scamTypeLabel, scamTypeColor } from '@/lib/scamTypeLabels';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
import { LineChart, Line, XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, ReferenceLine } from 'recharts';

const PAGE_SIZE = 25;

export function ConvergenceHistory() {
  const { t } = useTranslation();
  const { data, isLoading, error, refetch } = useConvergenceHistory();
  const { data: config } = useMetaConfig();
  const [page, setPage] = useState(1);
  const [chartScamType, setChartScamType] = useState<string>('');

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
          <span className={convergedCount > 0 ? 'text-success' : 'text-on-surface-dim'}>{t('convergence.converged', { count: convergedCount })}</span>
          <span>{t('convergence.exploring', { count: exploringCount })}</span>
        </div>
      </header>

      {/* Dominance Evolution Chart */}
      <DominanceChart entries={entries} config={config} selectedScamType={chartScamType} onScamTypeChange={setChartScamType} />

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
              {convergedCount > 0 && <th className="text-left px-5 py-3 font-medium">{t('convergence.status')}</th>}
            </tr>
          </thead>
          <tbody className="text-sm">
            {paged.map((row, i) => (
              <tr key={`${row.scam_type}-${row.date}-${i}`} className="hover:bg-surface-high/50 transition-colors">
                <td className="px-5 py-3 text-on-surface-variant">{row.date}</td>
                <td className="px-5 py-3">
                  <span className={`px-2 py-0.5 rounded text-xs font-medium ${scamTypeColor(row.scam_type)}`}>{scamTypeLabel(row.scam_type)}</span>
                </td>
                <td className="px-5 py-3 text-on-surface">{personaDisplayName(config, row.dominant_persona)}</td>
                <td className="px-5 py-3 font-mono text-on-surface">{(row.dominant_pct * 100).toFixed(1)}%</td>
                <td className="px-5 py-3 font-mono text-on-surface-variant">{row.sessions_count}</td>
                {convergedCount > 0 && (
                  <td className="px-5 py-3">
                    {row.converged
                      ? <span className="text-success text-xs font-medium">CONVERGED</span>
                      : <span className="text-on-surface-dim text-xs">exploring</span>}
                  </td>
                )}
              </tr>
            ))}
            {paged.length === 0 && (
              <tr>
                <td colSpan={convergedCount > 0 ? 6 : 5} className="px-5 py-12 text-center text-on-surface-dim">
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

const CHART_COLORS = ['#60a5fa', '#34d399', '#fbbf24', '#f87171', '#a78bfa', '#fb923c', '#94a3b8', '#e879f9'];

function DominanceChart({ entries, config, selectedScamType, onScamTypeChange }: {
  entries: Record<string, import('@/hooks/useConvergenceHistory').ConvergenceEntry[]>;
  config: import('@/types/api').MetaConfig | undefined;
  selectedScamType: string;
  onScamTypeChange: (v: string) => void;
}) {
  const scamTypes = Object.keys(entries);
  const activeType = selectedScamType || scamTypes[0] || '';
  const logsRaw = entries[activeType];
  const logs = logsRaw ?? [];

  // Build chart data: { date, [persona1]: pct, [persona2]: pct, ... }
  const chartData = useMemo(() => {
    const sorted = [...logs].sort((a, b) => a.date.localeCompare(b.date));
    return sorted.map((entry) => ({
      date: entry.date,
      [entry.dominant_persona]: Math.round(entry.dominant_pct * 100),
    }));
  }, [logs]);

  // Get unique personas for this scam type
  const personas = useMemo(() => [...new Set(logs.map((l) => l.dominant_persona))], [logs]);

  // Spec 104 P2 — convergence threshold (% dominance) read from bandit
  // config, falls back to 50% if the field is absent. The exact number
  // matters less than the visible reference line: it tells the viewer
  // "here is where the bandit declares it has chosen, you can see
  // whether the trajectory crossed it".
  const convergenceThresholdPct = Math.round((config?.bandit?.convergence_threshold ?? 0.5) * 100);

  // Find the FIRST date where any persona's dominance crossed the
  // threshold. If none, the chart shows the line + a banner saying
  // we're still exploring.
  const { firstCrossingDate, latestSessions } = useMemo(() => {
    if (logs.length === 0) return { firstCrossingDate: null as string | null, latestSessions: 0 };
    const sortedLogs = [...logs].sort((a, b) => a.date.localeCompare(b.date));
    const crossed = sortedLogs.find((l) => l.dominant_pct * 100 >= convergenceThresholdPct);

    return {
      firstCrossingDate: crossed?.date ?? null,
      latestSessions: sortedLogs[sortedLogs.length - 1]?.sessions_count ?? 0,
    };
  }, [logs, convergenceThresholdPct]);

  if (scamTypes.length === 0) return null;

  return (
    <div className="bg-surface-low rounded-lg p-5 space-y-4">
      <div className="flex items-center justify-between">
        <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">Dominance Evolution</h3>
        <select
          value={activeType}
          onChange={(e) => onScamTypeChange(e.target.value)}
          className="text-xs bg-surface-base text-on-surface rounded px-2 py-1 border-none cursor-pointer"
          style={{ colorScheme: 'dark' }}
        >
          {scamTypes.map((st) => (
            <option key={st} value={st} className="bg-neutral-800 text-neutral-200">{scamTypeLabel(st)}</option>
          ))}
        </select>
      </div>

      {/* Spec 104 P2 — convergence state banner above the chart.
          When no scam type has crossed the threshold yet, this is the
          honest "still exploring" state — better than implying
          convergence the data doesn't support. */}
      {chartData.length >= 1 && (firstCrossingDate ? (
        <p
          className="text-[11px] text-emerald-300 font-mono"
          data-testid="convergence-state-converged"
        >
          ✓ Converged on {firstCrossingDate} (dominance ≥ {convergenceThresholdPct}%, {latestSessions} sessions)
        </p>
      ) : (
        <p
          className="text-[11px] text-amber-300 font-mono"
          data-testid="convergence-state-exploring"
        >
          ⧖ Still exploring — {latestSessions} sessions so far, no persona has reached the {convergenceThresholdPct}% dominance threshold for {scamTypeLabel(activeType)}
        </p>
      ))}

      {chartData.length < 2 ? (
        <p className="text-sm text-on-surface-dim text-center py-8">Not enough data points to show evolution for {scamTypeLabel(activeType)}.</p>
      ) : (
        <ResponsiveContainer width="100%" height={250}>
          <LineChart data={chartData}>
            <CartesianGrid strokeDasharray="3 3" stroke="rgba(255,255,255,0.05)" />
            <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#9ca3af' }} />
            <YAxis domain={[0, 100]} tick={{ fontSize: 10, fill: '#9ca3af' }} unit="%" />
            <Tooltip
              contentStyle={{ backgroundColor: '#1e1e2e', border: 'none', borderRadius: 8, fontSize: 12 }}
              labelStyle={{ color: '#9ca3af' }}
            />
            {/* Spec 104 P2 — horizontal reference line at the convergence
                threshold so the viewer can recompose "crossed = chosen"
                without reading any text. */}
            <ReferenceLine
              y={convergenceThresholdPct}
              stroke="#fbbf24"
              strokeDasharray="4 4"
              strokeOpacity={0.6}
              label={{ value: `${convergenceThresholdPct}% threshold`, position: 'right', fill: '#fbbf24', fontSize: 10 }}
            />
            {personas.map((persona, i) => (
              <Line
                key={persona}
                type="monotone"
                dataKey={persona}
                name={personaDisplayName(config, persona)}
                stroke={CHART_COLORS[i % CHART_COLORS.length]}
                strokeWidth={2}
                dot={{ r: 3 }}
                connectNulls
              />
            ))}
          </LineChart>
        </ResponsiveContainer>
      )}
    </div>
  );
}

export default ConvergenceHistory;
