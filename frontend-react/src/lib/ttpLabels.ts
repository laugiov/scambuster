// TTP scam-phase display helpers. Mirrors the scamTypeLabels.ts pattern: a
// closed map of the 6 taxonomy phases (see specs/152 taxonomy-v1) to a human
// label and a Tailwind colour, with a graceful humanized fallback for any code
// not yet in the map.

interface PhaseConfig {
  label: string;
  color: string; // Tailwind classes
}

// Canonical kill-chain phase order (hook → … → exit). Charts and phase
// filters are always ordered by this, never by observation count.
export const PHASE_ORDER = [
  'hook', 'trust-building', 'payment-request', 'escalation', 'channel-switch', 'exit',
] as const;

// Per-phase hues mirroring PHASE_MAP's Tailwind palette, for recharts fills.
export const PHASE_HEX: Record<string, string> = {
  hook: '#3b82f6',
  'trust-building': '#4ade80',
  'payment-request': '#f87171',
  escalation: '#fbbf24',
  'channel-switch': '#a78bfa',
  exit: '#94a3b8',
};

// hook → trust-building → payment-request → escalation → channel-switch → exit.
// Each phase gets its own hue so an actor's kill-chain position is legible by
// colour (hook = blue lure, payment = red ask, escalation = amber pressure…).
const PHASE_MAP: Record<string, PhaseConfig> = {
  hook: { label: 'Hook', color: 'bg-blue-500/20 text-blue-400' },
  'trust-building': { label: 'Trust-building', color: 'bg-emerald-500/20 text-emerald-400' },
  'payment-request': { label: 'Payment request', color: 'bg-red-500/20 text-red-400' },
  escalation: { label: 'Escalation', color: 'bg-amber-500/20 text-amber-400' },
  'channel-switch': { label: 'Channel switch', color: 'bg-violet-500/20 text-violet-400' },
  exit: { label: 'Exit', color: 'bg-slate-500/20 text-slate-300' },
};

/** Title-case a phase code as a fallback ("some-new-phase" → "Some New Phase"). */
export function humanizePhase(phase: string): string {
  return phase
    .split(/[-_]/)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1).toLowerCase())
    .join(' ');
}

export function ttpPhaseLabel(phase: string): string {
  return PHASE_MAP[phase]?.label ?? humanizePhase(phase);
}

export function ttpPhaseColor(phase: string): string {
  return PHASE_MAP[phase]?.color ?? 'bg-surface-highest text-on-surface-variant';
}
