import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { TheaterMessage } from '@/hooks/useTheaterReplay';
import { detectTradecraftSignals } from '@/lib/tradecraftDetection';

interface TheaterTradecraftChipsProps {
  messages: TheaterMessage[];
  visibleStep: number;
}

/**
 * Spec 101 S4 — render the tradecraft signals detected in the
 * already-revealed message bodies. A signal is currently always an
 * email-tracker beacon (Mailsuite / Mailtrack / Sidekick / generic);
 * the component renders one purple chip per (msg, kind) pair, with a
 * tooltip explaining what the signal means in CTI terms.
 *
 * Hidden until at least one signal has been observed at the current
 * playback step.
 */
export function TheaterTradecraftChips({ messages, visibleStep }: TheaterTradecraftChipsProps) {
  const { t } = useTranslation();

  const signals = useMemo(() => {
    const visible = messages.slice(0, visibleStep);
    return detectTradecraftSignals(visible);
  }, [messages, visibleStep]);

  if (signals.length === 0) return null;

  return (
    <div className="mt-4 border-t border-outline-variant pt-3" data-testid="theater-tradecraft">
      <h3 className="text-[11px] font-mono uppercase tracking-widest text-purple-300/80 mb-2">
        {t('theater.tradecraft_title')}
      </h3>
      <div className="flex flex-wrap gap-1.5">
        {signals.map((sig, i) => (
          <span
            key={`${sig.msg_id}-${sig.kind}-${i}`}
            data-testid={`tradecraft-chip-${sig.kind}`}
            title={t('theater.tradecraft_tooltip', {
              kind: t(`theater.tradecraft_kind.${sig.kind}`),
              turn: sig.msg_idx,
            })}
            className="inline-flex items-center gap-1 text-[10px] font-mono px-2 py-1 rounded bg-purple-500/15 text-purple-200 border border-purple-500/40"
          >
            <span aria-hidden="true">📡</span>
            <span>{t(`theater.tradecraft_kind.${sig.kind}` as const)}</span>
            <span className="text-purple-300/60">· t{sig.msg_idx}</span>
          </span>
        ))}
      </div>
    </div>
  );
}

