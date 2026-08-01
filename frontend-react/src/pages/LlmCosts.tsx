import { useTranslation } from 'react-i18next';
import { useLlmCosts, useKillSwitchState, useToggleKillSwitch } from '@/hooks/useLlmCosts';
import { StatCard } from '@/components/ui/StatCard';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';
import { formatTokenCount, formatPurposeName, formatShortDate } from '@/lib/format';
import type { LlmCostReport, LlmPurposeCost } from '@/types/api';

function BudgetBadge({ report }: { report: LlmCostReport }) {
  const { t } = useTranslation();

  if (report.limit_exceeded) {
    return <span className="px-3 py-1 rounded-full text-xs font-medium bg-error/20 text-error">{t('llmCosts.budgetExceeded')}</span>;
  }
  if (report.current_month.pct_used >= 80) {
    return <span className="px-3 py-1 rounded-full text-xs font-medium bg-warning/20 text-warning">{t('llmCosts.approachingLimit')}</span>;
  }
  return <span className="px-3 py-1 rounded-full text-xs font-medium bg-success/20 text-success">{t('llmCosts.withinBudget')}</span>;
}

function BudgetBar({ report }: { report: LlmCostReport }) {
  const { t } = useTranslation();
  const { total_usd, limit_usd, pct_used } = report.current_month;

  if (limit_usd <= 0) {
    return <p className="text-on-surface-dim text-sm">{t('llmCosts.noLimit')}</p>;
  }

  const barWidth = Math.min(pct_used, 100);
  const barColor = pct_used >= 80 ? 'bg-error' : pct_used >= 60 ? 'bg-warning' : 'bg-success';

  return (
    <div>
      <div className="flex justify-between text-sm text-on-surface-variant mb-2">
        <span>{t('llmCosts.used', { amount: total_usd.toFixed(2) })}</span>
        <span>{t('llmCosts.limit', { amount: limit_usd.toFixed(2) })}</span>
      </div>
      <div className="relative h-4 bg-surface-highest rounded-full overflow-hidden">
        <div className={`h-full rounded-full transition-all ${barColor}`} style={{ width: `${barWidth}%` }} />
        <div className="absolute top-0 h-full border-r-2 border-dashed border-on-surface-dim/40" style={{ left: '80%' }} />
      </div>
      <p className="text-xs text-on-surface-dim mt-1">{t('llmCosts.alertThreshold')}</p>
    </div>
  );
}

