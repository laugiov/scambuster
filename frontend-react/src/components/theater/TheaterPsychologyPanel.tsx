import { useTranslation } from 'react-i18next';
import type { TheaterHumanFactor, TheaterMeta } from '@/hooks/useTheaterReplay';
import { useFinancialRevealTiming } from '@/hooks/useFinancialRevealTiming';

interface TheaterPsychologyPanelProps {
  hf: TheaterHumanFactor;
  meta: TheaterMeta;
  finished: boolean;
  /**
   * Spec 099 S4 — current player step (0..totalSteps). Used to
   * progressively reveal climax stats like `first_financial_at`
   * instead of spoiling them on the empty step-0 frame.
   */
  visibleStep?: number;
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
export function TheaterPsychologyPanel({ hf, meta, finished, visibleStep }: TheaterPsychologyPanelProps) {
  const { t } = useTranslation();
  const det = hf.deterministic;
  const llm = hf.exploratory_llm_signals;
  // Spec 100 S6 — coverage warning threshold relaxed from <50% to
  // <30%. Empirically the enrichment pipeline routinely lands in the
  // 40-60% range on healthy data; flagging that as "limited" trained
  // viewers to ignore the warning. <30% is the new threshold where
  // the LLM signals genuinely cease to be representative.
  const lowCoverage = meta.enrichment_coverage_pct < 30;
  const confidencePct =
    typeof llm.enrichment_confidence_avg === 'number'
      ? Math.round(llm.enrichment_confidence_avg * 100)
      : null;
  // Spec 099 S4 — progressive reveal of the financial-IOC turn. Until
  // playback reaches that turn, show the unrevealed placeholder so the
  // climax isn't pre-spoiled on the empty step-0 frame.
  const financialRevealed =
    det.first_financial_turn === null
    || visibleStep === undefined
    || visibleStep >= det.first_financial_turn;

  // Spec 100 S2 — corpus baseline so the per-conv reveal turn is
  // contextualised ("typical" vs "outlier"). Hidden until the per-conv
  // line has been revealed to avoid pre-spoiling.
  const corpusTiming = useFinancialRevealTiming();
  const corpus = corpusTiming.data;
  const showCorpusLine =
    financialRevealed
    && corpus !== undefined
    && corpus.n > 0
    && corpus.median_ratio_pct !== null
    && det.first_financial_ratio !== null;
  const corpusThisRatio = det.first_financial_ratio !== null
    ? Math.round(det.first_financial_ratio * 100)
    : 0;
  const isTypicalReveal =
    corpus?.median_ratio_pct !== undefined
    && corpus?.median_ratio_pct !== null
    && corpusThisRatio >= corpus.median_ratio_pct - 15;

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
              : financialRevealed
                ? t('theater.turn_x_of_y', {
                    turn: det.first_financial_turn,
                    total: det.total_turns,
                    pct: det.first_financial_ratio
                      ? Math.round(det.first_financial_ratio * 100)
                      : 0,
                  })
                : t('theater.spoiler_pending')
          }
          prefix={t('theater.in_this_conv')}
        />
        {/* Spec 100 S2 — corpus baseline for the financial-reveal turn */}
        {showCorpusLine && corpus && (
          <p
            className="text-[11px] text-on-surface-dim mt-1 pl-30"
            data-testid="theater-corpus-timing"
          >
            <span className="text-on-surface-variant font-mono">
              {t('theater.corpus_median', {
                pct: corpus.median_ratio_pct,
                n: corpus.n,
              })}
            </span>
            {' — '}
            {isTypicalReveal ? (
              <span className="text-emerald-300">{t('theater.typical_pattern')}</span>
            ) : (
              <span className="text-amber-300">{t('theater.outlier_pattern')}</span>
            )}
          </p>
        )}
        <Stat
          label={t('theater.scammer_response_median')}
          value={
            det.scammer_response_time_hours_median === null
              ? '—'
              : `${det.scammer_response_time_hours_median.toFixed(1)} h`
          }
          prefix={t('theater.in_this_conv')}
        />
        {/* Spec 101 S6 — progressively reveal cascade events. We
            count only cascades whose trigger message has actually been
            played, so the number ticks up live with the playback
            instead of spoiling the final count on the empty step-0
            frame. `messages` is not in scope here so we rely on the
            visibleStep + cascade.turn comparison (turn is 1-based). */}
        <Stat
          label={t('theater.cascade_events_label')}
          value={
            visibleStep === undefined
              ? `${det.cascade_events.length}`
              : `${det.cascade_events.filter((c) => c.turn <= visibleStep).length}`
          }
          prefix={t('theater.in_this_conv')}
        />
        <Stat
          label={t('theater.persona_used')}
          value={`${meta.persona_label ?? meta.persona_code ?? det.persona_pressure_profile.persona_code ?? '—'} (${det.persona_pressure_profile.financial_obtained}/${det.persona_pressure_profile.iocs_obtained} ${t('theater.financial_short')})`}
          prefix={t('theater.in_this_conv')}
        />
        {/* Spec 101 S6 — language_switch_count is a per-conv aggregate
            without per-turn data on the wire, so we cannot tick it up
            progressively. We hide it until the playback completes (or
            visibleStep is undefined, i.e. no player context) and show
            "(reveals as you play)" until then. Final state is still
            accurate. */}
        <Stat
          label={t('theater.language_switches')}
          value={
            visibleStep === undefined || visibleStep >= det.total_turns
              ? `${det.language_switch_count}`
              : t('theater.spoiler_pending')
          }
          prefix={t('theater.in_this_conv')}
        />
      </div>

