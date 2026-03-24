import { useState, useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { useAllPersonaPerformances } from '@/hooks/usePersonas';
import { useAutonomyStats } from '@/hooks/useStats';
import { useConvergenceHistory } from '@/hooks/useConvergenceHistory';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { useMetaConfig, personaDisplayName } from '@/hooks/useMetaConfig';
import type { PersonaSummary, MetaConfig } from '@/types/api';

export function Personas() {
  const { t } = useTranslation();
  const { data: config } = useMetaConfig();
  const personaCodes = config?.personas.map((p) => p.code) ?? [];
  const { data: personas, isLoading, error, refetch } = useAllPersonaPerformances(personaCodes);
  const { data: stats } = useAutonomyStats();
  const [selectedCode, setSelectedCode] = useState<string | null>(null);

  if (isLoading) return <Loading message={t('personas.loading')} />;
  if (error) return <ErrorMessage message={t('personas.failedLoad')} onRetry={() => void refetch()} />;

  const safePersonas = personas ?? [];
  const activeCount = safePersonas.length;
  const epsilon = stats?.convergence.exploration_rate ?? 0.15;
  const totalSessions = safePersonas.reduce((sum, p) => sum + p.total_sessions, 0);
  const bestPersona = safePersonas.length > 0
    ? safePersonas.reduce((best, p) => p.global_avg_reward > best.global_avg_reward ? p : best)
    : null;

  const selectedPersona = safePersonas.find((p) => p.persona_code === selectedCode) ?? null;

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-xl font-semibold text-on-surface">{t('personas.title')}</h1>
        <p className="text-xs text-on-surface-dim mt-1">{t('personas.subtitle')}</p>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard label={t('personas.activePersonas')} value={activeCount} />
        <StatCard label={t('personas.explorationRate')} value={epsilon.toFixed(2)} subtitle={t('personas.epsilon')} />
        <StatCard label={t('personas.totalSessions')} value={totalSessions} />
        <StatCard
          label={t('personas.convergenceRate')}
          value={bestPersona?.global_avg_reward.toFixed(2) ?? '--'}
          subtitle={bestPersona ? personaDisplayName(config, bestPersona.persona_code) : '--'}
          subtitleColor="text-accent"
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 space-y-6">
          <PerformanceMatrix
            personas={safePersonas}
            selectedCode={selectedCode}
            onSelect={setSelectedCode}
            config={config}
          />
          {selectedPersona && <PersonaDetail persona={selectedPersona} config={config} />}
        </div>

        <BanditSettings epsilon={epsilon} config={config} />
      </div>

      <ConvergenceHistory />
    </div>
  );
}

