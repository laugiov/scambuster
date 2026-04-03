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

function formatDate(iso: string): string {
  return new Date(iso).toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

export function IocTimeline({ observations }: Props) {
  const { t } = useTranslation();

  const data = useMemo(() => {
    return observations
      .map((obs) => ({
        date: new Date(obs.ts_observed).getTime(),
        y: 1,
        scamType: obs.conv_scam_type,
        method: obs.extraction_method,
        convId: obs.conv_id,
        subject: obs.conv_subject,
      }))
      .sort((a, b) => a.date - b.date);
  }, [observations]);

  if (observations.length === 0) {
    return (
      <div className="flex items-center justify-center h-32 text-on-surface-dim text-sm">
        {t('iocDetail.noObservations')}
      </div>
    );
  }

  return (
    <div className="bg-surface-low rounded-lg p-4">
      <h4 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-3">
        {t('iocDetail.observationTimeline')}
      </h4>
      <ResponsiveContainer width="100%" height={120}>
        <ScatterChart margin={{ top: 10, right: 20, bottom: 10, left: 20 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="var(--color-surface-highest, #333)" />
          <XAxis
            dataKey="date"
            type="number"
            domain={['dataMin', 'dataMax']}
            tickFormatter={(ts: number) => formatDate(new Date(ts).toISOString())}
            tick={{ fontSize: 10, fill: 'var(--color-on-surface-dim, #888)' }}
            stroke="var(--color-surface-highest, #333)"
          />
          <YAxis hide domain={[0, 2]} />
          <Tooltip
            content={({ payload }) => {
              if (!payload || payload.length === 0) return null;
              const d = payload[0].payload as (typeof data)[number];

              return (
                <div className="bg-surface-base border border-surface-highest rounded-lg px-3 py-2 text-xs shadow-lg">
                  <p className="font-bold text-on-surface">{formatDate(new Date(d.date).toISOString())}</p>
                  <p className="text-on-surface-dim">{d.scamType} — {d.method}</p>
                  {d.subject && <p className="text-accent truncate max-w-[200px]">{d.subject}</p>}
                </div>
              );
            }}
          />
          <Scatter
            data={data}
            fill="var(--color-accent, #60a5fa)"
            fillOpacity={0.8}
          />
        </ScatterChart>
      </ResponsiveContainer>
    </div>
  );
}