      {/* EXPLORATORY LLM SIGNALS — sub-section with caveat.
          Spec 099 S5: hide-when-empty. If every per-IOC signal field
          is zero/null, the sub-block collapses to a single honest line
          rather than displaying "0 / N" everywhere (which reads as
          "feature broken"). The deterministic block above carries the
          conversation either way. */}
      {(() => {
        // Spec 100 S5 — tighter "empty" heuristic. Previously all 3
        // had to be zero; in practice the LLM often produces a low
        // non-zero urgency (e.g. 24%) on otherwise-empty data, which
        // kept the full panel rendering "0/N · 0/N · 24%" — reads
        // as broken. New rule: ≥2 of the 3 signal fields zero/null
        // counts as empty.
        const zeros = [
          (llm.iocs_under_active_stimulus ?? 0) === 0 ? 1 : 0,
          (llm.hesitation_count ?? 0) === 0 ? 1 : 0,
          (llm.avg_urgency_at_reveal === null || llm.avg_urgency_at_reveal === 0) ? 1 : 0,
        ].reduce<number>((a, b) => a + b, 0);
        const isEmpty = zeros >= 2;

        if (isEmpty) {
          return (
            <div
              className="bg-surface-low rounded-lg p-4 flex flex-col gap-1 border border-outline-variant"
              data-testid="theater-psychology-llm-empty"
            >
              <h3 className="text-[11px] font-mono uppercase tracking-widest text-on-surface-dim mb-1">
                {t('theater.exploratory_signals')}
              </h3>
              <p className="text-[11px] italic text-on-surface-dim">
                {t('theater.no_labelled_signals')}
              </p>
            </div>
          );
        }

        return (
          <div
            className="bg-surface-low rounded-lg p-4 flex flex-col gap-2 border border-amber-500/20"
            data-testid="theater-psychology-llm"
          >
            <h3 className="text-[11px] font-mono uppercase tracking-widest text-amber-300/80 mb-1">
              {t('theater.exploratory_signals')}
              {confidencePct !== null && (
                <span className="ml-2 text-on-surface-dim normal-case font-normal tracking-normal">
                  — {t('theater.avg_confidence', { pct: confidencePct, n: meta.iocs_count })}
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
            {/* Hesitation is hidden when always-zero in the corpus
                (spec 102 prompt v2 collapsed it to ~0.12% TRUE rate).
                Displaying "0/N" on every demo signal looked broken;
                showing only when non-zero is honest. */}
            {(llm.hesitation_count ?? 0) > 0 && (
              <SmallStat
                label={t('theater.hesitation_labelled')}
                value={`${llm.hesitation_count} / ${meta.iocs_count}`}
              />
            )}
            <SmallStat label={t('theater.coverage')} value={`${meta.enrichment_coverage_pct.toFixed(0)}%`} />
          </div>
        );
      })()}

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
