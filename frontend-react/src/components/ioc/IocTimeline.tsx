import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import {
  ScatterChart,
  Scatter,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';
import type { IocObservation } from '@/types/api';

interface Props {
  observations: IocObservation[];
}

const METHOD_COLORS: Record<string, string> = {
  llm: '#60a5fa',
  regex: '#34d399',
  header: '#94a3b8',
  headers: '#94a3b8',
  extraction: '#a78bfa',
  derived_from_url: '#fbbf24',
  derived_from_email: '#fbbf24',
};

const DEFAULT_DOT_COLOR = '#60a5fa';

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

export function IocTimeline({ observations }: Props) {
  const { t } = useTranslation();

  const data = useMemo(() => {
    const sorted = observations
      .map((obs, idx) => ({
        date: new Date(obs.ts_observed).getTime(),
        y: 1 + (idx % 3) * 0.3,
        scamType: obs.conv_scam_type,
        method: obs.extraction_method,
        convId: obs.conv_id,
        subject: obs.conv_subject,
      }))
      .sort((a, b) => a.date - b.date);

    return sorted;
  }, [observations]);

  if (observations.length === 0) {
    return (
      <div className="flex items-center justify-center h-32 text-on-surface-dim text-sm">
        {t('iocDetail.noObservations')}
      </div>
    );
  }

  // Pad domain so single-day observations don't collapse
  const dates = data.map((d) => d.date);
  const minDate = Math.min(...dates);
  const maxDate = Math.max(...dates);
  const padding = minDate === maxDate ? 86_400_000 : (maxDate - minDate) * 0.05;

  return (
    <div className="bg-surface-low rounded-lg p-4">
      <h4 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-3">
        {t('iocDetail.observationTimeline')}
      </h4>
      <ResponsiveContainer width="100%" height={140}>
        <ScatterChart margin={{ top: 10, right: 20, bottom: 10, left: 20 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#334155" />
          <XAxis
            dataKey="date"
            type="number"
            domain={[minDate - padding, maxDate + padding]}
            tickFormatter={(ts: number) => formatDate(new Date(ts).toISOString())}
            tick={{ fontSize: 10, fill: '#94a3b8' }}
            stroke="#334155"
          />
          <YAxis dataKey="y" hide domain={[0, 2.5]} />
          <Tooltip
            content={({ payload }) => {
              if (!payload || payload.length === 0) return null;
              const d = payload[0].payload as (typeof data)[number];

              return (
                <div className="bg-surface-base border border-surface-highest rounded-lg px-3 py-2 text-xs shadow-lg">
                  <p className="font-bold text-on-surface">
                    {formatDate(new Date(d.date).toISOString())}
                  </p>
                  <p className="text-on-surface-dim">
                    {d.scamType} — {d.method}
                  </p>
                  {d.subject && <p className="text-accent truncate max-w-[200px]">{d.subject}</p>}
                </div>
              );
            }}
          />
          <Scatter
            data={data}
            fill={DEFAULT_DOT_COLOR}
            shape={({
              cx: dotCx,
              cy: dotCy,
              payload,
            }: {
              cx?: number;
              cy?: number;
              payload?: Record<string, unknown>;
            }) => {
              const p = payload as (typeof data)[number] | undefined;
              const color = p ? (METHOD_COLORS[p.method] ?? DEFAULT_DOT_COLOR) : DEFAULT_DOT_COLOR;
              return (
                <circle
                  cx={dotCx ?? 0}
                  cy={dotCy ?? 0}
                  r={8}
                  fill={color}
                  fillOpacity={0.85}
                  stroke={color}
                  strokeWidth={2}
                  strokeOpacity={0.4}
                />
              );
            }}
          />
        </ScatterChart>
      </ResponsiveContainer>
    </div>
  );
}
