import type { MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import type { PlayerStatus } from '@/hooks/useTheaterPlayer';

/**
 * Spec 099 S3 — a chapter marker is a single point on the progress bar
 * that highlights a narrative beat: first PHONE shared, first URL,
 * first DOMAIN, first FINANCIAL IOC, or a cascade event. Clicking a
 * marker scrubs to its step. Computed in Theater.tsx and passed in.
 */
export interface TheaterChapter {
  step: number; // 0-based, must satisfy 0 <= step < totalSteps
  kind: 'first_phone' | 'first_url' | 'first_domain' | 'first_financial' | 'cascade';
  label: string;
}

interface TheaterTransportProps {
  status: PlayerStatus;
  currentStep: number;
  totalSteps: number;
  speed: 1 | 2 | 4;
  chapters?: TheaterChapter[];
  onPlay: () => void;
  onPause: () => void;
  onRestart: () => void;
  onSkipToEnd: () => void;
  onScrub: (step: number) => void;
  onSetSpeed: (speed: 1 | 2 | 4) => void;
}

/**
 * Spec 097 / Slice 4 — Transport bar.
 *
 * Play / pause / restart / skip / progress + speed (1×, 2×, 4×).
 * Keyboard shortcuts wired in Theater page: Space (play/pause), M (mask),
 * ← / → (step prev/next, auto-pause), Home / End (jump to start/end).
 */
export function TheaterTransport({
  status,
  currentStep,
  totalSteps,
  speed,
  chapters = [],
  onPlay,
  onPause,
  onRestart,
  onSkipToEnd,
  onScrub,
  onSetSpeed,
}: TheaterTransportProps) {
  const { t } = useTranslation();
  const isPlaying = status === 'playing';
  const pct = totalSteps > 0 ? (currentStep / totalSteps) * 100 : 0;

  const handleProgressClick = (e: MouseEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const ratio = (e.clientX - rect.left) / rect.width;
    onScrub(Math.round(ratio * totalSteps));
  };

  return (
    <div className="border-t border-outline-variant bg-surface-low px-6 py-3 flex items-center gap-3 shrink-0">
      <button
        type="button"
        onClick={isPlaying ? onPause : onPlay}
        aria-label={isPlaying ? t('theater.pause') : t('theater.play')}
        className="w-10 h-10 rounded-full bg-accent text-bg font-bold text-base flex items-center justify-center hover:bg-accent-hover cursor-pointer"
        data-testid="play-pause"
      >
        {isPlaying ? '❚❚' : '▶'}
      </button>
      <button
        type="button"
        onClick={onRestart}
        className="text-xs px-2.5 py-1.5 rounded border border-outline-variant text-on-surface-variant hover:text-on-surface cursor-pointer"
        data-testid="restart"
      >
        ↻ {t('theater.restart')}
      </button>
      <button
        type="button"
        onClick={onSkipToEnd}
        className="text-xs px-2.5 py-1.5 rounded border border-outline-variant text-on-surface-variant hover:text-on-surface cursor-pointer"
        data-testid="skip"
      >
        ⏭ {t('theater.skip')}
      </button>

      <div
        onClick={handleProgressClick}
        className="flex-1 h-1.5 bg-surface-high rounded cursor-pointer relative mx-2"
        role="slider"
        aria-label={t('theater.progress')}
        aria-valuemin={0}
        aria-valuemax={totalSteps}
        aria-valuenow={currentStep}
        tabIndex={0}
        data-testid="progress-bar"
      >
        <div className="absolute inset-y-0 left-0 bg-accent rounded" style={{ width: `${pct}%` }} />
        {/* Tick marks per message */}
        {Array.from({ length: Math.max(0, totalSteps - 1) }, (_, i) => (
          <span
            key={i}
            className="absolute top-[-3px] w-px h-3 bg-outline-variant pointer-events-none"
            style={{ left: `${((i + 1) / totalSteps) * 100}%` }}
          />
        ))}
        {/* Spec 099 S3 — chapter markers (clickable, scrub on click) */}
        {chapters.map((c) => (
          <button
            key={`${c.kind}-${c.step}`}
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onScrub(c.step);
            }}
            title={c.label}
            aria-label={c.label}
            data-testid={`chapter-${c.kind}`}
            className={`absolute top-[-6px] w-2 h-5 rounded-sm cursor-pointer hover:scale-125 transition-transform ${chapterColorClass(c.kind)}`}
            style={{ left: `${(c.step / totalSteps) * 100}%` }}
          />
        ))}
      </div>

      <p className="text-[11px] font-mono text-on-surface-dim w-16 text-right">
        {currentStep}/{totalSteps}
      </p>

      <p
        className="text-[10px] font-mono text-on-surface-dim hidden md:block"
        title={t('theater.keyboardHintTitle')}
        data-testid="keyboard-hint"
      >
        ← → space M
      </p>

      {[1, 2, 4].map((s) => (
        <button
          key={s}
          type="button"
          onClick={() => onSetSpeed(s as 1 | 2 | 4)}
          className={`text-xs px-2 py-1.5 rounded cursor-pointer ${
            speed === s
              ? 'text-accent border border-accent'
              : 'text-on-surface-dim border border-outline-variant hover:text-on-surface'
          }`}
          data-testid={`speed-${s}x`}
        >
          {s}×
        </button>
      ))}
    </div>
  );
}

function chapterColorClass(kind: TheaterChapter['kind']): string {
  switch (kind) {
    case 'first_financial':
      return 'bg-amber-400 border border-amber-500';
    case 'cascade':
      return 'bg-purple-400 border border-purple-500';
    case 'first_phone':
      return 'bg-emerald-400 border border-emerald-500';
    case 'first_url':
    case 'first_domain':
      return 'bg-sky-400 border border-sky-500';
  }
}
