import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { TheaterMessage, TheaterTtp } from '@/hooks/useTheaterReplay';
import { PHASE_ORDER, ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';

interface TheaterKillChainStripProps {
  ttpsByMsg: TheaterTtp[];
  messages: TheaterMessage[];
  visibleStep: number;
}

/**
 * Kill-chain progress ribbon. Lists the six kill-chain phases in canonical
 * order (hook → exit) and fills each as the replay reveals a confirmed TTP in
 * that phase. A phase is "reached" once a confirmed TTP whose parent message is
 * revealed (1-based `idx <= visibleStep`, the same predicate the panels use)
 * sits in it. Ordering is fixed by PHASE_ORDER — never by observation count.
 *
 * Renders nothing when the conversation carries no confirmed TTPs, so
 * non-TTP conversations don't show a permanently-empty ribbon. Mounted above
 * the detail panels so it survives their IOC-empty early return.
 */
export function TheaterKillChainStrip({ ttpsByMsg, messages, visibleStep }: TheaterKillChainStripProps) {
  const { t } = useTranslation();

  const reached = useMemo<Set<string>>(() => {
    const idxByMsg = new Map<string, number>();
    for (const m of messages) {
      idxByMsg.set(m.msg_id, m.idx);
    }

    const set = new Set<string>();
    for (const ttp of ttpsByMsg ?? []) {
      const idx = idxByMsg.get(ttp.msg_id);
      if (typeof idx === 'number' && visibleStep >= idx) {
        set.add(ttp.phase);
      }
    }

    return set;
  }, [ttpsByMsg, messages, visibleStep]);

  if ((ttpsByMsg ?? []).length === 0) {
    return null;
  }

  return (
    <section className="p-5 border-b border-outline-variant" data-testid="theater-killchain">
      <h2
        className="text-[11px] font-mono uppercase tracking-widest text-on-surface-dim mb-2"
        title={t('theater.killchain.tooltip')}
      >
        {t('theater.killchain.title')}
      </h2>
      <ol className="flex flex-wrap items-center gap-1">
        {PHASE_ORDER.map((phase, i) => {
          const isReached = reached.has(phase);

          return (
            <li key={phase} className="flex items-center gap-1">
              <span
                data-testid={`killchain-phase-${phase}`}
                data-reached={isReached ? 'true' : 'false'}
                className={`rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${
                  isReached ? ttpPhaseColor(phase) : 'bg-surface-highest/40 text-on-surface-dim/50'
                }`}
              >
                {ttpPhaseLabel(phase)}
              </span>
              {i < PHASE_ORDER.length - 1 && (
                <span aria-hidden="true" className="text-on-surface-dim/40">
                  →
                </span>
              )}
            </li>
          );
        })}
      </ol>
    </section>
  );
}
