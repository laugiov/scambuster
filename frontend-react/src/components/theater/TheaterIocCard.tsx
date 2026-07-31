import { useTranslation } from 'react-i18next';
import type { TheaterIoc } from '@/hooks/useTheaterReplay';
import { useUrgencyCorpusStats } from '@/hooks/useUrgencyCorpusStats';
import { categoryColorClass } from '@/lib/iocCategory';
import { isActiveStimulus } from '@/lib/stimulusLabels';
import { MaskedValue } from './MaskedValue';

interface TheaterIocCardProps {
  ioc: TheaterIoc;
}

/**
 * Single IOC card in the Intelligence panel.
 *
 * Always renders type + masked value + category badge. When
 * `revelation_context.enrichment_status === 'enriched'`, also renders
 * the psychological footprint (stimulus, urgency, hesitation, semantic
 * role, context excerpt, co-revealed). Confidence is ALWAYS shown next
 * to the semantic role for construct validity.
 *
 * Confidence gating:
 *   - `enrichment_confidence < ROLE_DISPLAY_THRESHOLD` (0.70): semantic
 *     role and excerpt are NOT rendered at all (was: muted style). The
 *     pre-BH review showed `PHISHING_CREDENTIAL_URL` at 50% on a Play
 *     Store search URL — labels under threshold are more harmful than
 *     the confidence number alone.
 *   - The `LOW_CONFIDENCE_MUTE_THRESHOLD` (0.40) still mutes the
 *     remaining display when in the 0.40–0.70 grey zone (no role
 *     shown, but the rest of the footprint stays).
 */
const ROLE_DISPLAY_THRESHOLD = 0.7;
const LOW_CONFIDENCE_MUTE_THRESHOLD = 0.4;

export function TheaterIocCard({ ioc }: TheaterIocCardProps) {
  const { t } = useTranslation();
  const ctx = ioc.revelation_context;
  const isEnriched = ctx?.enrichment_status === 'enriched';
  const confidence = ctx?.enrichment_confidence ?? 1;
  const isLowConfidence = isEnriched && confidence < LOW_CONFIDENCE_MUTE_THRESHOLD;
  const shouldDisplayRole = isEnriched && confidence >= ROLE_DISPLAY_THRESHOLD;
  const isFinancial = ioc.category === 'financial';

  return (
    <div
      data-testid="theater-ioc-card"
      data-category={ioc.category}
      className={`rounded-lg border p-3 ${
        isFinancial
          ? 'bg-amber-500/10 border-amber-500/40'
          : 'bg-surface-low border-outline-variant'
      }`}
    >
      <div className="flex items-start justify-between gap-2">
        <span className={`text-[10px] uppercase tracking-widest font-mono px-1.5 py-0.5 rounded border ${categoryColorClass(ioc.category)}`}>
          {ioc.type}
        </span>
        {isFinancial && (
          <span className="text-[10px] font-bold text-amber-300 px-1.5 py-0.5 rounded bg-amber-500/20">
            {t('theater.financial')}
          </span>
        )}
      </div>
      <p className="text-sm font-mono mt-2 break-all">
        <MaskedValue value={ioc.value} type={ioc.type} />
      </p>

      {/* Slice 3: psychological footprint block */}
      {ctx && (
        <div className="mt-3 pt-3 border-t border-outline-variant/50 space-y-1.5">
          {/* Any stimulus other than PASSIVE/UNKNOWN reflects an outbound
              prompt — the badge condition is an exclusion, not a whitelist,
              so new enum values degrade visibly instead of silently. */}
          {isEnriched && isActiveStimulus(ctx.stimulus_type) && (
            <span className="inline-block text-[10px] uppercase tracking-widest px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-300 border border-amber-500/30">
              ⚡ {t('theater.active_stimulus')}
            </span>
          )}
          {/* urgency_score is LLM-derived; gate by the
              same ≥0.7 confidence threshold as the Role label, so the
              deterministic/exploratory separation that holds in the
              summary panel also holds at the card level. */}
          {shouldDisplayRole && typeof ctx.urgency_score === 'number' && (
            <UrgencyBar value={ctx.urgency_score} label={t('theater.scammer_urgency')} />
          )}
          {isEnriched && ctx.hesitation_detected && (
            <span className="inline-block text-[10px] text-purple-300 px-1.5 py-0.5 rounded bg-purple-500/15 border border-purple-500/30">
              💧 {t('theater.hesitation')}
            </span>
          )}
          {shouldDisplayRole && ctx.semantic_role && (
            <p className="text-xs font-mono mt-1 text-on-surface-variant">
              {t('theater.role')}: <span className="font-semibold">{ctx.semantic_role}</span>
              <span className="ml-1 text-on-surface-dim">
                · {Math.round(confidence * 100)}%
              </span>
            </p>
          )}
          {/* context_excerpt is the LLM's free-text
              narrative; gate by the same ≥0.7 confidence threshold.
              Under threshold the excerpt tended to repeat the same
              templated phrase across cards (low specificity) and
              presented itself as fact, which broke the deterministic/
              exploratory separation. */}
          {shouldDisplayRole && ctx.context_excerpt && (
            <p className={`text-xs italic ${isLowConfidence ? 'text-on-surface-dim' : 'text-on-surface-variant'}`}>
              “{ctx.context_excerpt}”
            </p>
          )}
          {isEnriched && (ctx.co_revealed_count ?? 0) > 0 && (ctx.co_revealed_types?.length ?? 0) > 0 && (
            <p className="text-[11px] text-on-surface-dim">
              🔁 {t('theater.co_revealed')}: {(ctx.co_revealed_types ?? []).join(', ')}
            </p>
          )}
          {!isEnriched && (
            <span className="inline-block text-[10px] uppercase tracking-widest px-1.5 py-0.5 rounded bg-on-surface-dim/15 text-on-surface-dim border border-outline-variant">
              {t('theater.enrichment_pending')}
            </span>
          )}
        </div>
      )}
    </div>
  );
}

function UrgencyBar({ value, label }: { value: number; label: string }) {
  const pct = Math.max(0, Math.min(100, value * 100));
  const corpus = useUrgencyCorpusStats();
  const medianPct =
    corpus.data?.median !== null && corpus.data?.median !== undefined
      ? Math.max(0, Math.min(100, corpus.data.median * 100))
      : null;

  return (
    <div>
      <div className="flex justify-between items-baseline text-[10px] text-on-surface-dim">
        <span>{label}</span>
        <span className="font-mono">{Math.round(pct)}%</span>
      </div>
      <div className="relative h-1 bg-surface-high rounded mt-0.5 overflow-hidden">
        <div className="h-full bg-amber-400" style={{ width: `${pct}%` }} />
        {/* Corpus median tick. Lets the viewer
            see at a glance whether this IOC is above or below the
            typical urgency. Subtle so it doesn't compete with the
            per-IOC bar; positioned by left/transform so it stays a
            single pixel of contrast regardless of card width. */}
        {medianPct !== null && (
          <div
            className="absolute top-0 h-full w-px bg-on-surface-dim/60"
            style={{ left: `${medianPct}%` }}
            data-testid="urgency-corpus-median-tick"
          />
        )}
      </div>
    </div>
  );
}
