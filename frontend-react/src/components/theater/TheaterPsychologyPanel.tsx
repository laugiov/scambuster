import { useTranslation } from 'react-i18next';
import type { TheaterHumanFactor, TheaterMeta } from '@/hooks/useTheaterReplay';

interface TheaterPsychologyPanelProps {
  hf: TheaterHumanFactor;
  meta: TheaterMeta;
  finished: boolean;
}

/**
 * Spec 097 — Psychology / Human Factor panel.
 *
 * STRUCTURE per spec § US7:
 * - DETERMINISTIC sub-section FIRST, in BIG/headline typography. This
 *   is the defensible thesis (engagement, response times, cascades,
 *   persona, deterministic language_switch).
 * - EXPLORATORY LLM SIGNALS sub-section SECOND, smaller, with an
 *   explicit caveat header that includes the average confidence.
 *
 * ALL per-conv aggregates are prefixed with "In this conversation: …"
 * — no bare numbers — to remind the viewer this is one case.
 *
 * Causal verbs are FORBIDDEN. Use "preceded", "co-occurred", "labelled".
 */
export function TheaterPsychologyPanel({ hf, meta, finished }: TheaterPsychologyPanelProps) {
  const { t } = useTranslation();
  const det = hf.deterministic;
  const llm = hf.exploratory_llm_signals;
  const lowCoverage = meta.enrichment_coverage_pct < 50;
  const confidencePct =
    typeof llm.enrichment_confidence_avg === 'number'
      ? Math.round(llm.enrichment_confidence_avg * 100)
      : null;

  return (
    <section className="p-5 flex flex-col gap-4" data-testid="theater-psychology-panel">
      <h2 className="text-xs font-mono uppercase tracking-widest text-on-surface-dim">
        {t('theater.psychology_panel')}
      </h2>

      {/* DETERMINISTIC — headline */}
      <div className="bg-surface-low rounded-lg p-4 flex flex-col gap-2">
        <h3 className="text-xs font-mono uppercase tracking-widest text-on-surface-variant mb-1">
          {t('theater.deterministic_metrics')}
        </h3>
        <Stat
          label={t('theater.engagement_duration')}
          value={`${det.engagement_hours.toFixed(1)} h`}
          prefix={t('theater.in_this_conv')}
        />
        <Stat
          label={t('theater.first_financial_at')}
          value={
            det.first_financial_turn === null
              ? '—'
              : t('theater.turn_x_of_y', {
                  turn: det.first_financial_turn,
                  total: det.total_turns,
                  pct: det.first_financial_ratio
                    ? Math.round(det.first_financial_ratio * 100)
                    : 0,
                })
          }
          prefix={t('theater.in_this_conv')}
        />
        <Stat
          label={t('theater.scammer_response_median')}
          value={
            det.scammer_response_time_hours_median === null
              ? '—'
              : `${det.scammer_response_time_hours_median.toFixed(1)} h`
          }
          prefix={t('theater.in_this_conv')}
        />
        <Stat
          label={t('theater.cascade_events_label')}
          value={`${det.cascade_events.length}`}
          prefix={t('theater.in_this_conv')}
        />
        <Stat
          label={t('theater.persona_used')}
          value={`${det.persona_pressure_profile.persona_code ?? '—'} (${det.persona_pressure_profile.financial_obtained}/${det.persona_pressure_profile.iocs_obtained} ${t('theater.financial_short')})`}
          prefix={t('theater.in_this_conv')}
        />
        <Stat
          label={t('theater.language_switches')}
          value={`${det.language_switch_count}`}
          prefix={t('theater.in_this_conv')}
        />
      </div>

      {/* EXPLORATORY LLM SIGNALS — sub-section with caveat */}
      <div className="bg-surface-low rounded-lg p-4 flex flex-col gap-2 border border-amber-500/20">
        <h3 className="text-[11px] font-mono uppercase tracking-widest text-amber-300/80 mb-1">
          {t('theater.exploratory_signals')}
          {confidencePct !== null && (
            <span className="ml-2 text-on-surface-dim normal-case font-normal tracking-normal">
              — {t('theater.avg_confidence', { pct: confidencePct })}
            </span>
          )}
        </h3>
        <p className="text-[11px] italic text-on-surface-dim mb-2">
          {t('theater.exploratory_caveat')}
        </p>
        {lowCoverage && (
          <p className="text-[11px] text-amber-300 font-mono mb-1">
            ⚠ {t('theater.limited_coverage', { pct: meta.enrichment_coverage_pct.toFixed(0) })}
          </p>
        )}
        <SmallStat
          label={t('theater.iocs_under_active_stim')}
          value={`${llm.iocs_under_active_stimulus} / ${meta.iocs_count}`}
        />
        <SmallStat
          label={t('theater.avg_urgency')}
          value={
            llm.avg_urgency_at_reveal === null
              ? '—'
              : `${Math.round(llm.avg_urgency_at_reveal * 100)}%`
          }
        />
        <SmallStat
          label={t('theater.hesitation_labelled')}
          value={`${llm.hesitation_count} / ${meta.iocs_count}`}
        />
        <SmallStat label={t('theater.coverage')} value={`${meta.enrichment_coverage_pct.toFixed(0)}%`} />
      </div>

      {finished && (
        <p className="text-[11px] text-on-surface-dim italic px-1">
          {t('theater.design_footnote')}
        </p>
      )}
    </section>
  );
}

function Stat({ label, value, prefix }: { label: string; value: string; prefix: string }) {
  return (
    <p className="text-sm text-on-surface">
      <span className="text-xs text-on-surface-dim font-mono">{prefix}: </span>
      <span className="text-on-surface-variant">{label}</span>{' '}
      <span className="font-semibold">{value}</span>
    </p>
  );
}

function SmallStat({ label, value }: { label: string; value: string }) {
  return (
    <p className="text-xs text-on-surface-variant">
      <span>{label}</span>{' '}
      <span className="font-mono text-on-surface">{value}</span>
    </p>
  );
}
