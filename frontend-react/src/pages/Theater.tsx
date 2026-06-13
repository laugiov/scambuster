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
import { TheaterTransport } from '@/components/theater/TheaterTransport';
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
    directionAt,
    reducedMotion,
  });

  // Spacebar = play/pause; M = mask toggle.
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
      }
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, [state.status, play, pause, toggleMask]);

  const finished = useMemo(() => state.status === 'finished', [state.status]);

  return (
    <div className="h-screen flex flex-col bg-bg text-on-surface">
      <TheaterHeader meta={data.meta} />
      <div className="flex-1 flex overflow-hidden">
        <TheaterThread
          messages={data.messages}
          visibleStep={state.currentStep}
          iocsByMsg={data.iocs_by_msg}
          typingDirection={state.typingDirection}
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
