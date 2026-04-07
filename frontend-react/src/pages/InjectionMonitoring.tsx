import { useState, useEffect, useCallback } from 'react';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import { StatCard } from '@/components/ui/StatCard';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

interface InjectionAlert {
  msg_id: string;
  conv_id: string;
  ts_msg: string;
  risk_score: number;
  risk_level: string;
  patterns: string | string[] | null;
}

interface InjectionStats {
  period_days: number;
  total_inbound: number;
  analyzed: number;
  coverage_pct: number;
  high_risk: number;
  medium_risk: number;
  low_risk: number;
  recent_alerts: InjectionAlert[];
}

const PERIOD_OPTIONS = [
  { label: '24h', days: 1 },
  { label: '7d', days: 7 },
  { label: '30d', days: 30 },
];

function RiskBadge({ score }: { score: number }) {
  if (score > 50) return <Badge label={`${score} HIGH`} variant="closed" />;
  if (score > 20) return <Badge label={`${score} MEDIUM`} variant="waiting" />;
  return <Badge label={`${score} LOW`} variant="engaging" />;
}

export default function InjectionMonitoring() {
  const [period, setPeriod] = useState(PERIOD_OPTIONS[1]);
  const [stats, setStats] = useState<InjectionStats | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    try {
      const res = await client.get(ENDPOINTS.monitoring.injection, {
        params: { days: period.days },
      });
      setStats(res.data);
      setError(null);
    } catch {
      setError('Failed to load injection data');
    } finally {
      setIsLoading(false);
    }
  }, [period]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  if (isLoading) return <Loading />;
  if (error) return <ErrorMessage message={error} />;
  if (!stats) return null;

  const parsePatterns = (patterns: string | string[] | null): string[] => {
    if (!patterns) return [];
    if (Array.isArray(patterns)) return patterns;
    try {
      return JSON.parse(patterns);
    } catch {
      return [patterns];
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-on-surface">Injection Monitor</h1>
        <div className="flex gap-1 bg-surface-high rounded-lg p-1">
          {PERIOD_OPTIONS.map((opt) => (
            <button
              key={opt.label}
              onClick={() => {
                setPeriod(opt);
                setIsLoading(true);
              }}
              className={`px-3 py-1 rounded text-sm ${period.label === opt.label ? 'bg-primary text-on-primary' : 'text-on-surface-dim hover:bg-surface-highest'}`}
            >
              {opt.label}
            </button>
          ))}
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Analyzed"
          value={String(stats.analyzed)}
          subtitle={`${stats.coverage_pct}% coverage`}
        />
        <StatCard
          label="High Risk"
          value={String(stats.high_risk)}
          subtitle={stats.high_risk > 0 ? 'Action needed' : 'No threats'}
          subtitleColor={stats.high_risk > 0 ? 'text-error' : 'text-success'}
        />
        <StatCard label="Medium Risk" value={String(stats.medium_risk)} />
        <StatCard label="Low Risk" value={String(stats.low_risk)} subtitle="Benign" />
      </div>

      {/* Coverage bar */}
      <div className="bg-surface-high rounded-lg p-4">
        <div className="flex justify-between text-sm text-on-surface-variant mb-2">
          <span>
            Coverage: {stats.analyzed} / {stats.total_inbound} messages
          </span>
          <span>{stats.coverage_pct}%</span>
        </div>
        <div className="h-3 bg-surface-highest rounded-full overflow-hidden">
          <div
            className={`h-full rounded-full transition-all ${stats.coverage_pct >= 95 ? 'bg-success' : stats.coverage_pct >= 50 ? 'bg-warning' : 'bg-error'}`}
            style={{ width: `${Math.min(stats.coverage_pct, 100)}%` }}
          />
        </div>
      </div>

      {/* Alerts */}
      {stats.recent_alerts.length === 0 ? (
        <div className="bg-success/10 border border-success/30 rounded-lg p-6 text-center">
          <p className="text-success font-semibold">No injection threats detected</p>
          <p className="text-on-surface-dim text-sm mt-1">
            Last {period.label} — {stats.analyzed} messages analyzed
          </p>
        </div>
      ) : (
        <div className="bg-surface-high rounded-lg overflow-hidden">
          <div className="px-4 py-3 border-b border-surface-highest">
            <h2 className="text-lg font-semibold text-on-surface">Recent Alerts</h2>
          </div>
          <div className="divide-y divide-surface-highest">
            {stats.recent_alerts.map((alert) => (
              <div key={alert.msg_id} className="px-4 py-3">
                <div className="flex items-center gap-4">
                  <span className="font-mono text-xs text-on-surface-dim w-20 truncate">
                    {(alert.conv_id || '').substring(0, 8)}
                  </span>
                  <RiskBadge score={alert.risk_score} />
                  <div className="flex-1">
                    {parsePatterns(alert.patterns).map((p, i) => (
                      <span
                        key={i}
                        className="inline-block text-xs bg-error/10 text-error px-2 py-0.5 rounded mr-1 mb-1"
                      >
                        {p}
                      </span>
                    ))}
                  </div>
                  <span className="text-xs text-on-surface-dim">
                    {new Date(alert.ts_msg).toLocaleString()}
                  </span>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
