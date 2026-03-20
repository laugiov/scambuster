import { useAutonomyStats } from '@/hooks/useStats';
import { useConversations } from '@/hooks/useConversations';
import { StatCard } from '@/components/ui/StatCard';
import { Badge, statusToBadgeVariant } from '@/components/ui/Badge';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

export function Dashboard() {
  const stats = useAutonomyStats();
  const conversations = useConversations();

  if (stats.isLoading) return <Loading message="Loading dashboard..." />;
  if (stats.error) return <ErrorMessage message="Failed to load dashboard data" onRetry={() => void stats.refetch()} />;

  const data = stats.data;
  const activeConversations = conversations.data?.filter((c) => c.status === 'open') ?? [];

  return (
    <div className="space-y-8">
      <header className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-on-surface">ScamBuster — Operations Dashboard</h1>
        <div className="flex items-center gap-3">
          <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium ${
            data?.kill_switch ? 'bg-error/20 text-error' : 'bg-success/20 text-success'
          }`}>
            <span className={`w-1.5 h-1.5 rounded-full ${data?.kill_switch ? 'bg-error' : 'bg-success'}`} />
            {data?.kill_switch ? 'Kill Switch Active' : 'Pipeline Active'}
          </span>
          <span className="text-xs text-on-surface-dim">
            Last sync: {data?.checked_at ? new Date(data.checked_at).toLocaleTimeString() : '--'}
          </span>
        </div>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Active Campaigns"
          value={data?.conversations.active ?? 0}
        />
        <StatCard
          label="IOCs Extracted"
          value={data?.iocs.total ?? 0}
          subtitle={`${data?.iocs.unique_types ?? 0} unique types`}
        />
        <StatCard
          label="Avg. Engagement"
          value={`${((data?.messages.outbound ?? 0) / Math.max(data?.conversations.total ?? 1, 1)).toFixed(1)}`}
          subtitle="turns"
        />
        <StatCard
          label="Best Persona Score"
          value={data?.convergence.best_score?.toFixed(2) ?? '--'}
          subtitle={data?.convergence.best_persona ?? '--'}
          subtitleColor="text-accent"
        />
      </div>

      <div className="grid grid-cols-3 gap-6">
        <div className="col-span-2 bg-surface-low rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-medium text-on-surface">Active Conversations</h2>
            <span className="text-xs text-on-surface-dim">{activeConversations.length} active</span>
          </div>

          {conversations.isLoading ? (
            <Loading message="Loading conversations..." />
          ) : (
            <table className="w-full">
              <thead>
                <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
                  <th className="text-left pb-3 font-medium">Source ID</th>
                  <th className="text-left pb-3 font-medium">Scam Type</th>
                  <th className="text-left pb-3 font-medium">Persona</th>
                  <th className="text-left pb-3 font-medium">Status</th>
                </tr>
              </thead>
              <tbody className="text-sm">
                {activeConversations.slice(0, 6).map((conv) => (
                  <tr key={conv.conv_id} className="hover:bg-surface-high/50 transition-colors">
                    <td className="py-2.5 text-on-surface-variant font-mono text-xs">
                      {conv.conv_id.slice(0, 8)}
                    </td>
                    <td className="py-2.5 text-on-surface-variant">{conv.scam_type ?? '--'}</td>
                    <td className="py-2.5 text-on-surface-variant">{conv.persona ?? '--'}</td>
                    <td className="py-2.5">
                      <Badge label={conv.status} variant={statusToBadgeVariant(conv.status)} />
                    </td>
                  </tr>
                ))}
                {activeConversations.length === 0 && (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-on-surface-dim text-sm">
                      No active conversations
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>

        <div className="bg-surface-low rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-medium text-on-surface">Bandit Performance</h2>
            <span className="text-xs text-on-surface-dim">Reward Weighting</span>
          </div>
          <div className="space-y-3">
            <BanditBar label={data?.convergence.best_persona ?? 'Best'} value={data?.convergence.best_score ?? 0} />
            <p className="text-xs text-on-surface-dim mt-4">
              Exploration rate: {((data?.convergence.exploration_rate ?? 0.15) * 100).toFixed(0)}%
            </p>
            <p className="text-xs text-on-surface-dim">
              Strategy: epsilon-greedy
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}

function BanditBar({ label, value }: { label: string; value: number }) {
  const pct = Math.min(value * 100, 100);
  return (
    <div className="flex items-center gap-3">
      <span className="text-sm text-on-surface-variant w-24 shrink-0">{label}</span>
      <div className="flex-1 bg-surface-high rounded-full h-2 overflow-hidden">
        <div
          className="h-full bg-accent-muted rounded-full transition-all duration-500"
          style={{ width: `${pct}%` }}
        />
      </div>
      <span className="text-sm text-accent font-mono w-10 text-right">{value.toFixed(2)}</span>
    </div>
  );
}
export default Dashboard;
