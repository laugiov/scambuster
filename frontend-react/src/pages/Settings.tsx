import { useAutonomyStats } from '@/hooks/useStats';
import { Loading } from '@/components/feedback/Loading';

export function Settings() {
  const { data: stats, isLoading } = useAutonomyStats();

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">Settings</h1>
        <p className="text-xs text-on-surface-dim mt-1">System configuration and monitoring</p>
      </header>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title="System Status">
          {isLoading ? <Loading message="Loading status..." /> : (
            <div className="space-y-3">
              <StatusRow label="Pipeline" value={stats?.kill_switch ? 'Kill Switch Active' : 'Operational'} ok={!stats?.kill_switch} />
              <StatusRow label="Last Check" value={stats?.checked_at ? new Date(stats.checked_at).toLocaleString('en-GB') : '--'} ok />
              <StatusRow label="Convergence" value={stats?.convergence.status ?? '--'} ok={stats?.convergence.status === 'converging'} />
              <StatusRow label="Best Persona" value={stats?.convergence.best_persona ?? '--'} ok />
              <StatusRow label="Exploration Rate" value={`${((stats?.convergence.exploration_rate ?? 0) * 100).toFixed(0)}%`} ok />
            </div>
          )}
        </Section>

        <Section title="Counters">
          {isLoading ? <Loading message="Loading counters..." /> : (
            <div className="space-y-3">
              <CounterRow label="Total Conversations" value={stats?.conversations.total ?? 0} />
              <CounterRow label="Active" value={stats?.conversations.active ?? 0} />
              <CounterRow label="Closed" value={stats?.conversations.closed ?? 0} />
              <CounterRow label="Abandoned" value={stats?.conversations.abandoned ?? 0} />
              <CounterRow label="Total Messages" value={stats?.messages.total ?? 0} />
              <CounterRow label="Inbound" value={stats?.messages.inbound ?? 0} />
              <CounterRow label="Outbound" value={stats?.messages.outbound ?? 0} />
              <CounterRow label="Total IOCs" value={stats?.iocs.total ?? 0} />
              <CounterRow label="Unique IOC Types" value={stats?.iocs.unique_types ?? 0} />
            </div>
          )}
        </Section>

        <Section title="Platform Info">
          <div className="space-y-3">
            <InfoRow label="Version" value="1.0.0-alpha" />
            <InfoRow label="Backend" value="Symfony 7 / PHP 8.3" />
            <InfoRow label="Frontend" value="React 18 / TypeScript" />
            <InfoRow label="Database" value="PostgreSQL 15" />
            <InfoRow label="Cache" value="Redis 7" />
            <InfoRow label="LLM Provider" value="OpenAI (gpt-4o-mini)" />
            <InfoRow label="Orchestration" value="n8n" />
          </div>
        </Section>

        <Section title="Agents">
          <div className="space-y-3">
            <InfoRow label="Orchestrator" value="Thread lifecycle management" />
            <InfoRow label="ScamClassifier" value="Scam type detection (13 types)" />
            <InfoRow label="IocExtractor" value="IOC extraction (34 types)" />
            <InfoRow label="Generator" value="Reply generation with persona" />
            <InfoRow label="PolicyGuard" value="Hard rules validation" />
            <InfoRow label="LLM Validator" value="Tone + safety check" />
          </div>
        </Section>
      </div>
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div className="bg-surface-low rounded-lg p-6">
      <h2 className="text-base font-medium text-on-surface mb-4">{title}</h2>
      {children}
    </div>
  );
}

function StatusRow({ label, value, ok }: { label: string; value: string; ok?: boolean }) {
  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-sm text-on-surface-variant">{label}</span>
      <div className="flex items-center gap-2">
        <span className={`w-2 h-2 rounded-full ${ok ? 'bg-success' : 'bg-error'}`} />
        <span className="text-sm text-on-surface font-medium">{value}</span>
      </div>
    </div>
  );
}

function CounterRow({ label, value }: { label: string; value: number }) {
  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-sm text-on-surface-variant">{label}</span>
      <span className="text-sm text-on-surface font-mono font-medium">{value.toLocaleString()}</span>
    </div>
  );
}

function InfoRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between py-1.5">
      <span className="text-sm text-on-surface-variant">{label}</span>
      <span className="text-sm text-on-surface">{value}</span>
    </div>
  );
}

export default Settings;
