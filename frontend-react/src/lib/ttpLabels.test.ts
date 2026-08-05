import { describe, it, expect } from 'vitest';
import { ttpPhaseLabel, ttpPhaseColor, humanizePhase, PHASE_ORDER, PHASE_HEX } from './ttpLabels';

describe('ttpPhaseLabel', () => {
  it('returns mapped label for each known phase', () => {
    expect(ttpPhaseLabel('hook')).toBe('Hook');
    expect(ttpPhaseLabel('trust-building')).toBe('Trust-building');
    expect(ttpPhaseLabel('payment-request')).toBe('Payment request');
    expect(ttpPhaseLabel('escalation')).toBe('Escalation');
    expect(ttpPhaseLabel('channel-switch')).toBe('Channel switch');
    expect(ttpPhaseLabel('exit')).toBe('Exit');
  });

  it('humanizes an unknown phase code as a fallback', () => {
    expect(ttpPhaseLabel('some-new-phase')).toBe('Some New Phase');
  });
});

describe('ttpPhaseColor', () => {
  it('returns colour classes for a known phase', () => {
    expect(ttpPhaseColor('payment-request')).toContain('red');
    expect(ttpPhaseColor('hook')).toContain('blue');
  });

  it('returns a neutral colour for an unknown phase', () => {
    expect(ttpPhaseColor('mystery')).toContain('surface');
  });
});

describe('humanizePhase', () => {
  it('title-cases dash- and underscore-separated codes', () => {
    expect(humanizePhase('trust-building')).toBe('Trust Building');
    expect(humanizePhase('channel_switch')).toBe('Channel Switch');
  });
});

describe('PHASE_ORDER', () => {
  it('lists the 6 canonical phases in kill-chain order', () => {
    expect(PHASE_ORDER).toEqual([
      'hook', 'trust-building', 'payment-request', 'escalation', 'channel-switch', 'exit',
    ]);
  });

  it('has a mapped display label and colour for every canonical phase', () => {
    for (const phase of PHASE_ORDER) {
      expect(ttpPhaseLabel(phase).length).toBeGreaterThan(0);
      // Known phases resolve from the closed map, never the neutral fallback.
      expect(ttpPhaseColor(phase)).not.toBe('bg-surface-highest text-on-surface-variant');
    }
  });
});

describe('PHASE_HEX', () => {
  it('provides a hex hue for every canonical phase', () => {
    for (const phase of PHASE_ORDER) {
      expect(PHASE_HEX[phase]).toMatch(/^#[0-9a-f]{6}$/);
    }
  });

  it('assigns a distinct hue per phase (legibility by colour)', () => {
    const hues = PHASE_ORDER.map((phase) => PHASE_HEX[phase]);
    expect(new Set(hues).size).toBe(PHASE_ORDER.length);
  });
});
