import { useCallback, useEffect, useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useTheaterReplay } from '@/hooks/useTheaterReplay';
import { useTheaterPlayer } from '@/hooks/useTheaterPlayer';
import { useReducedMotion } from '@/hooks/useReducedMotion';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import { useMaskMode } from '@/hooks/useMaskMode';
import { TheaterHeader } from '@/components/theater/TheaterHeader';
import { TheaterThread } from '@/components/theater/TheaterThread';
import { TheaterIntelligencePanel } from '@/components/theater/TheaterIntelligencePanel';
import { TheaterPsychologyPanel } from '@/components/theater/TheaterPsychologyPanel';
import { TheaterTransport, type TheaterChapter } from '@/components/theater/TheaterTransport';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

/**
 * Spec 097 — Live Bait Theater page.
 *
 * Slice 3: full static rendering.
 * Slice 4: playback state machine (useTheaterPlayer) + transport bar +
 *          keyboard shortcuts (spacebar toggles play/pause).
 * Slice 5: M-key wired for mask toggle + reduced-motion polish.
 */
export function Theater() {
  const { t } = useTranslation();
  const { id: convId } = useParams<{ id: string }>();
  const { data, isLoading, error, refetch } = useTheaterReplay(convId);

  if (isLoading) return <Loading message={t('theater.loading')} />;
  if (error || !data) {
    return <ErrorMessage message={t('theater.error')} onRetry={() => void refetch()} />;
  }

  return (
    <MaskModeProvider>
      <TheaterContent data={data} />
    </MaskModeProvider>
  );
}

function TheaterContent({ data }: { data: NonNullable<ReturnType<typeof useTheaterReplay>['data']> }) {
  const { t } = useTranslation();
  const reducedMotion = useReducedMotion();
  const { toggle: toggleMask } = useMaskMode();

  // directionAt: maps step index to message direction for the typing indicator.
  const directionAt = useCallback(
    (step: number): 'in' | 'out' | null => {
      const msg = data.messages[step];
      return msg ? msg.direction : null;
    },
    [data.messages],
  );

  const { state, play, pause, restart, skipToEnd, scrub, setSpeed } = useTheaterPlayer({
    totalSteps: data.messages.length,
    reducedMotion,
  });

  // Keyboard shortcuts:
  //   Space         play / pause
  //   M             toggle mask
  //   ArrowRight    step forward 1 message (auto-pauses)
  //   ArrowLeft     step back 1 message (auto-pauses)
  //   Home          jump to start
  //   End           jump to end
  useEffect(() => {
    const handleKey = (e: KeyboardEvent) => {
      // Skip when user is typing in an input
      const target = e.target as HTMLElement | null;
      if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) return;

      if (e.code === 'Space') {
        e.preventDefault();
        if (state.status === 'playing') pause();
        else play();
      } else if (e.key === 'm' || e.key === 'M') {
        e.preventDefault();
        toggleMask();
      } else if (e.key === 'ArrowRight') {
        e.preventDefault();
        scrub(state.currentStep + 1);
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        scrub(state.currentStep - 1);
      } else if (e.key === 'Home') {
        e.preventDefault();
        scrub(0);
      } else if (e.key === 'End') {
        e.preventDefault();
        skipToEnd();
      }
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [state.status, state.currentStep, play, pause, scrub, skipToEnd, toggleMask]);

  const finished = useMemo(() => state.status === 'finished', [state.status]);

  // Spec 099 S3 — chapter markers on the progress bar. We derive one
  // marker per narrative beat:
  //   - first PHONE / URL / DOMAIN observation (one each, at most)
  //   - first FINANCIAL IOC (any of iban/bic/wallet*/bank_account/credit_card)
  //   - one per cascade event (≥2 IOC types yielded in a single turn)
  // The presenter scrubs to any marker by clicking it (TheaterTransport
  // wires the onClick → onScrub).
  const chapters = useMemo<TheaterChapter[]>(() => {
    const msgIdxById = new Map<string, number>();
    data.messages.forEach((m, i) => msgIdxById.set(m.msg_id, i));

    const FINANCIAL_TYPES = new Set(['iban', 'bic', 'wallet_btc', 'wallet_eth', 'wallet_xmr', 'bank_account', 'credit_card']);
    const sortedIocs = [...data.iocs_by_msg].sort((a, b) => (msgIdxById.get(a.msg_id) ?? 0) - (msgIdxById.get(b.msg_id) ?? 0));

    const firstByKind: Record<string, TheaterChapter | undefined> = {};
    const acc: TheaterChapter[] = [];

    const pushFirst = (kind: TheaterChapter['kind'], step: number, label: string) => {
      if (firstByKind[kind] === undefined && step >= 0) {
        firstByKind[kind] = { kind, step, label };
        acc.push(firstByKind[kind]!);
      }
    };

    for (const ioc of sortedIocs) {
      const step = msgIdxById.get(ioc.msg_id);
      if (step === undefined) continue;
      if (ioc.type === 'phone') pushFirst('first_phone', step, t('theater.chapter.first_phone'));
      else if (ioc.type === 'url') pushFirst('first_url', step, t('theater.chapter.first_url'));
      else if (ioc.type === 'domain') pushFirst('first_domain', step, t('theater.chapter.first_domain'));
      if (FINANCIAL_TYPES.has(ioc.type)) pushFirst('first_financial', step, t('theater.chapter.first_financial'));
    }

    for (const ev of data.human_factor.deterministic.cascade_events) {
      const step = msgIdxById.get(ev.trigger_msg_id);
      if (step !== undefined) {
        acc.push({ kind: 'cascade', step, label: t('theater.chapter.cascade', { count: ev.yielded_types.length }) });
      }
    }

    return acc.filter((c) => c.step < data.messages.length);
  }, [data.messages, data.iocs_by_msg, data.human_factor.deterministic.cascade_events, t]);

  // Spec 097 — typing direction is DERIVED from state (not dispatched).
  // Shows while we're actively playing and the next message is about to reveal.
  const typingDirection = useMemo<'in' | 'out' | null>(() => {
    if (reducedMotion) return null;
    if (state.status !== 'playing') return null;
    if (state.currentStep >= state.totalSteps) return null;
    return directionAt(state.currentStep);
  }, [reducedMotion, state.status, state.currentStep, state.totalSteps, directionAt]);

  return (
    <div className="h-screen flex flex-col bg-bg text-on-surface">
      <TheaterHeader meta={data.meta} />
      <div className="flex-1 flex overflow-hidden">
        <TheaterThread
          messages={data.messages}
          visibleStep={state.currentStep}
          iocsByMsg={data.iocs_by_msg}
          typingDirection={typingDirection}
        />
        <aside className="w-[440px] shrink-0 overflow-y-auto border-l border-outline-variant bg-surface-low/30">
          <TheaterIntelligencePanel
            iocs={data.iocs_by_msg}
            messages={data.messages}
            visibleStep={state.currentStep}
          />
          <TheaterPsychologyPanel hf={data.human_factor} meta={data.meta} finished={finished} />
        </aside>
      </div>
      <TheaterTransport
        status={state.status}
        currentStep={state.currentStep}
        totalSteps={state.totalSteps}
        speed={state.speed}
        chapters={chapters}
        onPlay={play}
        onPause={pause}
        onRestart={restart}
        onSkipToEnd={skipToEnd}
        onScrub={scrub}
        onSetSpeed={setSpeed}
      />
    </div>
  );
}

export default Theater;
