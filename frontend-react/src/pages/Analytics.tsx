import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  AreaChart, Area, BarChart, Bar, LineChart, Line, PieChart, Pie, Cell,
  XAxis, YAxis, Tooltip, ResponsiveContainer, CartesianGrid, Legend,
} from 'recharts';
import {
  useIocTimeline, useConversationTimeline, useIocDistribution,
  useScamDistribution, useCostTimeline, usePipelineTimeline,
} from '@/hooks/useAnalytics';
import { useConvergenceHistory } from '@/hooks/useConvergenceHistory';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

const CHART_COLORS = ['#3b82f6', '#4ade80', '#fbbf24', '#f87171', '#60a5fa', '#a78bfa', '#adc6ff', '#fb923c'];
const GRID_COLOR = '#31353c';
const AXIS_COLOR = '#6b7280';
const TOOLTIP_BG = '#181c22';
const PERIODS = [7, 30, 90] as const;

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

export function Analytics() {
  const { t } = useTranslation();
  const [days, setDays] = useState<number>(30);

  const iocTimeline = useIocTimeline(days);
  const convTimeline = useConversationTimeline(days);
  const iocDist = useIocDistribution();
  const scamDist = useScamDistribution();
  const costTimeline = useCostTimeline(days);
  const pipelineTimeline = usePipelineTimeline(days);
  const convergence = useConvergenceHistory();

  const isLoading = iocTimeline.isLoading || convTimeline.isLoading;
  const error = iocTimeline.error || convTimeline.error;

  if (isLoading) return <Loading message={t('analytics.loading')} />;
  if (error) return <ErrorMessage message={t('analytics.failedLoad')} onRetry={() => void iocTimeline.refetch()} />;

  const convergenceSparklines = buildConvergenceSparklines(convergence.data?.by_scam_type ?? {});

  return (
    <div className="space-y-6">
      <header className="flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-on-surface">{t('analytics.title')}</h1>
          <p className="text-xs text-on-surface-dim mt-1">{t('analytics.subtitle')}</p>
        </div>
        <div className="flex items-center gap-1 bg-surface-low rounded-lg p-1">
          {PERIODS.map((p) => (
            <button
              key={p}
              onClick={() => setDays(p)}
              className={`px-3 py-1.5 text-xs rounded transition-colors cursor-pointer ${
                days === p ? 'bg-accent-muted text-on-surface font-medium' : 'text-on-surface-variant hover:bg-surface-high'
              }`}
            >
              {t(`analytics.${p}d`)}
            </button>
          ))}
        </div>
      </header>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* 1. IOC Extraction Timeline */}
        <ChartCard title={t('analytics.iocTimeline')}>
          {(iocTimeline.data?.data.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={iocTimeline.data?.data}>
                <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                <XAxis dataKey="date" tick={{ fill: AXIS_COLOR, fontSize: 10 }} tickFormatter={formatDate} />
                <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
                <Tooltip content={<CustomTooltip />} />
                <Area type="monotone" dataKey="count" name={t('analytics.count')} stroke="#3b82f6" fill="#3b82f6" fillOpacity={0.3} />
              </AreaChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* 2. Conversation Volume */}
        <ChartCard title={t('analytics.conversationVolume')}>
          {(convTimeline.data?.data.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={convTimeline.data?.data}>
                <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                <XAxis dataKey="date" tick={{ fill: AXIS_COLOR, fontSize: 10 }} tickFormatter={formatDate} />
                <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
                <Tooltip content={<CustomTooltip />} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Bar dataKey="closed" name={t('analytics.closed')} fill="#f87171" stackId="conv" />
                <Bar dataKey="opened" name={t('analytics.opened')} fill="#4ade80" stackId="conv" />
              </BarChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* 3. IOC Type Distribution */}
        <ChartCard title={t('analytics.iocDistribution')}>
          {(iocDist.data?.data.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie
                  data={iocDist.data?.data}
                  dataKey="count"
                  nameKey="label"
                  cx="50%"
                  cy="50%"
                  innerRadius={50}
                  outerRadius={90}
                  paddingAngle={2}
                  label={({ name, percent }: { name?: string; percent?: number }) => (percent ?? 0) >= 0.02 ? `${name ?? ''} ${((percent ?? 0) * 100).toFixed(0)}%` : ''}
                  labelLine={false}
                  fontSize={10}
                >
                  {iocDist.data?.data.map((_, i) => (
                    <Cell key={i} fill={CHART_COLORS[i % CHART_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* 4. Scam Type Distribution */}
        <ChartCard title={t('analytics.scamDistribution')}>
          {(scamDist.data?.data.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={scamDist.data?.data} layout="vertical" margin={{ left: 80 }}>
                <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                <XAxis type="number" tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
                <YAxis type="category" dataKey="label" tick={{ fill: AXIS_COLOR, fontSize: 10 }} width={80} />
                <Tooltip content={<CustomTooltip />} />
                <Bar dataKey="count" name={t('analytics.count')} fill="#adc6ff" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* 5. LLM Cost Trend */}
        <ChartCard title={t('analytics.costTrend')}>
          {(costTimeline.data?.data.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={costTimeline.data?.data}>
                <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                <XAxis dataKey="date" tick={{ fill: AXIS_COLOR, fontSize: 10 }} tickFormatter={formatDate} />
                <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} tickFormatter={(v: number) => `$${v.toFixed(3)}`} />
                <Tooltip content={<CustomTooltip />} />
                <Line type="monotone" dataKey="cost_usd" name={t('analytics.costUsd')} stroke="#fbbf24" strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* 6. Persona Convergence Sparklines */}
        <ChartCard title={t('analytics.convergenceSparklines')}>
          {convergenceSparklines.length > 0 ? (
            <div className="grid grid-cols-2 gap-3 h-full overflow-auto">
              {convergenceSparklines.map((spark) => (
                <div key={spark.scamType} className="bg-surface-base rounded p-2">
                  <span className="text-[10px] text-on-surface-dim block mb-1">{spark.scamType}</span>
                  <ResponsiveContainer width="100%" height={40}>
                    <LineChart data={spark.data}>
                      <Line type="monotone" dataKey="pct" stroke="#4ade80" strokeWidth={1.5} dot={false} />
                    </LineChart>
                  </ResponsiveContainer>
                </div>
              ))}
            </div>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>

        {/* 7. Reply Pipeline Health */}
        <ChartCard title={t('analytics.pipelineHealth')}>
          {(pipelineTimeline.data?.data.length ?? 0) > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={pipelineTimeline.data?.data}>
                <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
                <XAxis dataKey="date" tick={{ fill: AXIS_COLOR, fontSize: 10 }} tickFormatter={formatDate} />
                <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} />
                <Tooltip content={<CustomTooltip />} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Area type="monotone" dataKey="approved" name={t('analytics.approved')} stroke="#4ade80" fill="#4ade80" fillOpacity={0.3} stackId="pipe" />
                <Area type="monotone" dataKey="fallback" name={t('analytics.fallback')} stroke="#fbbf24" fill="#fbbf24" fillOpacity={0.3} stackId="pipe" />
                <Area type="monotone" dataKey="rejected" name={t('analytics.rejected')} stroke="#f87171" fill="#f87171" fillOpacity={0.3} stackId="pipe" />
              </AreaChart>
            </ResponsiveContainer>
          ) : <EmptyChart message={t('analytics.noData')} />}
        </ChartCard>
      </div>
    </div>
  );
}

function formatDate(dateStr: string): string {
  const d = new Date(dateStr);
  return `${d.getMonth() + 1}/${d.getDate()}`;
}

interface SparklineData {
  scamType: string;
  data: { date: string; pct: number }[];
}

function buildConvergenceSparklines(byScamType: Record<string, { date: string; dominant_pct: number }[]>): SparklineData[] {
  return Object.entries(byScamType)
    .filter(([, logs]) => logs.length > 1)
    .map(([scamType, logs]) => ({
      scamType,
      data: logs.map((l) => ({ date: l.date, pct: l.dominant_pct * 100 })),
    }));
}

export default Analytics;
