import { useEffect, useMemo, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import type { TheaterMessage, TheaterIoc } from '@/hooks/useTheaterReplay';
import { TheaterPressureBadge } from './TheaterPressureBadge';
import { useMaskMode } from '@/hooks/useMaskMode';
import { maskPiiInBody } from '@/lib/maskPiiInBody';
import { displayValue } from '@/lib/iocMask';

interface TheaterThreadProps {
  messages: TheaterMessage[];
  visibleStep: number;
  iocsByMsg: TheaterIoc[];
  typingDirection: 'in' | 'out' | null;
  /**
   * Additional plain strings that should be redacted from message bodies
   * when masked. Used by the parent to pass the conv-level scammer +
   * persona addresses so quoted-reply blocks (`<r.lastname@...> wrote:`)
   * don't leak the participant emails alongside IOC values.
   */
  extraMaskValues?: readonly string[];
}

/**
 * Spec 097 — Replay thread.
 *
 * Renders the first `visibleStep` messages as bubbles (in = left, out =
 * right). When `typingDirection` is non-null, shows a typing indicator
 * on the corresponding side before the next message reveal.
 *
 * For each OUTBOUND message that has at least one IOC pointing to it as
 * its `stimulus_msg_id`, renders a TheaterPressureBadge above it listing
 * the IOC types that came in the immediately following inbound reveal.
 */
export function TheaterThread({
  messages,
  visibleStep,
  iocsByMsg,
  typingDirection,
  extraMaskValues,
}: TheaterThreadProps) {
  const { t } = useTranslation();
  const { masked } = useMaskMode();
  const scrollerRef = useRef<HTMLDivElement>(null);

  // Body PII masking is now driven by the unified `masked` state.
  // Pass BOTH the raw display value AND the normalized value to the
  // masker — IOC extraction normalizes characters (a phone "+91-7906757261"
  // becomes value_norm "+917906757261"), so matching only on value_norm
  // missed the body's hyphenated form.
  // Also seed with conv-level addresses (scammer + persona) so the
  // quoted-reply lines "On Sun ... <addr@…> wrote:" inside bodies are
  // also redacted.
  const iocValueNorms = useMemo<string[]>(() => {
    if (!masked) return [];
    const set = new Set<string>();
    for (const ioc of iocsByMsg) {
      if (ioc.value_norm) set.add(ioc.value_norm);
      if (ioc.value) set.add(ioc.value);
    }
    for (const addr of extraMaskValues ?? []) {
      if (addr) set.add(addr);
    }

    return Array.from(set);
  }, [iocsByMsg, masked, extraMaskValues]);

  useEffect(() => {
    if (scrollerRef.current) {
      scrollerRef.current.scrollTop = scrollerRef.current.scrollHeight;
    }
  }, [visibleStep, typingDirection]);

  // Build map: outbound msg_id → list of yielded IOC types (only IOCs
  // already revealed at this step, so the badge appears in time).
  const stimulusYield = new Map<string, string[]>();
  for (let i = 0; i < Math.min(visibleStep, messages.length); i++) {
    const msg = messages[i];
    if (msg.direction !== 'in') continue;
    iocsByMsg.forEach((ioc) => {
      if (ioc.msg_id !== msg.msg_id) return;
      const stim = ioc.revelation_context?.stimulus_msg_id;
      if (!stim) return;
      const existing = stimulusYield.get(stim) ?? [];
      if (!existing.includes(ioc.type)) existing.push(ioc.type);
      stimulusYield.set(stim, existing);
    });
  }

  const visible = messages.slice(0, visibleStep);

  return (
    <div ref={scrollerRef} className="flex-1 overflow-y-auto p-6 flex flex-col gap-3" data-testid="theater-thread">
      {visible.length === 0 && !typingDirection && (
        <div
          className="m-auto flex flex-col items-center gap-3 text-center text-on-surface-dim"
          data-testid="theater-teaser"
        >
          <span className="text-5xl opacity-50">▶</span>
          <p className="text-sm font-mono">{t('theater.teaser_hint')}</p>
          <p className="text-xs text-on-surface-dim/70 max-w-sm">
            {t('theater.teaser_play_to_begin')}
          </p>
        </div>
      )}
      {visible.map((msg) => {
        const isIn = msg.direction === 'in';
        const yieldedTypes = stimulusYield.get(msg.msg_id);
        return (
          <div key={msg.msg_id} className={`flex flex-col ${isIn ? 'items-start' : 'items-end'}`}>
            {!isIn && yieldedTypes && yieldedTypes.length > 0 && (
              <TheaterPressureBadge turn={msg.idx} yieldedTypes={yieldedTypes} />
            )}
            <div
              className={`max-w-[74%] rounded-2xl p-4 ${
                isIn
                  ? 'bg-surface-low border-l-2 border-red-400 text-on-surface'
                  : 'bg-surface-high border-r-2 border-blue-400 text-on-surface'
              }`}
            >
              <div className="text-xs font-mono mb-2 flex justify-between gap-4">
                <span className={isIn ? 'text-red-300' : 'text-blue-300'}>
                  {displayValue(msg.sender, 'email', masked)}
                </span>
                <span className="text-on-surface-dim">{formatTime(msg.ts_msg)}</span>
              </div>
              {msg.subject && (
                <p className="text-xs text-on-surface-dim mb-1 italic">{msg.subject}</p>
              )}
              <p className="text-sm leading-relaxed whitespace-pre-line">
                {truncate(masked ? maskPiiInBody(msg.body_text, iocValueNorms) : msg.body_text, 600)}
              </p>
            </div>
          </div>
        );
      })}
      {typingDirection && (
        <div className={`flex ${typingDirection === 'in' ? 'justify-start' : 'justify-end'}`} data-testid="typing-indicator">
          <div className={`rounded-2xl px-4 py-3 ${typingDirection === 'in' ? 'bg-surface-low' : 'bg-surface-high'} flex gap-1`}>
            <span className="w-2 h-2 rounded-full bg-on-surface-dim animate-pulse" />
            <span className="w-2 h-2 rounded-full bg-on-surface-dim animate-pulse" style={{ animationDelay: '150ms' }} />
            <span className="w-2 h-2 rounded-full bg-on-surface-dim animate-pulse" style={{ animationDelay: '300ms' }} />
          </div>
        </div>
      )}
    </div>
  );
}

function formatTime(iso: string): string {
  try {
    const d = new Date(iso);
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false });
  } catch {
    return iso;
  }
}

function truncate(s: string, n: number): string {
  if (s.length <= n) return s;
  return `${s.slice(0, n)}…`;
}
