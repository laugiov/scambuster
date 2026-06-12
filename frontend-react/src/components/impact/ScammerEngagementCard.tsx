import { useState, useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useScammerEngagement } from '@/hooks/useImpact';
import { scamTypeLabel } from '@/lib/scamTypeLabels';

/**
 * Spec 096 / C1 — Scammer engagement (real rate) card.
 *
 * Displays the bias-corrected engagement rate as a single primary metric
 * with a counterpart count subtitle and a breakdown by scam_type below.
 * A tooltip explains the methodology in one sentence.
 *
 * The component always fetches the FULL (unfiltered) breakdown so the
 * dropdown options never collapse. When the user selects a scam_type,
 * the primary metric updates client-side from the corresponding row in
 * the breakdown. This avoids the dropdown-narrowing UX bug observed
 * during C1 manual validation.
 *
 * C4 will replace the embedded dropdown by the page-level scam_type
 * filter (passed as a prop).
 */
export function ScammerEngagementCard() {
  const { t } = useTranslation();
  const [scamFilter, setScamFilter] = useState<string>('');
  // Always fetch unfiltered → single source of truth for both the
  // dropdown options AND the displayed metric.
  const { data, isLoading, error } = useScammerEngagement(96, undefined);

  // Derived displayed metric — global when no filter, the selected row otherwise
  const displayed = useMemo(() => {
    if (!data) return null;
    if (!scamFilter) return data.global;
    const row = data.by_scam_type.find((r) => r.scam_type === scamFilter);
    return row ?? data.global;
  }, [data, scamFilter]);

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

  if (error || !data || !displayed) {
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

  const { by_scam_type } = data;
  const ratePct = displayed.rate_pct.toFixed(1);
  const subtitle = `${displayed.responded}/${displayed.observable} ${t('impact.scammer_engagement_senders')}`;

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
        {scamFilter && (
          <p className="text-xs text-on-surface-variant">
            ({scamTypeLabel(scamFilter)})
          </p>
        )}
      </div>

      {/* Embedded scam_type filter (C1 only — replaced by page filter in C4) */}
      <div className="mt-4">
        <label className="text-xs text-on-surface-dim block mb-1">
          {t('impact.scammer_engagement_filter_label')}
        </label>
        <select
          className="text-xs bg-surface-high text-on-surface rounded px-2 py-1 border border-outline-variant w-full"
          value={scamFilter}
          onChange={(e) => setScamFilter(e.target.value)}
        >
          <option value="">{t('impact.scammer_engagement_filter_all')}</option>
          {by_scam_type
            .filter((row) => row.scam_type)
            .map((row) => (
              <option key={row.scam_type} value={row.scam_type}>
                {scamTypeLabel(row.scam_type)} ({row.observable})
              </option>
            ))}
        </select>
      </div>

      {/* Breakdown bars — only when no client-side filter is applied */}
      {!scamFilter && by_scam_type.length > 0 && (
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
