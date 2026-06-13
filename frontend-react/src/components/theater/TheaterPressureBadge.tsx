import { useTranslation } from 'react-i18next';

interface TheaterPressureBadgeProps {
  turn: number;
  yieldedTypes: string[];
}

/**
 * Spec 097 — Adjacency marker rendered above an outbound message
 * when the next inbound IOC reveal's `stimulus_msg_id` points to it.
 *
 * IMPORTANT: label is intentionally NEUTRAL. We say "preceded" not
 * "triggered" / "caused". Per spec § Construct validity, adjacency
 * does not prove causation; the persona's pressure is intentional
 * (Spec 095 BasePromptRules) but a single observational case is not
 * causal proof.
 */
export function TheaterPressureBadge({ turn, yieldedTypes }: TheaterPressureBadgeProps) {
  const { t } = useTranslation();
  return (
    <div
      className="text-[11px] uppercase tracking-widest text-amber-300 font-mono mb-1 mr-1 self-end"
      data-testid="pressure-badge"
    >
      ↳ {t('theater.preceded_reveal', { turn })}: {yieldedTypes.join(', ')}
    </div>
  );
}
