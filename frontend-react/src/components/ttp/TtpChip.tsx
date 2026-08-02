import { ttpPhaseColor, ttpPhaseLabel } from '@/lib/ttpLabels';

interface TtpChipProps {
  code: string;
  label: string;
  phase: string;
  /** 0..1; when present the tooltip carries "Phase · NN%". */
  confidence?: number;
  /** Observation status; 'review' renders the dashed, dimmed variant. */
  status?: string;
  /** data-testid override; ConversationDetail keeps its legacy `ttp-badge` id. */
  testId?: string;
}

/**
 * Shared TTP chip: taxonomy label on the kill-chain phase colour, with an
 * optional confidence tooltip. Review-status observations render visually
 * distinct — dashed border + reduced opacity — so an unvalidated extraction
 * is never mistaken for a confirmed one. Falls back to the code when the
 * label is empty (the taxonomy always carries one; API drift stays legible).
 */
export function TtpChip({ code, label, phase, confidence, status, testId = 'ttp-chip' }: TtpChipProps) {
  const isReview = status === 'review';
  const title =
    confidence !== undefined
      ? `${ttpPhaseLabel(phase)} · ${Math.round(confidence * 100)}%`
      : ttpPhaseLabel(phase);

  return (
    <span
      data-testid={testId}
      title={title}
      className={`inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[0.625rem] font-medium ${ttpPhaseColor(phase)} ${
        isReview ? 'border border-dashed border-current opacity-70' : ''
      }`}
    >
      {label !== '' ? label : code}
    </span>
  );
}
