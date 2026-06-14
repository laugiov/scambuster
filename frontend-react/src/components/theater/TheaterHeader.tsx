import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { useMaskMode } from '@/hooks/useMaskMode';
import type { TheaterMeta } from '@/hooks/useTheaterReplay';
import { scamTypeLabel } from '@/lib/scamTypeLabels';

interface TheaterHeaderProps {
  meta: TheaterMeta;
}

/**
 * Spec 097 — Theater header: title, scam type, scammer/persona addresses,
 * close button, mask toggle.
 */
export function TheaterHeader({ meta }: TheaterHeaderProps) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { masked, toggle } = useMaskMode();

  return (
    <header className="bg-surface-low px-6 py-4 flex items-center justify-between shrink-0 border-b border-outline-variant">
      <div className="flex items-center gap-4">
        <button
          type="button"
          onClick={() => navigate(-1)}
          aria-label={t('theater.close')}
          className="text-on-surface-variant hover:text-on-surface text-2xl leading-none px-2 py-1 rounded hover:bg-surface-high cursor-pointer"
        >
          ✕
        </button>
        <div>
          <h1 className="text-lg font-semibold text-on-surface">
            {t('theater.title')}
          </h1>
          <p className="text-xs text-on-surface-variant font-mono mt-1">
            {scamTypeLabel(meta.scam_type)} ·{' '}
            <span className="text-on-surface-dim">{meta.scammer_address ?? '—'}</span>
            {' ↔ '}
            <span className="text-on-surface-dim">{meta.persona_address ?? '—'}</span>
            {(meta.persona_label || meta.persona_code) && (
              <span
                className="text-on-surface-dim"
                title={meta.persona_code ?? undefined}
              >
                {' '}({meta.persona_label || meta.persona_code})
              </span>
            )}
          </p>
        </div>
      </div>
      <div className="flex items-center gap-3">
        {meta.status === 'open' && (
          <span className="text-xs px-2 py-1 rounded bg-amber-500/20 text-amber-300 border border-amber-500/30">
            {t('theater.in_progress')}
          </span>
        )}
        {meta.long_conversation_truncated && (
          <span className="text-xs px-2 py-1 rounded bg-on-surface-dim/20 text-on-surface-variant border border-outline-variant">
            {t('theater.long_truncated')}
          </span>
        )}
        <button
          type="button"
          onClick={toggle}
          aria-label={t('theater.toggle_mask')}
          aria-pressed={!masked}
          className="text-xs px-3 py-1.5 rounded bg-surface-high text-on-surface-variant hover:text-on-surface border border-outline-variant cursor-pointer"
        >
          {masked ? `👁 ${t('theater.reveal')}` : `🔒 ${t('theater.mask')}`}
        </button>
      </div>
    </header>
  );
}
