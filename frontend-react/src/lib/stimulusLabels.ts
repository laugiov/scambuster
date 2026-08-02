// Stimulus-type display helpers. Mirrors the ttpLabels.ts pattern: a closed
// map of the 7 enrichment stimulus values (the backend contextual-enrichment
// enum) to an i18n label key and a Tailwind colour, with a graceful humanized
// fallback for any value not yet in the map. Wording is strictly neutral and
// temporal — a stimulus describes what the outbound message contained, never
// a causal claim about why the scammer revealed anything.

/** The closed backend enum, verbatim. */
export const STIMULUS_TYPES = [
  'DIRECT_REQUEST',
  'DOCUMENT_REQUEST',
  'PAYMENT_INITIATION',
  'TRUST_BUILDING',
  'URGENCY_PRESSURE',
  'PASSIVE',
  'UNKNOWN',
] as const;

export type StimulusType = (typeof STIMULUS_TYPES)[number];

interface StimulusConfig {
  /** i18n key under the `stimulus.*` namespace (EN + FR). */
  labelKey: string;
  /** Tailwind classes. */
  color: string;
}

const STIMULUS_MAP: Record<StimulusType, StimulusConfig> = {
  DIRECT_REQUEST: { labelKey: 'stimulus.DIRECT_REQUEST', color: 'bg-blue-500/20 text-blue-400' },
  DOCUMENT_REQUEST: { labelKey: 'stimulus.DOCUMENT_REQUEST', color: 'bg-cyan-500/20 text-cyan-400' },
  PAYMENT_INITIATION: { labelKey: 'stimulus.PAYMENT_INITIATION', color: 'bg-red-500/20 text-red-400' },
  TRUST_BUILDING: { labelKey: 'stimulus.TRUST_BUILDING', color: 'bg-emerald-500/20 text-emerald-400' },
  URGENCY_PRESSURE: { labelKey: 'stimulus.URGENCY_PRESSURE', color: 'bg-amber-500/20 text-amber-400' },
  PASSIVE: { labelKey: 'stimulus.PASSIVE', color: 'bg-slate-500/20 text-slate-300' },
  UNKNOWN: { labelKey: 'stimulus.UNKNOWN', color: 'bg-surface-highest text-on-surface-variant' },
};

/** Title-case a stimulus value as a fallback ("SOME_NEW_TYPE" → "Some New Type"). */
export function humanizeStimulus(type: string): string {
  return type
    .split(/[-_]/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ');
}

/**
 * Display label for a stimulus type: the translated `stimulus.*` entry for the
 * 7 known values, a humanized fallback otherwise. `t` is the i18n translate
 * function of the calling component.
 */
export function stimulusLabel(type: string, t: (key: string) => string): string {
  const cfg = (STIMULUS_MAP as Record<string, StimulusConfig>)[type];
  return cfg !== undefined ? t(cfg.labelKey) : humanizeStimulus(type);
}

export function stimulusColor(type: string): string {
  return (STIMULUS_MAP as Record<string, StimulusConfig>)[type]?.color
    ?? 'bg-surface-highest text-on-surface-variant';
}

/**
 * True when the stimulus reflects an outbound prompt of any kind — i.e. any
 * value other than PASSIVE (spontaneous revelation) and UNKNOWN (no signal).
 * Drives the Theater "active stimulus" badge; kept temporal, not causal:
 * presence of a prompt never asserts it produced the revelation.
 */
export function isActiveStimulus(type: string | null | undefined): boolean {
  return type != null && type !== 'PASSIVE' && type !== 'UNKNOWN';
}
