import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Badge } from '@/components/ui/Badge';
import { useSubmitIocVerdict, type IocVerdict } from '@/hooks/useIocVerdict';

interface IocVerdictPanelProps {
  indicatorId: string;
  verdict?: 'confirmed' | 'false_positive' | null;
  note?: string | null;
  exportHeld?: boolean;
}

/**
 * Analyst verdict panel — the release path for the financial-IOC export hold.
 *
 * Shows the current verdict / held status and lets an analyst record
 * `confirmed` (releases a financial IOC to the feeds) or `false_positive`
 * (removes the IOC from every export). Inline note + inline error — the app
 * has no modal/toast system, by design.
 */
export function IocVerdictPanel({ indicatorId, verdict, note: existingNote, exportHeld }: IocVerdictPanelProps) {
  const { t } = useTranslation();
  const [note, setNote] = useState(existingNote ?? '');
  const submit = useSubmitIocVerdict();

  // The detail loads asynchronously: sync the input once the recorded note
  // arrives (and after a re-submit refetches it).
  useEffect(() => {
    setNote(existingNote ?? '');
  }, [existingNote]);

  const onSubmit = (v: IocVerdict) => {
    submit.mutate({ indicatorId, verdict: v, note: note.trim() || undefined });
  };

  return (
    <section
      data-testid="ioc-verdict-panel"
      className="bg-surface-low rounded-lg p-4 space-y-3"
    >
      <div className="flex items-center gap-2 flex-wrap">
        <h2 className="text-sm font-semibold text-on-surface">{t('iocVerdict.title')}</h2>
        {verdict === 'confirmed' && <Badge label={t('iocVerdict.confirmed')} variant="done" />}
        {verdict === 'false_positive' && <Badge label={t('iocVerdict.falsePositive')} variant="closed" />}
        {!verdict && exportHeld && <Badge label={t('iocVerdict.held')} variant="waiting" />}
        {!verdict && !exportHeld && <Badge label={t('iocVerdict.none')} variant="default" />}
      </div>

      {exportHeld && !verdict && (
        <p className="text-xs text-on-surface-dim">{t('iocVerdict.heldHint')}</p>
      )}

      <input
        type="text"
        value={note}
        onChange={(e) => setNote(e.target.value)}
        placeholder={t('iocVerdict.notePlaceholder')}
        maxLength={1000}
        className="w-full bg-surface-base/60 border border-surface-base rounded px-3 py-1.5 text-sm text-on-surface placeholder-on-surface-dim focus:outline-none focus:ring-2 focus:ring-accent"
        data-testid="verdict-note"
      />

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => onSubmit('confirmed')}
          disabled={submit.isPending}
          className="text-xs px-3 py-1.5 rounded bg-accent text-surface-base font-medium hover:opacity-90 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
          data-testid="verdict-confirm"
        >
          {t('iocVerdict.confirmAction')}
        </button>
        <button
          type="button"
          onClick={() => onSubmit('false_positive')}
          disabled={submit.isPending}
          className="text-xs px-3 py-1.5 rounded border border-surface-base text-on-surface hover:text-red-400 disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
          data-testid="verdict-false-positive"
        >
          {t('iocVerdict.falsePositiveAction')}
        </button>
        {submit.isPending && <span className="text-xs text-on-surface-dim">{t('iocVerdict.saving')}</span>}
        {submit.isSuccess && !submit.isPending && (
          <span className="text-xs text-success" role="status" data-testid="verdict-saved">
            {t('iocVerdict.saved')}
          </span>
        )}
      </div>

      {submit.isError && (
        <p className="text-xs text-red-400" role="alert" data-testid="verdict-error">
          {t('iocVerdict.error')}
        </p>
      )}
    </section>
  );
}