function PerformanceMatrix({ personas, selectedCode, onSelect, config }: {
  personas: PersonaSummary[];
  selectedCode: string | null;
  onSelect: (code: string) => void;
  config: MetaConfig | undefined;
}) {
  const { t } = useTranslation();
  const handleKeyDown = useCallback((code: string, e: React.KeyboardEvent) => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      onSelect(code);
    }
  }, [onSelect]);

  return (
    <div className="bg-surface-low rounded-lg p-6">
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-base font-medium text-on-surface">{t('personas.performanceMatrix')}</h2>
        <span className="text-xs text-on-surface-dim">{t('personas.liveFeed')}</span>
      </div>

      <table className="w-full">
        <thead>
          <tr className="text-xs text-on-surface-dim uppercase tracking-widest">
            <th className="text-left pb-3 font-medium">{t('conversations.persona')}</th>
            <th className="text-left pb-3 font-medium">{t('personas.pulls')}</th>
            <th className="text-left pb-3 font-medium">{t('personas.avgReward')}</th>
            <th className="text-left pb-3 font-medium">{t('personas.best')}</th>
            <th className="text-left pb-3 font-medium">{t('common.status.open')}</th>
          </tr>
        </thead>
        <tbody className="text-sm">
          {personas.map((p) => {
            const isSelected = p.persona_code === selectedCode;
            return (
              <tr
                key={p.persona_code}
                onClick={() => onSelect(p.persona_code)}
                onKeyDown={(e) => handleKeyDown(p.persona_code, e)}
                tabIndex={0}
                role="button"
                aria-pressed={isSelected}
                className={`transition-colors cursor-pointer outline-none focus-visible:ring-2 focus-visible:ring-accent ${
                  isSelected ? 'bg-surface-high' : 'hover:bg-surface-high/50'
                }`}
              >
                <td className="py-3 font-medium text-on-surface">{personaDisplayName(config, p.persona_code)}</td>
                <td className="py-3 text-on-surface-variant font-mono text-xs">{p.total_sessions}</td>
                <td className="py-3">
                  <span className={`font-mono text-xs font-bold ${
                    p.global_avg_reward >= 0.7 ? 'text-success' :
                    p.global_avg_reward >= 0.4 ? 'text-accent' : 'text-on-surface-variant'
                  }`}>
                    {p.global_avg_reward.toFixed(2)}
                  </span>
                </td>
                <td className="py-3 text-on-surface-variant font-mono text-xs">
                  {p.performance_by_scam_type.length > 0
                    ? Math.max(...p.performance_by_scam_type.map((s) => s.best_reward ?? s.reward_avg ?? 0)).toFixed(2)
                    : '--'}
                </td>
                <td className="py-3">
                  <span className={`text-xs px-2 py-0.5 rounded font-medium ${
                    p.total_sessions > 0 ? 'bg-success/20 text-success' : 'bg-surface-highest text-on-surface-dim'
                  }`}>
                    {p.total_sessions > 0 ? t('common.active') : t('common.status.coldStart')}
                  </span>
                </td>
              </tr>
            );
          })}
          {personas.length === 0 && (
            <tr>
              <td colSpan={5} className="py-8 text-center text-on-surface-dim">
                {t('personas.noPersonaData')}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

function PersonaDetail({ persona, config }: { persona: PersonaSummary; config: MetaConfig | undefined }) {
  const { t } = useTranslation();

  return (
    <div className="bg-surface-low rounded-lg p-6">
      <h2 className="text-base font-medium text-accent mb-4">
        {t('personas.personaDetail', { name: personaDisplayName(config, persona.persona_code) })}
      </h2>
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <InfoField label={t('personas.code')} value={persona.persona_code} />
        <InfoField label={t('personas.totalSessions')} value={String(persona.total_sessions)} />
        <InfoField label={t('personas.avgReward')} value={persona.global_avg_reward.toFixed(4)} />
      </div>
      {persona.performance_by_scam_type.length > 0 ? (
        <div className="mt-4">
          <span className="text-xs text-on-surface-dim uppercase tracking-widest font-medium block mb-2">
            {t('personas.performanceByScamType')}
          </span>
          <div className="space-y-2">
            {persona.performance_by_scam_type.map((st) => (
              <div key={st.scam_type_code} className="flex items-center justify-between bg-surface-base rounded p-2">
                <span className="text-xs text-on-surface-variant">{st.scam_type_code}</span>
                <div className="flex items-center gap-4">
                  <span className="text-xs text-on-surface-dim">{t('personas.pullsCount', { count: st.sessions_count ?? st.total_pulls ?? 0 })}</span>
                  <span className="text-xs font-mono font-bold text-accent">{(st.reward_avg ?? st.avg_reward ?? 0).toFixed(2)}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      ) : (
        <p className="mt-4 text-sm text-on-surface-dim bg-surface-base rounded p-3">
          {t('personas.noPerformanceData')}
        </p>
      )}
    </div>
  );
}

function BanditSettings({ epsilon, config }: { epsilon: number; config: MetaConfig | undefined }) {
  const { t } = useTranslation();
  const bandit = config?.bandit;
  const strategy = bandit?.strategy ?? 'epsilon-greedy';
  const effectiveEpsilon = bandit?.epsilon ?? epsilon;
  const coldStart = bandit?.cold_start_threshold ?? 3;

  return (
    <div className="bg-surface-low rounded-lg p-6 space-y-6">
      <h2 className="text-base font-medium text-on-surface">{t('personas.banditStrategySettings')}</h2>

      <div className="space-y-4">
        <InfoField label={t('dashboard.strategy', { name: '' }).replace(': ', '')} value={strategy} />

        <div>
          <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-2">{t('personas.epsilon')}</span>
          <div className="bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface font-mono">
            {effectiveEpsilon.toFixed(2)}
          </div>
          <p className="text-xs text-on-surface-dim mt-1">
            {t('personas.exploit', { pct: ((1 - effectiveEpsilon) * 100).toFixed(0) })} / {t('personas.explore', { pct: (effectiveEpsilon * 100).toFixed(0) })}
          </p>
        </div>

        <div>
          <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-2">{t('personas.decaySchedule')}</span>
          <div className="flex items-center justify-between bg-surface-base rounded px-3 py-2.5">
            <span className="text-sm text-on-surface">{t('common.enabled')}</span>
            <span className="w-8 h-4 bg-accent-muted rounded-full relative" role="img" aria-label="Decay schedule enabled">
              <span className="absolute right-0.5 top-0.5 w-3 h-3 bg-on-surface rounded-full" />
            </span>
          </div>
        </div>

        <InfoField label={t('personas.minPullsBeforeExploit')} value={String(bandit?.min_sessions_for_convergence ?? 50)} />

        <div>
          <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block mb-2">{t('personas.resetOnNewCampaign')}</span>
          <div className="bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface-dim italic">
            {t('personas.coldRestart', { threshold: coldStart })}
          </div>
        </div>
      </div>

      <div className="space-y-3 pt-2">
        <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest block">{t('personas.rewardFunction')}</span>
        <div className="grid grid-cols-2 gap-2">
          {bandit?.reward_weights ? (
            Object.entries(bandit.reward_weights).map(([key, val]) => (
              <RewardWeight key={key} label={key.replace(/_/g, ' ')} value={val.toFixed(2)} />
            ))
          ) : (
            <p className="text-xs text-on-surface-dim col-span-2">{t('personas.loadingWeights')}</p>
          )}
        </div>
      </div>
    </div>
  );
}

function RewardWeight({ label, value }: { label: string; value: string }) {
  return (
    <div className="bg-surface-base rounded p-2 flex items-center justify-between">
      <span className="text-xs text-on-surface-variant">{label}</span>
      <span className="text-xs font-mono font-bold text-accent">{value}</span>
    </div>
  );
}

function InfoField({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <span className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">{label}</span>
      <div className="bg-surface-base rounded px-3 py-2.5 text-sm text-on-surface mt-1">{value}</div>
    </div>
  );
}

function ConvergenceHistory() {
  const { t } = useTranslation();
  const { data, isLoading } = useConvergenceHistory();

  if (isLoading) return null;

  const entries = data?.by_scam_type ?? {};
  const hasData = Object.values(entries).some((v) => v.length > 0);

  if (!hasData) {
    return (
      <div className="bg-surface-low rounded-lg p-6">
        <h2 className="text-base font-medium text-on-surface mb-2">{t('personas.convergenceHistory')}</h2>
        <p className="text-sm text-on-surface-dim">{t('personas.noConvergenceData')}</p>
      </div>
    );
  }

  return (
    <div className="bg-surface-low rounded-lg p-6">
      <h2 className="text-base font-medium text-on-surface mb-4">{t('personas.convergenceHistory')}</h2>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-outline-variant text-left text-xs text-on-surface-dim uppercase tracking-widest">
              <th className="pb-2 font-medium">{t('personas.date')}</th>
              <th className="pb-2 font-medium">{t('monitoring.scamType')}</th>
              <th className="pb-2 font-medium">{t('personas.dominantPersona')}</th>
              <th className="pb-2 font-medium">{t('personas.dominance')}</th>
              <th className="pb-2 font-medium">{t('personas.sessions')}</th>
              <th className="pb-2 font-medium">{t('common.status.converged')}</th>
            </tr>
          </thead>
          <tbody>
            {Object.entries(entries).flatMap(([scamType, logs]) =>
              logs.map((log, i) => (
                <tr key={`${scamType}-${i}`} className="border-b border-outline-variant/50">
                  <td className="py-2 text-on-surface-variant">{log.date}</td>
                  <td className="py-2">
                    <span className="px-2 py-0.5 rounded-full text-xs bg-surface-high text-on-surface">{scamType}</span>
                  </td>
                  <td className="py-2 text-on-surface">{log.dominant_persona}</td>
                  <td className="py-2 font-mono text-on-surface">{(log.dominant_pct * 100).toFixed(1)}%</td>
                  <td className="py-2 font-mono text-on-surface-variant">{log.sessions_count}</td>
                  <td className="py-2">
                    {log.converged
                      ? <span className="text-success text-xs font-medium">CONVERGED</span>
                      : <span className="text-on-surface-dim text-xs">exploring</span>}
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

export default Personas;
