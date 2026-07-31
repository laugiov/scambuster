import { Link } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useIocsForTtp } from '@/hooks/useTtps';
import { iocTypeLabel } from '@/lib/iocTypeLabels';

/**
 * "Co-occurring IOCs" section for a TTP Explorer row's expand panel — the
 * TTP → IOC pivot. Rendered only while the row is expanded: because the parent
 * mounts this component lazily (conditional render, no useEffect), the query in
 * useIocsForTtp fires on mount and never runs for a collapsed row.
 *
 * Each IOC deep-links to its detail page (/ioc-explorer/{indicator_id}) via the
 * indicator_id the backend now returns. Values are shown normalized (value_norm),
 * matching the IOC pages' own rendering; no evidence text is available or shown.
 */
export function TtpIocsSection({ code }: { code: string }) {
  const { t } = useTranslation();
  const { data, isLoading } = useIocsForTtp(code);

  const iocs = data?.iocs ?? [];

  return (
    <div className="mt-4 border-t border-surface-high pt-3" data-testid="ttp-iocs-section">
      <p className="text-xs font-bold text-on-surface-dim uppercase tracking-widest mb-2">
        {t('ttpPivot.iocsTitle')}
      </p>

      {isLoading ? (
        <p className="text-xs text-on-surface-dim italic">{t('ttpPivot.iocsLoading')}</p>
      ) : iocs.length === 0 ? (
        <p className="text-xs text-on-surface-dim italic" data-testid="ttp-iocs-empty">
          {t('ttpPivot.iocsEmpty')}
        </p>
      ) : (
        <ul className="flex flex-col gap-1.5">
          {iocs.map((ioc) => (
            <li key={ioc.indicator_id}>
              <Link
                to={`/ioc-explorer/${ioc.indicator_id}`}
                data-testid="ttp-ioc-link"
                className="group flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md border border-border bg-surface px-3 py-1.5 hover:bg-surface-high/50 transition-colors"
              >
                <span className="text-[0.625rem] uppercase tracking-wide text-on-surface-dim">
                  {iocTypeLabel(ioc.type)}
                </span>
                <span className="font-mono text-xs text-accent break-all group-hover:underline">
                  {ioc.value_norm}
                </span>
                <span className="ml-auto flex items-center gap-3 text-[11px] text-on-surface-dim">
                  <span title={t('ttpPivot.coOccurrenceTooltip')}>
                    {t('ttpPivot.coOccurrence', { count: ioc.co_occurrence_count })}
                  </span>
                  <span title={t('ttpPivot.conversationsTooltip')}>
                    {t('ttpPivot.conversations', { count: ioc.conversation_count })}
                  </span>
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

export default TtpIocsSection;
