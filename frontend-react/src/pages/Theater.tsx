import { useParams } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useTheaterReplay } from '@/hooks/useTheaterReplay';
import { MaskModeProvider } from '@/hooks/MaskModeProvider';
import { TheaterHeader } from '@/components/theater/TheaterHeader';
import { TheaterThread } from '@/components/theater/TheaterThread';
import { TheaterIntelligencePanel } from '@/components/theater/TheaterIntelligencePanel';
import { TheaterPsychologyPanel } from '@/components/theater/TheaterPsychologyPanel';
import { Loading } from '@/components/feedback/Loading';
import { ErrorMessage } from '@/components/feedback/ErrorMessage';

/**
 * Spec 097 — Live Bait Theater page.
 *
 * Slice 3 deliverable: full static rendering. The thread + panels show
 * the WHOLE conversation immediately. Slice 4 will wire the
 * useTheaterPlayer state machine to drive progressive reveal.
 */
export function Theater() {
  const { t } = useTranslation();
  const { id: convId } = useParams<{ id: string }>();
  const { data, isLoading, error, refetch } = useTheaterReplay(convId);

  if (isLoading) return <Loading message={t('theater.loading')} />;
  if (error || !data) {
    return <ErrorMessage message={t('theater.error')} onRetry={() => void refetch()} />;
  }

  // Slice 3: reveal everything (visibleStep = messages.length).
  // Slice 4 will drive this via useTheaterPlayer.
  const visibleStep = data.messages.length;
  const finished = true;

  return (
    <MaskModeProvider>
      <div className="h-screen flex flex-col bg-bg text-on-surface">
        <TheaterHeader meta={data.meta} />
        <div className="flex-1 flex overflow-hidden">
          <TheaterThread
            messages={data.messages}
            visibleStep={visibleStep}
            iocsByMsg={data.iocs_by_msg}
            typingDirection={null}
          />
          <aside className="w-[440px] shrink-0 overflow-y-auto border-l border-outline-variant bg-surface-low/30">
            <TheaterIntelligencePanel
              iocs={data.iocs_by_msg}
              messages={data.messages}
              visibleStep={visibleStep}
            />
            <TheaterPsychologyPanel hf={data.human_factor} meta={data.meta} finished={finished} />
          </aside>
        </div>
      </div>
    </MaskModeProvider>
  );
}

export default Theater;
