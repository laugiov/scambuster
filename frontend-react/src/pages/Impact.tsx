import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  BarChart, Bar, PieChart, Pie, Cell, AreaChart, Area,
  XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid,
} from 'recharts';
import { useImpactSummary, useIocUniqueness } from '@/hooks/useImpact';
import type { IocTypeEntry, TopCampaign } from '@/hooks/useImpact';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

const CHART_COLORS = ['#3b82f6', '#4ade80', '#fbbf24', '#f87171', '#60a5fa', '#a78bfa', '#adc6ff', '#fb923c'];
const GRID_COLOR = '#31353c';
const AXIS_COLOR = '#6b7280';
const TOOLTIP_BG = '#181c22';
const PERIODS = ['7d', '30d', '90d', 'all'] as const;

function ChartCard({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="bg-surface-low rounded-lg p-5">
      <h3 className="text-sm font-medium text-on-surface mb-4">{title}</h3>
      <div className="h-64">
        {children}
      </div>
    </div>
  );
}

function EmptyChart({ message }: { message: string }) {
  return (
    <div className="h-full flex items-center justify-center text-on-surface-dim text-sm">
      {message}
    </div>
  );
}

function CustomTooltip({ active, payload, label }: { active?: boolean; payload?: { name: string; value: number; color: string }[]; label?: string }) {
  if (!active || !payload?.length) return null;
  return (
    <div className="bg-surface-low border border-outline-variant rounded px-3 py-2 text-xs shadow-lg" style={{ backgroundColor: TOOLTIP_BG }}>
      <p className="text-on-surface-dim mb-1">{label}</p>
      {payload.map((entry, i) => (
        <p key={i} style={{ color: entry.color }} className="font-mono">
          {entry.name}: {typeof entry.value === 'number' ? entry.value.toLocaleString() : entry.value}
        </p>
      ))}
    </div>
  );
}

function buildPieData(byType: IocTypeEntry[]): IocTypeEntry[] {
  const sorted = [...byType].sort((a, b) => b.count - a.count);
  if (sorted.length <= 8) return sorted;
  const top = sorted.slice(0, 8);
  const otherCount = sorted.slice(8).reduce((sum, e) => sum + e.count, 0);
  return [...top, { type: 'Other', count: otherCount }];
}

