import { useTranslation } from 'react-i18next';
import {
  BarChart, Bar, XAxis, YAxis, Tooltip, Legend, ResponsiveContainer, CartesianGrid,
} from 'recharts';
import { useTtpPhaseTrend } from '@/hooks/useTtps';
import { PHASE_HEX, PHASE_ORDER, ttpPhaseLabel } from '@/lib/ttpLabels';

const GRID_COLOR = '#31353c';
const AXIS_COLOR = '#6b7280';
const TOOLTIP_BG = '#181c22';
// Distinct from every PHASE_HEX hue (exit is #94a3b8) so an unmapped phase
// series stays visually distinguishable in the stacked bars.
const FALLBACK_HEX = '#64748b';

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

/**
 * Weekly phase-evolution chart: one stacked bar per ISO week over the last 8
 * weeks, one series per kill-chain phase (canonical order and hues). The
 * backend zero-fills weeks and phases and buckets on the message timestamp,
 * so this component only renders — it never re-buckets client-side. Load,
 * failure and all-zero windows degrade to an informative note in the card,
 * never a hard page error.
 */
export function PhaseTrendChart() {
  const { t } = useTranslation();
  const { data, isLoading, isError } = useTtpPhaseTrend();

  const weeks = data?.weeks ?? [];

  // Canonical phases first; an unexpected phase key returned by the backend
  // becomes an extra stacked series instead of being silently dropped.
  const phases: string[] = [...PHASE_ORDER];
  for (const entry of weeks) {
    for (const phase of Object.keys(entry.counts)) {
      if (!phases.includes(phase)) phases.push(phase);
    }
  }

  // Week label = the bucket's ISO Monday date, straight from the API.
  const chartData = weeks.map((entry) => ({ week: entry.week, ...entry.counts }));
  const total = weeks.reduce(
    (sum, entry) => sum + Object.values(entry.counts).reduce((s, n) => s + n, 0),
    0,
  );

  return (
    <div className="bg-surface-low rounded-lg p-5" data-testid="ttp-phase-trend">
      <h3 className="text-sm font-medium text-on-surface mb-4">{t('ttpExplorer.trendTitle')}</h3>
      <div className="h-64">
        {isLoading ? (
          <EmptyChart message={t('ttpExplorer.trendLoading')} />
        ) : isError ? (
          <EmptyChart message={t('ttpExplorer.trendFailed')} />
        ) : total === 0 ? (
          <EmptyChart message={t('ttpExplorer.trendEmpty')} />
        ) : (
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke={GRID_COLOR} />
              <XAxis dataKey="week" tick={{ fill: AXIS_COLOR, fontSize: 10 }} interval={0} />
              <YAxis tick={{ fill: AXIS_COLOR, fontSize: 10 }} allowDecimals={false} />
              <Tooltip content={<CustomTooltip />} cursor={{ fill: 'rgba(255,255,255,0.04)' }} />
              <Legend wrapperStyle={{ fontSize: 11 }} />
              {phases.map((phase) => (
                <Bar
                  key={phase}
                  dataKey={phase}
                  stackId="phases"
                  name={ttpPhaseLabel(phase)}
                  fill={PHASE_HEX[phase] ?? FALLBACK_HEX}
                />
              ))}
            </BarChart>
          </ResponsiveContainer>
        )}
      </div>
    </div>
  );
}
