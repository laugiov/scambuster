import { useTranslation } from 'react-i18next';
import { useTtpsForIoc } from '@/hooks/useTtps';
import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';

/**
 * "Co-occurring TTPs" panel on the IOC detail page — the IOC → TTP pivot.
 * Self-fetching from {indicatorId} (useTtpsForIoc): each TTP is a badge coloured
 * by kill-chain phase (ttpLabels) with its co-occurrence and conversation counts.
 *
 * Mirrors ClusterTtpPanel's degrade-to-a-dashed-empty idiom: a dashed empty note
 * both when the indicator is unknown (404 → null) and when it simply has no
 * co-observed TTPs, so it never surfaces a hard error. No evidence text is shown.
 */
export function IocCoOccurringTtps({ indicatorId }: { indicatorId: string }) {
  const { t } = useTranslation();
  const { data, isLoading } = useTtpsForIoc(indicatorId);

  if (isLoading) {
    return null;
  }

  const ttps = data?.ttps ?? [];

  if (ttps.length === 0) {
    return (
      <section
        data-testid="ioc-ttps-empty"
        className="rounded-lg border border-dashed border-border bg-surface-low px-5 py-4"
      >
        <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-1">
          {t('ttpPivot.ttpsTitle')}
        </h3>
        <p className="text-sm text-on-surface-dim">{t('ttpPivot.ttpsEmpty')}</p>
      </section>
    );
  }

  return (
    <section data-testid="ioc-ttps" className="rounded-lg border border-border bg-surface-low px-5 py-4 space-y-3">
      <h3 className="text-xs font-bold text-on-surface-dim uppercase tracking-widest">
        {t('ttpPivot.ttpsTitle')}
      </h3>
      <div className="flex flex-wrap gap-2">
        {ttps.map((ttp) => (
          <span
            key={ttp.ttp_code}
            data-testid="ioc-ttp-badge"
            className="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 text-xs"
            title={`${ttp.ttp_label} · ${ttpPhaseLabel(ttp.phase)}`}
          >
            <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(ttp.phase)}`}>
              {ttpPhaseLabel(ttp.phase)}
            </span>
            <span className="text-on-surface">{ttp.ttp_label}</span>
            <span className="font-mono text-[11px] text-on-surface-dim">{ttp.ttp_code}</span>
            <span
              className="font-semibold text-accent"
              title={t('ttpPivot.coOccurrenceTooltip')}
            >
              {t('ttpPivot.coOccurrence', { count: ttp.co_occurrence_count })}
            </span>
          </span>
        ))}
      </div>
    </section>
  );
}

export default IocCoOccurringTtps;
