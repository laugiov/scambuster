import { useTranslation } from 'react-i18next';
import type { TheaterIoc } from '@/hooks/useTheaterReplay';
import { categoryColorClass } from '@/lib/iocCategory';
import { MaskedValue } from './MaskedValue';

interface TheaterIocCardProps {
  ioc: TheaterIoc;
}

/**
 * Spec 097 — Single IOC card in the Intelligence panel.
 *
 * Always renders type + masked value + category badge. When
 * `revelation_context.enrichment_status === 'enriched'`, also renders
 * the psychological footprint (stimulus, urgency, hesitation, semantic
 * role, context excerpt, co-revealed). Confidence is ALWAYS shown next
 * to the semantic role per spec § Construct validity.
 *
 * When confidence < 0.40, the semantic role and excerpt are visually
 * de-emphasized (muted color + low-confidence icon).
 */
export function TheaterIocCard({ ioc }: TheaterIocCardProps) {
  const { t } = useTranslation();
  const ctx = ioc.revelation_context;
  const isEnriched = ctx?.enrichment_status === 'enriched';
  const isLowConfidence = isEnriched && (ctx?.enrichment_confidence ?? 1) < 0.4;
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
          {isEnriched && ctx.stimulus_type === 'active' && (
            <span className="inline-block text-[10px] uppercase tracking-widest px-1.5 py-0.5 rounded bg-amber-500/15 text-amber-300 border border-amber-500/30">
              ⚡ {t('theater.active_stimulus')}
            </span>
          )}
          {isEnriched && typeof ctx.urgency_score === 'number' && (
            <UrgencyBar value={ctx.urgency_score} label={t('theater.scammer_urgency')} />
          )}
          {isEnriched && ctx.hesitation_detected && (
            <span className="inline-block text-[10px] text-purple-300 px-1.5 py-0.5 rounded bg-purple-500/15 border border-purple-500/30">
              💧 {t('theater.hesitation')}
            </span>
          )}
          {isEnriched && ctx.semantic_role && (
            <p className={`text-xs font-mono mt-1 ${isLowConfidence ? 'text-on-surface-dim italic' : 'text-on-surface-variant'}`}>
              {t('theater.role')}: <span className="font-semibold">{ctx.semantic_role}</span>
              {typeof ctx.enrichment_confidence === 'number' && (
                <span className={isLowConfidence ? 'ml-1' : 'ml-1 text-on-surface-dim'}>
                  · {Math.round((ctx.enrichment_confidence ?? 0) * 100)}%
                  {isLowConfidence && <span title={t('theater.low_confidence')} className="ml-1">⚠</span>}
                </span>
              )}
            </p>
          )}
          {isEnriched && ctx.context_excerpt && (
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
  return (
    <div>
      <div className="flex justify-between items-baseline text-[10px] text-on-surface-dim">
        <span>{label}</span>
        <span className="font-mono">{Math.round(pct)}%</span>
      </div>
      <div className="h-1 bg-surface-high rounded mt-0.5 overflow-hidden">
        <div className="h-full bg-amber-400" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}
