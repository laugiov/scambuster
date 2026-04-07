import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAutonomyStats } from '@/hooks/useStats';
import { useConversations } from '@/hooks/useConversations';
import { useLlmCosts } from '@/hooks/useLlmCosts';
import { StatCard } from '@/components/ui/StatCard';
import { Badge } from '@/components/ui/Badge';
import { statusToBadgeVariant } from '@/components/ui/badgeUtils';
import { Loading } from '@/components/feedback/Loading';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
// import { useCampaignCandidates } from '@/hooks/useStix'; // hidden: campaigns disconnected
import { useAllPersonaPerformances } from '@/hooks/usePersonas';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { useActivityFeed, useWeeklyTrends, type WeeklyTrend } from '@/hooks/useAnalytics';
import { useAllIocs } from '@/hooks/useIocs';
import { timeSince } from '@/lib/time';

export function Dashboard() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const stats = useAutonomyStats();
  const conversations = useConversations();
  const { data: config } = useMetaConfig();
  // const { data: campaigns } = useCampaignCandidates(); // hidden: campaigns disconnected
  const { data: llmCosts } = useLlmCosts();
  const personaCodes = config?.personas.map((p) => p.code) ?? [];
  const { data: personas } = useAllPersonaPerformances(personaCodes);
  const { data: activityFeed } = useActivityFeed();
  const { data: weeklyTrends } = useWeeklyTrends();
  const { data: allIocs } = useAllIocs();

  if (stats.isLoading) return <Loading message={t('dashboard.loadingDashboard')} />;
  if (stats.error)
    return (
      <ErrorMessage message={t('dashboard.failedLoad')} onRetry={() => void stats.refetch()} />
    );

  const data = stats.data;
  const isKillSwitch = data?.kill_switch_active ?? data?.kill_switch ?? false;
  const activeConversations = conversations.data?.filter((c) => c.status === 'open') ?? [];
  const trendMap = new Map<string, WeeklyTrend>();
  weeklyTrends?.trends.forEach((tr) => trendMap.set(tr.metric, tr));

  const topIocs = (allIocs ?? [])
    .filter((ioc) => (ioc.confidence ?? 0) > 0.5)
    .sort((a, b) => new Date(b.ts_observed).getTime() - new Date(a.ts_observed).getTime())
    .slice(0, 5);

  const bestPersona =
    personas && personas.length > 0
      ? personas.reduce((best, p) => (p.global_avg_reward > best.global_avg_reward ? p : best))
      : null;

  return (
    <div className="space-y-8">
      <header className="flex items-center justify-between">
        <h1 className="text-xl font-semibold text-on-surface">{t('dashboard.title')}</h1>
        <div className="flex items-center gap-3">
          <span
            className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs font-medium ${
              isKillSwitch ? 'bg-error/20 text-error' : 'bg-success/20 text-success'
            }`}
          >
            <span
              className={`w-1.5 h-1.5 rounded-full ${isKillSwitch ? 'bg-error' : 'bg-success'}`}
            />
            {isKillSwitch ? t('dashboard.killSwitchActive') : t('dashboard.pipelineActive')}
          </span>
          <span className="text-xs text-on-surface-dim">
            {t('common.lastSync', {
              time: data?.checked_at ? new Date(data.checked_at).toLocaleTimeString() : '--',
            })}
          </span>
        </div>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label={t('dashboard.iocsExtracted')}
          value={data?.iocs.total ?? 0}
          subtitle={<TrendBadge trend={trendMap.get('iocs')} t={t} />}
        />
        <StatCard
          label={t('dashboard.avgEngagement')}
          value={`${((data?.messages.outbound ?? 0) / Math.max(data?.conversations.total ?? 1, 1)).toFixed(1)}`}
          subtitle={t('dashboard.turns')}
        />
        <StatCard
          label={t('dashboard.bestPersonaScore')}
          value={
            bestPersona?.global_avg_reward.toFixed(2) ??
            data?.convergence.best_score?.toFixed(2) ??
            '--'
          }
          subtitle={
            bestPersona
              ? personaDisplayName(config, bestPersona.persona_code)
              : (data?.convergence.best_persona ?? '--')
          }
          subtitleColor="text-accent"
        />
        <div className="cursor-pointer" onClick={() => navigate('/llm-costs')}>
          <StatCard
            label={t('dashboard.llmCost')}
            value={`$${(llmCosts?.current_month.total_usd ?? 0).toFixed(2)}`}
            subtitle={
              llmCosts && llmCosts.current_month.limit_usd > 0
                ? t('dashboard.ofBudget', {
                    pct: llmCosts.current_month.pct_used.toFixed(0),
                    limit: llmCosts.current_month.limit_usd.toFixed(0),
                  })
                : t('llmCosts.thisMonth')
            }
            subtitleColor={
              (llmCosts?.current_month.pct_used ?? 0) >= 80
                ? 'text-error'
                : (llmCosts?.current_month.pct_used ?? 0) >= 50
                  ? 'text-warning'
                  : 'text-success'
            }
          />
        </div>
      </div>

      {/* Activity Feed + Top IOCs row */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Activity Feed */}
        <div className="bg-surface-low rounded-lg p-6">
          <h2 className="text-base font-medium text-on-surface mb-4">
            {t('dashboard.activityFeed')}
          </h2>
          {activityFeed?.events && activityFeed.events.length > 0 ? (
            <div className="space-y-2.5">
              {activityFeed.events.map((evt, i) => (
                <div key={i} className="flex items-start gap-3 text-xs">
                  <ActivityIcon type={evt.event_type} />
                  <div className="flex-1 min-w-0">
                    <span className="text-on-surface-variant">
                      {t(`dashboard.${camelCase(evt.event_type)}`)}
                    </span>
                    <span className="text-on-surface-dim ml-1.5 font-mono">
                      {evt.ref_id.slice(0, 8)}
                    </span>
                  </div>
                  <span className="text-on-surface-dim shrink-0">{timeSince(evt.ts)}</span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-on-surface-dim py-4 text-center">
              {t('dashboard.noRecentActivity')}
            </p>
          )}
        </div>

        {/* Top IOCs */}
        <div className="bg-surface-low rounded-lg p-6">
          <h2 className="text-base font-medium text-on-surface mb-4">{t('dashboard.topIocs')}</h2>
          {topIocs.length > 0 ? (
            <div className="space-y-2">
              {topIocs.map((ioc) => (
                <div
                  key={ioc.obs_id}
                  className="flex items-center justify-between bg-surface-base rounded px-3 py-2"
                >
                  <div className="min-w-0">
                    <span className="text-[10px] text-on-surface-dim uppercase">{ioc.type}</span>
                    <p className="text-xs text-on-surface font-mono truncate">{ioc.value}</p>
                  </div>
                  <span className="text-xs font-mono text-accent ml-2 shrink-0">
                    {(ioc.confidence ?? 0).toFixed(2)}
                  </span>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-on-surface-dim py-4 text-center">
              {t('dashboard.noTopIocs')}
            </p>
          )}
        </div>

        {/* Pipeline Health Summary */}
        <div className="bg-surface-low rounded-lg p-6">
          <h2 className="text-base font-medium text-on-surface mb-4">
            {t('dashboard.pipelineHealthSummary')}
          </h2>
          <div className="space-y-4">
            <div>
              <span className="text-xs text-on-surface-dim uppercase">
                {t('dashboard.approvalRate')}
              </span>
              <p className="text-2xl font-semibold text-success mt-1">
                {(data?.messages.outbound ?? 0) > 0 ? '~100%' : '--'}
              </p>
            </div>
            <div>
              <span className="text-xs text-on-surface-dim uppercase">
                {t('dashboard.avgDuration')}
              </span>
              <p className="text-2xl font-semibold text-on-surface mt-1">--</p>
              <p className="text-xs text-on-surface-dim">
                {t('dashboard.weeklyTrend')}: <TrendBadge trend={trendMap.get('replies')} t={t} />
              </p>
            </div>
          </div>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 bg-surface-low rounded-lg p-6">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-base font-medium text-on-surface">
              {t('dashboard.activeConversations')}
            </h2>
            <span className="text-xs text-on-surface-dim">
              {t('dashboard.activeCount', {
                count: data?.conversations.open ?? activeConversations.length,
              })}
            </span>
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
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') navigate(`/conversations/${conv.conv_id}`);
                    }}
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
            <h2 className="text-base font-medium text-on-surface">
              {t('dashboard.banditPerformance')}
            </h2>
            <span className="text-xs text-on-surface-dim">{t('dashboard.rewardWeighting')}</span>
          </div>
          <div className="space-y-2">
            {personas && personas.length > 0 ? (
              [...personas]
                .sort((a, b) => b.global_avg_reward - a.global_avg_reward)
                .slice(0, 5)
                .map((p) => (
                  <BanditBar
                    key={p.persona_code}
                    label={personaDisplayName(config, p.persona_code)}
                    value={p.global_avg_reward}
                  />
                ))
            ) : (
              <BanditBar
                label={data?.convergence.best_persona ?? 'N/A'}
                value={data?.convergence.best_score ?? 0}
              />
            )}
            <p className="text-xs text-on-surface-dim mt-3">
              {t('dashboard.explorationRate', {
                rate: ((data?.convergence.exploration_rate ?? 0.15) * 100).toFixed(0),
              })}
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
      <span className="text-xs text-on-surface-variant w-32 shrink-0 truncate" title={label}>
        {label}
      </span>
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
function TrendBadge({ trend, t }: { trend: WeeklyTrend | undefined; t: (key: string) => string }) {
  if (!trend || trend.delta_pct === null) return null;
  const isPositive = trend.delta_pct >= 0;
  const color = isPositive ? 'text-success' : 'text-error';
  const arrow = isPositive ? '\u2191' : '\u2193';
  return (
    <span className={`text-xs font-medium ${color}`}>
      {arrow} {Math.abs(trend.delta_pct).toFixed(0)}% {t('dashboard.weeklyTrend')}
    </span>
  );
}

function ActivityIcon({ type }: { type: string }) {
  const colors: Record<string, string> = {
    conversation_opened: 'text-success',
    reply_sent: 'text-accent',
    ioc_extracted: 'text-warning',
    conversation_closed: 'text-on-surface-dim',
  };
  return <span className={`text-sm ${colors[type] ?? 'text-on-surface-dim'}`}>{'\u25CF'}</span>;
}

function camelCase(str: string): string {
  return str.replace(/_([a-z])/g, (_, c: string) => c.toUpperCase());
}

export default Dashboard;
