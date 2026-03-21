import { useTranslation } from 'react-i18next';
import { useAutonomyStats } from '@/hooks/useStats';
import { Loading } from '@/components/feedback/Loading';
import { LanguageSwitcher } from '@/components/ui/LanguageSwitcher';

export function Settings() {
  const { t } = useTranslation();
  const { data: stats, isLoading } = useAutonomyStats();

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('settings.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('settings.subtitle')}</p>
      </header>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Section title="Language">
          <LanguageSwitcher variant="full" />
        </Section>

        <Section title={t('settings.systemStatus')}>
          {isLoading ? <Loading message={t('settings.loadingStatus')} /> : (
            <div className="space-y-3">
              <StatusRow label={t('settings.pipeline')} value={stats?.kill_switch ? t('dashboard.killSwitchActive') : t('settings.operational')} ok={!stats?.kill_switch} />
              <StatusRow label={t('settings.lastCheck')} value={stats?.checked_at ? new Date(stats.checked_at).toLocaleString('en-GB') : '--'} ok />
              <StatusRow label={t('settings.convergence')} value={stats?.convergence.status ?? '--'} ok={stats?.convergence.status === 'converging'} />
              <StatusRow label={t('settings.bestPersona')} value={stats?.convergence.best_persona ?? '--'} ok />
              <StatusRow label={t('personas.explorationRate')} value={`${((stats?.convergence.exploration_rate ?? 0) * 100).toFixed(0)}%`} ok />
            </div>
          )}
        </Section>

        <Section title={t('settings.counters')}>
          {isLoading ? <Loading message={t('settings.loadingCounters')} /> : (
            <div className="space-y-3">
              <CounterRow label={t('settings.totalConversations')} value={stats?.conversations.total ?? 0} />
              <CounterRow label={t('common.active')} value={stats?.conversations.active ?? 0} />
              <CounterRow label={t('common.status.closed')} value={stats?.conversations.closed ?? 0} />
              <CounterRow label={t('common.status.abandoned')} value={stats?.conversations.abandoned ?? 0} />
              <CounterRow label={t('settings.totalMessages')} value={stats?.messages.total ?? 0} />
              <CounterRow label={t('settings.inbound')} value={stats?.messages.inbound ?? 0} />
              <CounterRow label={t('settings.outbound')} value={stats?.messages.outbound ?? 0} />
              <CounterRow label={t('settings.totalIocs')} value={stats?.iocs.total ?? 0} />
              <CounterRow label={t('settings.uniqueIocTypes')} value={stats?.iocs.unique_types ?? 0} />
            </div>
          )}
        </Section>

        <Section title={t('settings.platformInfo')}>
          <div className="space-y-3">
            <InfoRow label={t('settings.version')} value="1.0.0-alpha" />
            <InfoRow label={t('settings.backend')} value="Symfony 7 / PHP 8.3" />
            <InfoRow label={t('settings.frontend')} value="React 18 / TypeScript" />
            <InfoRow label={t('settings.database')} value="PostgreSQL 15" />
            <InfoRow label={t('settings.cache')} value="Redis 7" />
            <InfoRow label={t('settings.llmProvider')} value="OpenAI (gpt-4o-mini)" />
            <InfoRow label={t('settings.orchestration')} value="n8n" />
          </div>
        </Section>

        <Section title={t('settings.agents')}>
          <div className="space-y-3">
            <InfoRow label="Orchestrator" value={t('settings.orchestratorDesc')} />
            <InfoRow label="ScamClassifier" value={t('settings.scamClassifierDesc')} />
            <InfoRow label="IocExtractor" value={t('settings.iocExtractorDesc')} />
            <InfoRow label="Generator" value={t('settings.generatorDesc')} />
            <InfoRow label="PolicyGuard" value={t('settings.policyGuardDesc')} />
            <InfoRow label="LLM Validator" value={t('settings.llmValidatorDesc')} />
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
