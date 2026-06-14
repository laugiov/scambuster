import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import type { TheaterMessage, TheaterIoc } from '@/hooks/useTheaterReplay';
import { TheaterPressureBadge } from './TheaterPressureBadge';

interface TheaterThreadProps {
  messages: TheaterMessage[];
  visibleStep: number;
  iocsByMsg: TheaterIoc[];
  typingDirection: 'in' | 'out' | null;
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
export function TheaterThread({ messages, visibleStep, iocsByMsg, typingDirection }: TheaterThreadProps) {
  const { t } = useTranslation();
  const scrollerRef = useRef<HTMLDivElement>(null);

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
                <span className={isIn ? 'text-red-300' : 'text-blue-300'}>{msg.sender}</span>
                <span className="text-on-surface-dim">{formatTime(msg.ts_msg)}</span>
              </div>
              {msg.subject && (
                <p className="text-xs text-on-surface-dim mb-1 italic">{msg.subject}</p>
              )}
              <p className="text-sm leading-relaxed whitespace-pre-line">{truncate(msg.body_text, 600)}</p>
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
