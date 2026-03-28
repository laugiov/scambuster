import { useState, useEffect, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import client from '@/api/client';
import { ENDPOINTS } from '@/api/endpoints';
import { StatCard } from '@/components/ui/StatCard';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

interface ComponentTraceData {
  name: string;
  status: string;
  duration_ms: number;
  cost?: number;
  output?: Record<string, unknown>;
  error?: string;
  skip_reason?: string;
}

interface TraceSummary {
  msg_id?: string;
  conversation_id: string;
  persona: string;
  scam_type: string;
  total_duration_ms: number;
  total_cost: number;
  attempts: number;
  approved: boolean;
  fallback_used: boolean;
  component_count: number;
  has_alerts: boolean;
  created_at?: string;
}

interface TraceDetail extends TraceSummary {
  components: ComponentTraceData[];
  detected_language: string;
}

interface HealthData {
  period_hours: number;
  total_replies: number;
  avg_duration_ms: number;
  avg_cost: number;
  approval_rate: number;
  fallback_rate: number;
  components: Record<string, { success_rate: number; skip_rate: number; error_rate: number; avg_duration_ms: number }>;
  alerts: string[];
  cost_today: number;
  cost_yesterday: number;
}

const PERIOD_OPTIONS = [
  { label: '24h', days: 1, hours: 24 },
  { label: '7d', days: 7, hours: 168 },
  { label: '30d', days: 30, hours: 720 },
];

const COMPONENT_COLORS: Record<string, string> = {
  language_detector: 'bg-blue-500',
  context_analyzer: 'bg-green-500',
  conversation_analyzer: 'bg-purple-500',
  reciprocity_manager: 'bg-yellow-500',
  prompt_builder: 'bg-orange-500',
  policy_guard: 'bg-red-400',
  reply_validator: 'bg-pink-500',
  ioc_scorer: 'bg-teal-500',
};

function StatusBadge({ trace }: { trace: TraceSummary }) {
  if (trace.fallback_used) return <Badge label="Fallback" variant="closed" />;
  if (trace.has_alerts) return <Badge label="Alert" variant="waiting" />;
  if (trace.approved) return <Badge label="OK" variant="engaging" />;
  return <Badge label="Failed" variant="closed" />;
}

function ComponentWaterfall({ components, totalDuration }: { components: ComponentTraceData[]; totalDuration: number }) {
  if (totalDuration === 0) return null;

  return (
    <div className="mt-3 space-y-1">
      {components.map((c, i) => {
        const pct = totalDuration > 0 ? Math.max((c.duration_ms / totalDuration) * 100, 1) : 0;
        const color = COMPONENT_COLORS[c.name] || 'bg-gray-500';
        const statusIcon = c.status === 'ran' ? '✓' : c.status === 'skipped' ? '⊘' : '✗';

        return (
          <div key={i} className="flex items-center gap-2 text-xs">
            <span className="w-40 text-on-surface-dim truncate">{c.name}</span>
            <span className="w-6 text-center">{statusIcon}</span>
            <div className="flex-1 h-4 bg-surface-highest rounded overflow-hidden">
              {c.status === 'ran' && (
                <div className={`h-full ${color} rounded`} style={{ width: `${pct}%` }} />
              )}
            </div>
            <span className="w-20 text-right text-on-surface-dim">
              {c.status === 'skipped' ? c.skip_reason || 'skipped' : `${c.duration_ms.toFixed(0)}ms`}
            </span>
            {c.cost != null && <span className="w-16 text-right text-on-surface-dim">${c.cost.toFixed(4)}</span>}
          </div>
        );
      })}
    </div>
  );
}

export default function PipelineMonitor() {
  useTranslation();
  const [period, setPeriod] = useState(PERIOD_OPTIONS[0]);
  const [traces, setTraces] = useState<TraceSummary[]>([]);
  const [health, setHealth] = useState<HealthData | null>(null);
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [expandedDetail, setExpandedDetail] = useState<TraceDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(true);

  const fetchData = useCallback(async () => {
    try {
      const [tracesRes, healthRes] = await Promise.all([
        client.get(ENDPOINTS.monitoring.pipelineTraces, { params: { days: period.days, limit: 50 } }),
        client.get(ENDPOINTS.monitoring.pipelineHealth, { params: { hours: period.hours } }),
      ]);
      setTraces(tracesRes.data.traces || []);
      setHealth(healthRes.data);
      setError(null);
    } catch {
      setError('Failed to load pipeline data');
    } finally {
      setIsLoading(false);
    }
  }, [period]);

  useEffect(() => {
    fetchData();
  }, [fetchData]);

  useEffect(() => {
    if (!autoRefresh) return;
    const interval = setInterval(fetchData, 30000);
    return () => clearInterval(interval);
  }, [autoRefresh, fetchData]);

  const handleExpand = async (msgId: string) => {
    if (expandedId === msgId) {
      setExpandedId(null);
      setExpandedDetail(null);
      return;
    }
    setExpandedId(msgId);
    try {
      const res = await client.get(ENDPOINTS.monitoring.pipelineTraceDetail(msgId));
      setExpandedDetail(res.data);
    } catch {
      setExpandedDetail(null);
    }
  };

  if (isLoading) return <Loading />;
  if (error) return <ErrorMessage message={error} />;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-on-surface">Pipeline Monitor</h1>
        <div className="flex items-center gap-3">
          <div className="flex gap-1 bg-surface-high rounded-lg p-1">
            {PERIOD_OPTIONS.map((opt) => (
              <button
                key={opt.label}
                onClick={() => { setPeriod(opt); setIsLoading(true); }}
                className={`px-3 py-1 rounded text-sm ${period.label === opt.label ? 'bg-primary text-on-primary' : 'text-on-surface-dim hover:bg-surface-highest'}`}
              >
                {opt.label}
              </button>
            ))}
          </div>
          <label className="flex items-center gap-2 text-sm text-on-surface-dim">
            <input type="checkbox" checked={autoRefresh} onChange={(e) => setAutoRefresh(e.target.checked)} className="rounded" />
            Auto-refresh
          </label>
        </div>
      </div>

      {/* Stats */}
      {health && (
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          <StatCard label="Replies" value={String(health.total_replies)} subtitle={`Last ${period.label}`} />
          <StatCard label="Avg Cost" value={`$${health.avg_cost.toFixed(4)}`} subtitle={`Today: $${health.cost_today.toFixed(2)}`} />
          <StatCard label="Avg Duration" value={`${(health.avg_duration_ms / 1000).toFixed(1)}s`} />
          <StatCard label="Approval Rate" value={`${(health.approval_rate * 100).toFixed(0)}%`} subtitle={`Fallback: ${(health.fallback_rate * 100).toFixed(0)}%`} />
        </div>
      )}

      {/* Alerts */}
      {health && health.alerts.length > 0 && (
        <div className="bg-error/10 border border-error/30 rounded-lg p-4">
          <h3 className="text-sm font-semibold text-error mb-2">Alerts</h3>
          {health.alerts.map((alert, i) => (
            <p key={i} className="text-sm text-error/80">{alert}</p>
          ))}
        </div>
      )}

      {/* Trace List */}
      <div className="bg-surface-high rounded-lg overflow-hidden">
        <div className="px-4 py-3 border-b border-surface-highest">
          <h2 className="text-lg font-semibold text-on-surface">Recent Executions</h2>
        </div>

        {traces.length === 0 ? (
          <p className="p-8 text-center text-on-surface-dim">No pipeline traces found for this period.</p>
        ) : (
          <div className="divide-y divide-surface-highest">
            {traces.map((trace) => (
              <div key={trace.msg_id || trace.conversation_id} className="px-4 py-3">
                <div
                  className="flex items-center gap-4 cursor-pointer hover:bg-surface-highest/50 -mx-2 px-2 py-1 rounded"
                  onClick={() => trace.msg_id && handleExpand(trace.msg_id)}
                >
                  <span className="font-mono text-xs text-on-surface-dim w-20 truncate">{(trace.conversation_id || '').substring(0, 8)}</span>
                  <Badge label={trace.persona} variant="default" />
                  <Badge label={trace.scam_type} variant="default" />
                  <span className="text-sm text-on-surface-dim">{(trace.total_duration_ms / 1000).toFixed(1)}s</span>
                  <span className="text-sm text-on-surface-dim">${trace.total_cost.toFixed(4)}</span>
                  <span className="text-xs text-on-surface-dim">×{trace.attempts}</span>
                  <StatusBadge trace={trace} />
                  <span className="text-xs text-on-surface-dim ml-auto">{trace.created_at ? new Date(trace.created_at).toLocaleTimeString() : ''}</span>
                </div>

                {expandedId === trace.msg_id && expandedDetail && (
                  <ComponentWaterfall components={expandedDetail.components} totalDuration={expandedDetail.total_duration_ms} />
                )}
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Component Health Table */}
      {health && Object.keys(health.components).length > 0 && (
        <div className="bg-surface-high rounded-lg overflow-hidden">
          <div className="px-4 py-3 border-b border-surface-highest">
            <h2 className="text-lg font-semibold text-on-surface">Component Health</h2>
          </div>
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-on-surface-dim border-b border-surface-highest">
                <th className="px-4 py-2">Component</th>
                <th className="px-4 py-2">Success</th>
                <th className="px-4 py-2">Skip</th>
                <th className="px-4 py-2">Error</th>
                <th className="px-4 py-2">Avg Duration</th>
              </tr>
            </thead>
            <tbody>
              {Object.entries(health.components).map(([name, stats]) => (
                <tr key={name} className={`border-b border-surface-highest ${stats.success_rate < 0.95 ? 'bg-error/5' : ''}`}>
                  <td className="px-4 py-2 font-mono text-on-surface">{name}</td>
                  <td className="px-4 py-2 text-on-surface-dim">{(stats.success_rate * 100).toFixed(0)}%</td>
                  <td className="px-4 py-2 text-on-surface-dim">{(stats.skip_rate * 100).toFixed(0)}%</td>
                  <td className="px-4 py-2 text-on-surface-dim">{(stats.error_rate * 100).toFixed(0)}%</td>
                  <td className="px-4 py-2 text-on-surface-dim">{stats.avg_duration_ms.toFixed(0)}ms</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
