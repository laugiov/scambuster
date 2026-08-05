import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useTtpSequences, useTtpTaxonomy } from '@/hooks/useTtps';
import type { TtpSequenceGrouping } from '@/types/ttp';

const GROUPINGS: TtpSequenceGrouping[] = ['cluster', 'scam_type'];

/**
 * Top TTP sequences per group (threat-actor cluster or scam type), rendered
 * as the house ordered chips "A → B (×N)" (ClusterTtpPanel vocabulary). The
 * pairs are cross-message bigrams only — same-message TTPs are an unordered
 * co-occurrence set and never form a pair — and the server hides pairs below
 * the minimum-support threshold, which is stated honestly under the panel.
 * The group toggle stays mounted across refetches (PhaseTrendChart card
 * idiom); loads/empties/errors degrade to a note, never a hard error.
 */
export function SequencesPanel() {
  const { t } = useTranslation();
  const [group, setGroup] = useState<TtpSequenceGrouping>('cluster');
  const { data, isLoading, isError } = useTtpSequences(group);
  const { data: taxonomy } = useTtpTaxonomy();

  // TTP labels for chip tooltips, from the already-cached taxonomy query.
  const labelByCode = useMemo(() => {
    const map = new Map<string, string>();
    for (const row of taxonomy?.ttps ?? []) map.set(row.ttp_code, row.ttp_label);
    return map;
  }, [taxonomy?.ttps]);

  const groups = data?.groups ?? [];

  return (
    <section className="bg-surface-low rounded-lg p-5 space-y-3" data-testid="ttp-sequences">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h3 className="text-sm font-medium text-on-surface">{t('ttpPlaybooks.sequencesTitle')}</h3>
          <p className="text-xs text-on-surface-dim mt-0.5">{t('ttpPlaybooks.sequencesSubtitle')}</p>
        </div>
        <div className="flex gap-1.5" role="group" aria-label={t('ttpPlaybooks.groupToggleLabel')}>
          {GROUPINGS.map((option) => (
            <button
              key={option}
              type="button"
              data-testid={`ttp-sequences-group-${option}`}
              onClick={() => setGroup(option)}
              className={`px-3 py-1 text-xs rounded-full transition-colors cursor-pointer ${
                group === option
                  ? 'bg-accent-muted text-on-surface font-medium'
                  : 'bg-surface-high hover:bg-surface-highest text-on-surface-variant'
              }`}
            >
              {option === 'cluster' ? t('ttpPlaybooks.groupCluster') : t('ttpPlaybooks.groupScamType')}
            </button>
          ))}
        </div>
      </div>

      {isLoading ? (
        <p className="text-sm text-on-surface-dim italic">{t('ttpPlaybooks.sequencesLoading')}</p>
      ) : isError || !data || groups.length === 0 ? (
        <p className="text-sm text-on-surface-dim italic" data-testid="ttp-sequences-empty">
          {t('ttpPlaybooks.sequencesEmpty')}
        </p>
      ) : (
        <div className="space-y-3">
          {groups.map((entry) => (
            <div key={entry.key} data-testid="ttp-sequences-group">
              <h4 className="mb-1.5 text-[0.625rem] uppercase tracking-widest text-on-surface-dim">
                {entry.label}
              </h4>
              <div className="flex flex-wrap gap-2">
                {entry.sequences.map((seq, idx) => (
                  <span
                    key={`${seq.sequence.join('>')}-${idx}`}
                    data-testid="ttp-sequence-chip"
                    title={`${seq.sequence.map((code) => labelByCode.get(code) ?? code).join(' → ')} · ${t('ttpPlaybooks.sequenceConversations', { count: seq.conversation_count })}`}
                    className="inline-flex items-center gap-1 rounded-full border border-border bg-surface px-2.5 py-0.5 text-xs text-on-surface-variant"
                  >
                    {seq.sequence.join(' → ')}
                    <span className="text-on-surface-dim">
                      (×{seq.count} · {t('ttpPlaybooks.sequenceConvChip', { count: seq.conversation_count })})
                    </span>
                  </span>
                ))}
              </div>
            </div>
          ))}
        </div>
      )}

      {data?.truncated && (
        <p className="text-[11px] text-on-surface-dim italic" data-testid="ttp-sequences-truncated">
          {t('ttpPlaybooks.sequencesTruncated')}
        </p>
      )}

      {data && (
        <p className="text-[11px] text-on-surface-dim" data-testid="ttp-sequences-min-support">
          {t('ttpPlaybooks.minSupportNote', { count: data.min_support })}
        </p>
      )}
    </section>
  );
}

export default SequencesPanel;
