import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAutonomyStats } from '@/hooks/useStats';
import { useConversations } from '@/hooks/useConversations';
import { StatCard } from '@/components/ui/StatCard';
import { Badge } from '@/components/ui/Badge';
import { statusToBadgeVariant } from '@/components/ui/badgeUtils';
import { Loading } from '@/components/feedback/Loading';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
import { useCampaignCandidates } from '@/hooks/useStix';
import { useAllPersonaPerformances } from '@/hooks/usePersonas';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

export function Dashboard() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const stats = useAutonomyStats();
  const conversations = useConversations();
  const { data: config } = useMetaConfig();
  const { data: campaigns } = useCampaignCandidates();
  const personaCodes = config?.personas.map((p) => p.code) ?? [];
  const { data: personas } = useAllPersonaPerformances(personaCodes);

  if (stats.isLoading) return <Loading message={t('dashboard.loadingDashboard')} />;
  if (stats.error) return <ErrorMessage message={t('dashboard.failedLoad')} onRetry={() => void stats.refetch()} />;

  const data = stats.data;
  const isKillSwitch = data?.kill_switch_active ?? data?.kill_switch ?? false;
  const activeConversations = conversations.data?.filter((c) => c.status === 'open') ?? [];
  const bestPersona = personas && personas.length > 0
    ? personas.reduce((best, p) => p.global_avg_reward > best.global_avg_reward ? p : best)
    : null;

  return (
    <div className="space-y-8">
      <header className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-on-surface">{t('dashboard.title')}</h1>
        <div className="flex items-center gap-3">
          <span className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium ${
            isKillSwitch ? 'bg-error/20 text-error' : 'bg-success/20 text-success'
          }`}>
            <span className={`w-1.5 h-1.5 rounded-full ${isKillSwitch ? 'bg-error' : 'bg-success'}`} />
            {isKillSwitch ? t('dashboard.killSwitchActive') : t('dashboard.pipelineActive')}
          </span>
          <span className="text-xs text-on-surface-dim">
            {t('common.lastSync', { time: data?.checked_at ? new Date(data.checked_at).toLocaleTimeString() : '--' })}
          </span>
        </div>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label={t('dashboard.activeCampaigns')}
          value={campaigns?.length ?? 0}
        />
        <StatCard
          label={t('dashboard.iocsExtracted')}
          value={data?.iocs.total ?? 0}
          subtitle={t('dashboard.uniqueTypes', { count: data?.iocs.unique_indicators ?? data?.iocs.unique_types ?? 0 })}
        />
        <StatCard
          label={t('dashboard.avgEngagement')}
          value={`${((data?.messages.outbound ?? 0) / Math.max(data?.conversations.total ?? 1, 1)).toFixed(1)}`}
          subtitle={t('dashboard.turns')}
        />
        <StatCard
          label={t('dashboard.bestPersonaScore')}
          value={bestPersona?.global_avg_reward.toFixed(2) ?? data?.convergence.best_score?.toFixed(2) ?? '--'}
          subtitle={bestPersona ? personaDisplayName(config, bestPersona.persona_code) : data?.convergence.best_persona ?? '--'}
          subtitleColor="text-accent"
        />
      </div>

      <div className="grid grid-cols-3 gap-6">
        <div className="col-span-2 bg-surface-low rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-medium text-on-surface">{t('dashboard.activeConversations')}</h2>
            <span className="text-xs text-on-surface-dim">{t('dashboard.activeCount', { count: activeConversations.length })}</span>
          </div>

          {conversations.isLoading ? (
            <Loading message={t('dashboard.loadingConversations')} />
          ) : (
            <table className="w-full">
              <thead>
                <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
                  <th className="text-left pb-3 font-medium">{t('conversations.sourceId')}</th>
                  <th className="text-left pb-3 font-medium">{t('conversations.scamType')}</th>
                  <th className="text-left pb-3 font-medium">{t('conversations.persona')}</th>
                  <th className="text-left pb-3 font-medium">{t('common.status.open')}</th>
                </tr>
              </thead>
              <tbody className="text-sm">
                {activeConversations.slice(0, 6).map((conv) => (
                  <tr
                    key={conv.conv_id}
                    onClick={() => navigate(`/conversations/${conv.conv_id}`)}
                    className="hover:bg-surface-high/50 transition-colors cursor-pointer"
                    role="link"
                    tabIndex={0}
                    onKeyDown={(e) => { if (e.key === 'Enter') navigate(`/conversations/${conv.conv_id}`); }}
                  >
                    <td className="py-2.5 text-accent font-mono text-xs">
                      {conv.conv_id.slice(0, 8)}
                    </td>
                    <td className="py-2.5 text-on-surface-variant">{conv.scam_type ?? '--'}</td>
                    <td className="py-2.5 text-on-surface-variant">
                      {conv.persona ? personaDisplayName(config, conv.persona) : '--'}
                    </td>
                    <td className="py-2.5">
                      <Badge label={conv.status} variant={statusToBadgeVariant(conv.status)} />
                    </td>
                  </tr>
                ))}
                {activeConversations.length === 0 && (
                  <tr>
                    <td colSpan={4} className="py-8 text-center text-on-surface-dim text-sm">
                      {t('dashboard.noActiveConversations')}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          )}
        </div>

        <div className="bg-surface-low rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-medium text-on-surface">{t('dashboard.banditPerformance')}</h2>
            <span className="text-xs text-on-surface-dim">{t('dashboard.rewardWeighting')}</span>
          </div>
          <div className="space-y-3">
            <BanditBar label={data?.convergence.best_persona ?? 'Best'} value={data?.convergence.best_score ?? 0} />
            <p className="text-xs text-on-surface-dim mt-4">
              {t('dashboard.explorationRate', { rate: ((data?.convergence.exploration_rate ?? 0.15) * 100).toFixed(0) })}
            </p>
            <p className="text-xs text-on-surface-dim">
              {t('dashboard.strategy', { name: 'epsilon-greedy' })}
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