function PurposeTable({ perPurpose, totalUsd }: { perPurpose: Record<string, LlmPurposeCost>; totalUsd: number }) {
  const { t } = useTranslation();
  const entries = Object.entries(perPurpose);

  if (entries.length === 0) {
    return <p className="text-on-surface-dim text-sm">{t('llmCosts.noCostData')}</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="text-xs uppercase tracking-wider text-on-surface-dim border-b border-surface-highest">
            <th className="text-left py-3 px-2">{t('llmCosts.purpose')}</th>
            <th className="text-right py-3 px-2">{t('llmCosts.cost')}</th>
            <th className="text-right py-3 px-2">{t('llmCosts.calls')}</th>
            <th className="text-right py-3 px-2">{t('llmCosts.avgPerCall')}</th>
            <th className="text-right py-3 px-2 w-32">{t('llmCosts.share')}</th>
          </tr>
        </thead>
        <tbody>
          {entries.map(([purpose, data]) => {
            const share = totalUsd > 0 ? (data.cost_usd / totalUsd) * 100 : 0;
            const avgPerCall = data.calls > 0 ? data.cost_usd / data.calls : 0;
            return (
              <tr key={purpose} className="border-b border-surface-highest/50">
                <td className="py-3 px-2 text-on-surface">{formatPurposeName(purpose)}</td>
                <td className="py-3 px-2 text-right text-on-surface font-mono">${data.cost_usd.toFixed(4)}</td>
                <td className="py-3 px-2 text-right text-on-surface-variant">{data.calls.toLocaleString()}</td>
                <td className="py-3 px-2 text-right text-on-surface-variant font-mono">${avgPerCall.toFixed(6)}</td>
                <td className="py-3 px-2 text-right">
                  <div className="flex items-center justify-end gap-2">
                    <div className="w-16 h-2 bg-surface-highest rounded-full overflow-hidden">
                      <div className="h-full bg-accent rounded-full" style={{ width: `${Math.min(share, 100)}%` }} />
                    </div>
                    <span className="text-on-surface-variant text-xs w-12 text-right">{share.toFixed(1)}%</span>
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}

function DailyChart({ trend }: { trend: LlmCostReport['daily_trend'] }) {
  const { t } = useTranslation();

  if (trend.length === 0) {
    return <p className="text-on-surface-dim text-sm">{t('llmCosts.noCostData')}</p>;
  }

  const chronological = [...trend].reverse();
  const maxCost = Math.max(...chronological.map(d => d.cost_usd), 0.01);

  return (
    <div className="flex items-end gap-2 h-40">
      {chronological.map((day) => {
        const height = (day.cost_usd / maxCost) * 100;
        return (
          <div key={day.date} className="flex-1 flex flex-col items-center gap-1 group">
            <div className="relative w-full flex justify-center">
              <div
                className="w-full max-w-10 bg-accent/70 hover:bg-accent rounded transition-all cursor-default"
                style={{ height: `${Math.max(height, 2)}%`, minHeight: '2px' }}
                title={`$${day.cost_usd.toFixed(2)} — ${t('llmCosts.calls').toLowerCase()}: ${day.calls}`}
              />
            </div>
            <span className="text-[10px] text-on-surface-dim">{formatShortDate(day.date)}</span>
          </div>
        );
      })}
    </div>
  );
}

// Kill switch banner shown at the top of the LlmCosts page when active.
function KillSwitchBanner() {
  const { t } = useTranslation();
  const { data } = useKillSwitchState();

  if (!data?.active) return null;

  return (
    <div className="rounded-lg border border-error/40 bg-error/10 px-4 py-3 text-sm text-error">
      <strong>{t('llmCosts.killSwitch.activeBanner')}</strong>
    </div>
  );
}

// Toggle button (admin-gated by the backend, returns 403 if not admin).
function KillSwitchToggle() {
  const { t } = useTranslation();
  const { data } = useKillSwitchState();
  const toggle = useToggleKillSwitch();

  const active = data?.active ?? false;

  const handleClick = () => {
    const confirmMsg = active
      ? t('llmCosts.killSwitch.confirmDeactivate')
      : t('llmCosts.killSwitch.confirmActivate');
    if (!window.confirm(confirmMsg)) return;
    toggle.mutate(!active);
  };

  return (
    <button
      onClick={handleClick}
      disabled={toggle.isPending}
      className={`px-3 py-1.5 text-sm rounded transition ${
        active
          ? 'bg-success/20 text-success hover:bg-success/30'
          : 'bg-error/20 text-error hover:bg-error/30'
      } disabled:opacity-50`}
    >
      {active ? t('llmCosts.killSwitch.deactivate') : t('llmCosts.killSwitch.activate')}
    </button>
  );
}

export default function LlmCosts() {
  const { t } = useTranslation();
  const { data: report, isLoading, error, refetch } = useLlmCosts();

  if (isLoading) return <Loading message={t('llmCosts.loading') ?? undefined} />;
  if (error || !report) return <ErrorMessage title={t('common.error')} message={t('llmCosts.failedLoad')} onRetry={() => refetch()} />;

  const { current_month } = report;

  return (
    <div className="p-6 space-y-6">
      <KillSwitchBanner />
      <header className="flex items-center justify-between">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-2xl font-semibold text-on-surface">{t('llmCosts.title')}</h1>
            <BudgetBadge report={report} />
          </div>
          <p className="text-sm text-on-surface-variant mt-1">{t('llmCosts.subtitle')}</p>
        </div>
        <div className="flex items-center gap-2">
          <KillSwitchToggle />
          <button
            onClick={() => refetch()}
            className="px-3 py-1.5 text-sm rounded bg-surface-high text-on-surface-variant hover:text-on-surface transition"
          >
            {t('llmCosts.refresh')}
          </button>
        </div>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label={t('llmCosts.monthlyCost')}
          value={`$${current_month.total_usd.toFixed(2)}`}
          subtitle={current_month.limit_usd > 0 ? t('llmCosts.ofLimit', { pct: current_month.pct_used.toFixed(1), limit: current_month.limit_usd.toFixed(0) }) : t('llmCosts.thisMonth')}
        />
        <StatCard
          label={t('llmCosts.apiCalls')}
          value={current_month.calls_count.toLocaleString()}
          subtitle={t('llmCosts.thisMonth')}
        />
        <StatCard
          label={t('llmCosts.promptTokens')}
          value={formatTokenCount(current_month.total_prompt_tokens)}
          subtitle={t('llmCosts.inputTokens')}
        />
        <StatCard
          label={t('llmCosts.completionTokens')}
          value={formatTokenCount(current_month.total_completion_tokens)}
          subtitle={t('llmCosts.outputTokens')}
        />
      </div>

      <section className="bg-surface-low rounded-lg p-6">
        <h2 className="text-lg font-medium text-on-surface mb-4">{t('llmCosts.budgetUsage')}</h2>
        <BudgetBar report={report} />
      </section>

      <section className="bg-surface-low rounded-lg p-6">
        <h2 className="text-lg font-medium text-on-surface mb-4">{t('llmCosts.costByPurpose')}</h2>
        <PurposeTable perPurpose={report.per_purpose} totalUsd={current_month.total_usd} />
      </section>

      <section className="bg-surface-low rounded-lg p-6">
        <h2 className="text-lg font-medium text-on-surface mb-4">{t('llmCosts.dailyTrend')}</h2>
        <DailyChart trend={report.daily_trend} />
      </section>
    </div>
  );
}