export function Impact() {
  const { t } = useTranslation();
  const [period, setPeriod] = useState<string>('all');

  const { data, isLoading, error, refetch } = useImpactSummary(period);
  const { data: iocData } = useIocUniqueness(period);

  if (isLoading) return <Loading message={t('common.loading')} />;
  if (error) return <ErrorMessage message={t('common.error')} onRetry={() => void refetch()} />;

  if (!data || data.wasted_time.total_conversations === 0) {
    return (
      <div className="space-y-6">
        <header>
          <h1 className="text-xl font-semibold text-on-surface">{t('impact.title')}</h1>
        </header>
        <div className="bg-surface-low rounded-lg p-10 text-center text-on-surface-dim">
          {t('impact.empty_state')}
        </div>
      </div>
    );
  }

  const { wasted_time, ioc_value, cost_efficiency, campaigns } = data;
  const hours = wasted_time.total_hours;
  const pieData = buildPieData(ioc_value.by_type);

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-on-surface">{t('impact.title')}</h1>
        </div>
        <div className="flex items-center gap-1 bg-surface-low rounded-lg p-1">
          {PERIODS.map((p) => (
            <button
              key={p}
              onClick={() => setPeriod(p)}
              className={`px-3 py-1.5 text-xs rounded transition-colors cursor-pointer ${
                period === p ? 'bg-accent-muted text-on-surface font-medium' : 'text-on-surface-variant hover:bg-surface-high'
              }`}
            >
              {p === 'all' ? 'All' : p}
            </button>
          ))}
        </div>
      </header>

      {/* Stat cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label={t('impact.wasted_time')}
          value={`${Math.floor(hours)}h ${Math.round((hours % 1) * 60)}m`}
          subtitle={
            <>
              {t('impact.across_conversations', { count: wasted_time.total_conversations })}
              {data.trends?.wasted_hours_delta_pct != null && (
                <span className={`ml-2 ${data.trends.wasted_hours_delta_pct >= 0 ? 'text-green-400' : 'text-red-400'}`}>
                  {data.trends.wasted_hours_delta_pct >= 0 ? '\u25B2' : '\u25BC'} {Math.abs(data.trends.wasted_hours_delta_pct).toFixed(1)}%
                </span>
              )}
            </>
          }
        />
        <StatCard
          label={t('impact.novel_iocs')}
          value={`${ioc_value.novel_pct}%`}
          subtitle={
            <>
              {`${ioc_value.novel_iocs} ${t('impact.exclusive')}`}
              {data.trends?.novel_pct_delta != null && (
                <span className={`ml-2 ${data.trends.novel_pct_delta >= 0 ? 'text-green-400' : 'text-red-400'}`}>
                  {data.trends.novel_pct_delta >= 0 ? '\u25B2' : '\u25BC'} {Math.abs(data.trends.novel_pct_delta).toFixed(1)}pp
                </span>
              )}
            </>
          }
        />
        <StatCard
          label={t('impact.cost_per_ioc')}
          value={`$${cost_efficiency.cost_per_ioc_usd.toFixed(4)}`}
          subtitle={
            <>
              {`Total: $${cost_efficiency.total_cost_usd.toFixed(2)}`}
              {data.trends?.cost_per_ioc_delta_pct != null && (
                <span className={`ml-2 ${data.trends.cost_per_ioc_delta_pct <= 0 ? 'text-green-400' : 'text-red-400'}`}>
                  {data.trends.cost_per_ioc_delta_pct >= 0 ? '\u25B2' : '\u25BC'} {Math.abs(data.trends.cost_per_ioc_delta_pct).toFixed(1)}%
                </span>
              )}
            </>
          }
        />
        <StatCard
          label={t('impact.campaigns_exposed')}
          value={campaigns.total}
          subtitle={
            <>
              {`${campaigns.promoted} ${t('impact.promoted')}`}
              {data.trends?.campaigns_delta != null && data.trends.campaigns_delta !== 0 && (
                <span className={`ml-2 ${data.trends.campaigns_delta >= 0 ? 'text-green-400' : 'text-red-400'}`}>
                  {data.trends.campaigns_delta >= 0 ? '\u25B2' : '\u25BC'} {Math.abs(data.trends.campaigns_delta)}
                </span>
              )}
            </>
          }
        />
      </div>

      {/* Charts */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Weekly wasted time */}
        <ChartCard title={t('impact.weekly_wasted')}>
          {(wasted_time.weekly_trend?.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={wasted_time.weekly_trend}>
                <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                <XAxis dataKey="week" tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
                <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
                <Tooltip content={<CustomTooltip />} />
                <Bar dataKey="hours" name={t('impact.wasted_time')} fill="#3b82f6" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* IOC by type pie */}
        <ChartCard title={t('impact.ioc_by_type')}>
          {pieData.length > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={pieData}
                  dataKey="count"
                  nameKey="type"
                  cx="50%"
                  cy="50%"
                  innerRadius={50}
                  outerRadius={90}
                  paddingAngle={2}
                  label={({ name, percent }: { name?: string; percent?: number }) => (percent ?? 0) >= 0.02 ? `${name ?? ''} ${((percent ?? 0) * 100).toFixed(0)}%` : ''}
                  labelLine={false}
                  fontSize={10}
                >
                  {pieData.map((_, i) => (
                    <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>
      </div>

      {/* IOC daily trend */}
      {(iocData?.daily_trend?.length ?? 0) > 0 && (
        <ChartCard title={t('impact.ioc_daily_trend', 'IOCs per Day (novel vs known)')}>
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={iocData!.daily_trend.map((d) => ({ date: d.date, novel: d.novel, known: d.total - d.novel }))}>
              <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
              <XAxis dataKey="date" tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
              <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
              <Tooltip content={<CustomTooltip />} />
              <Area type="monotone" dataKey="known" stackId="1" stroke="#6b7280" fill="#6b7280" fillOpacity={0.3} name="Known" />
              <Area type="monotone" dataKey="novel" stackId="1" stroke="#4ade80" fill="#4ade80" fillOpacity={0.4} name="Novel" />
            </AreaChart>
          </ResponsiveContainer>
        </ChartCard>
      )}

      {/* Campaign table */}
      {campaigns.top_campaigns.length > 0 && (
        <div className="bg-surface-low rounded-lg p-5">
          <h3 className="text-sm font-medium text-on-surface mb-4">{t('impact.top_campaigns')}</h3>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-on-surface-dim text-xs uppercase tracking-widest border-b border-outline-variant">
                  <th className="text-left py-2 px-3">Campaign</th>
                  <th className="text-center py-2 px-3">Severity</th>
                  <th className="text-right py-2 px-3">Conversations</th>
                  <th className="text-right py-2 px-3">IOCs</th>
                  <th className="text-left py-2 px-3">First Seen</th>
                  <th className="text-center py-2 px-3">Status</th>
                </tr>
              </thead>
              <tbody>
                {(campaigns.top_campaigns ?? []).map((c: TopCampaign, index: number) => (
                  <tr key={c.campaign_id} className="border-b border-outline-variant/50 hover:bg-surface-high transition-colors">
                    <td className="py-2 px-3 text-on-surface text-sm">
                      Campaign #{index + 1} — {c.dominant_scam_type ?? 'Unknown'}
                      <span className="block text-xs text-on-surface-dim font-mono">{c.campaign_id?.slice(0, 8)}</span>
                    </td>
                    <td className="py-2 px-3 text-center text-on-surface">{c.severity}</td>
                    <td className="py-2 px-3 text-right text-on-surface">{c.conv_count}</td>
                    <td className="py-2 px-3 text-right text-on-surface">{c.ioc_count}</td>
                    <td className="py-2 px-3 text-on-surface-dim text-xs">{c.first_seen?.slice(0, 10) ?? '-'}</td>
                    <td className="py-2 px-3 text-center">
                      {c.status === 'promoted' ? (
                        <span className="text-xs bg-green-900/30 text-green-400 px-2 py-0.5 rounded">{t('impact.promoted')}</span>
                      ) : (
                        <span className="text-xs bg-surface-high text-on-surface-dim px-2 py-0.5 rounded">{c.status}</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Comparison banner */}
      <div className="bg-surface-low rounded-lg px-5 py-3 text-xs text-on-surface-dim flex items-center gap-2">
        <svg className="w-4 h-4 text-accent shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
          <path strokeLinecap="round" strokeLinejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        {cost_efficiency.cost_per_ioc_usd > 0
          ? `$${cost_efficiency.cost_per_ioc_usd.toFixed(4)}/IOC — ~${Math.round(10000 / (cost_efficiency.cost_per_ioc_usd * Math.max(ioc_value.total_iocs, 1))).toLocaleString()}x cheaper than commercial CTI feeds ($10,000/yr)`
          : t('impact.comparison')
        }
      </div>
    </div>
  );
}

export default Impact;
