import { useTranslation } from 'react-i18next';
import type { TheaterIoc, TheaterMessage } from '@/hooks/useTheaterReplay';
import { TheaterIocCard } from './TheaterIocCard';

interface TheaterIntelligencePanelProps {
  iocs: TheaterIoc[];
  messages: TheaterMessage[];
  visibleStep: number;
}

/**
 * Spec 097 — Intelligence panel showing IOCs as they appear over the
 * playback. Each IOC appears when its parent message is revealed
 * (visibleStep >= parent_msg.idx).
 */
export function TheaterIntelligencePanel({ iocs, messages, visibleStep }: TheaterIntelligencePanelProps) {
  const { t } = useTranslation();

  // Map msg_id -> idx so we know when to reveal each IOC.
  const idxByMsg = new Map<string, number>();
  messages.forEach((m) => idxByMsg.set(m.msg_id, m.idx));

  const visibleIocs = iocs.filter((ioc) => {
    const parentIdx = idxByMsg.get(ioc.msg_id);
    return typeof parentIdx === 'number' && visibleStep >= parentIdx;
  });

  const financialCount = visibleIocs.filter((i) => i.category === 'financial').length;

  if (iocs.length === 0) {
    return (
      <section className="p-5 flex flex-col gap-3">
        <h2 className="text-xs font-mono uppercase tracking-widest text-on-surface-dim">
          {t('theater.intelligence_panel')}
        </h2>
        <p className="text-sm text-on-surface-dim italic">
          {t('theater.no_iocs')}
        </p>
      </section>
    );
  }

  return (
    <section className="p-5 flex flex-col gap-3" data-testid="theater-intelligence-panel">
      <h2 className="text-xs font-mono uppercase tracking-widest text-on-surface-dim">
        {t('theater.intelligence_panel')}
      </h2>
      <div className="flex items-baseline gap-3">
        <p className="text-3xl font-light text-on-surface">{visibleIocs.length}</p>
        <p className="text-xs text-on-surface-dim">
          {t('theater.iocs_extracted', { count: visibleIocs.length })}
        </p>
      </div>
      {financialCount > 0 && (
        <p className="text-xs text-amber-300 font-mono">
          {t('theater.financial_count', { count: financialCount })}
        </p>
      )}
      <div className="flex flex-col gap-2 mt-2">
        {visibleIocs.map((ioc) => (
          <TheaterIocCard key={ioc.indicator_id} ioc={ioc} />
        ))}
      </div>
    </section>
  );
}
