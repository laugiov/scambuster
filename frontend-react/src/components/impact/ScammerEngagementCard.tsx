import { useTranslation } from 'react-i18next';
import { useScammerEngagement } from '@/hooks/useImpact';
import { scamTypeLabel } from '@/lib/scamTypeLabels';

interface ScammerEngagementCardProps {
  /**
   * Page-level scam_type filter. Null = All scam types.
   * When set, the card shows the rate for that single type and the
   * breakdown panel is hidden (filter is already global).
   */
  scamType?: string | null;
  /**
   * Page-level period filter. One of '7d' / '30d' /
   * '90d' / 'all'. Restricts the metric window to conversations with
   * `ts_last >= NOW() - period`.
   */
  period?: string;
}

/**
 * Scammer engagement (real rate) card.
 *
 * Driven by the page-level scam_type + period filters in Impact.tsx.
 * Renders a single primary metric (global rate or filtered rate) with
 * counterpart count, a methodology tooltip, and — when no filter is
 * applied — an inline breakdown by scam_type.
 */
export function ScammerEngagementCard({ scamType, period = 'all' }: ScammerEngagementCardProps) {
  const { t } = useTranslation();
  const { data, isLoading, error } = useScammerEngagement(96, scamType ?? null, period);

  if (isLoading) {
    return (
      <div className="bg-surface-low rounded-lg p-5">
        <p className="text-xs text-on-surface-dim uppercase tracking-widest mb-2">
          {t('impact.scammer_engagement')}
        </p>
        <p className="text-3xl font-light text-on-surface">…</p>
        <p className="text-xs text-on-surface-dim mt-1">{t('impact.scammer_engagement_loading')}</p>
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="bg-surface-low rounded-lg p-5">
        <p className="text-xs text-on-surface-dim uppercase tracking-widest mb-2">
          {t('impact.scammer_engagement')}
        </p>
        <p className="text-3xl font-light text-on-surface">—</p>
        <p className="text-xs text-red-400 mt-1">{t('impact.scammer_engagement_error')}</p>
      </div>
    );
  }

  const { global, by_scam_type } = data;
  const ratePct = global.rate_pct.toFixed(1);
  const subtitle = `${global.responded}/${global.observable} ${t('impact.scammer_engagement_senders')}`;

  return (
    <div className="bg-surface-low rounded-lg p-5">
      <div className="flex items-start justify-between mb-2">
        <p className="text-xs text-on-surface-dim uppercase tracking-widest">
          {t('impact.scammer_engagement')}
        </p>
        <span
          className="cursor-help text-on-surface-dim hover:text-on-surface text-xs"
          title={t('impact.scammer_engagement_tooltip')}
          aria-label={t('impact.scammer_engagement_tooltip')}
        >
          ⓘ
        </span>
      </div>

      <div className="flex items-baseline gap-3">
        <p className="text-3xl font-light text-on-surface">{ratePct}%</p>
        <p className="text-xs text-on-surface-dim">{subtitle}</p>
        {scamType && (
          <p className="text-xs text-on-surface-variant">
            ({scamTypeLabel(scamType)})
          </p>
        )}
      </div>

      {/* Breakdown — only when no page-level scam_type filter is set */}
      {!scamType && by_scam_type.length > 0 && (
        <div className="mt-4 space-y-1.5">
          <p className="text-xs text-on-surface-dim uppercase tracking-widest mb-2">
            {t('impact.scammer_engagement_breakdown')}
          </p>
          {by_scam_type.slice(0, 8).map((row) => {
            const widthPct = Math.max(2, row.rate_pct);
            const lowSample = row.observable < 5;
            return (
              <div key={row.scam_type} className="flex items-center gap-2 text-xs">
                <span className={`w-32 truncate ${lowSample ? 'text-on-surface-dim' : 'text-on-surface'}`}>
                  {scamTypeLabel(row.scam_type)}
                </span>
                <div className="flex-1 bg-surface-high rounded h-2 overflow-hidden">
                  <div
                    className="bg-accent-muted h-full"
                    style={{ width: `${widthPct}%` }}
                  />
                </div>
                <span className="w-24 text-right text-on-surface-dim font-mono">
                  {row.rate_pct.toFixed(0)}% ({row.responded}/{row.observable})
                </span>
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
