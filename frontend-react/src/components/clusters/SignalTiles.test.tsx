import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SignalTiles } from './SignalTiles';

/**
 * Regression coverage for the STIMULUS_COLORS retirement: the Primary-tactic
 * tile now sources its colour from stimulusColor() (the real 7-value enum), so
 * values that used to fall through the stale map to gray render a real hue.
 */
function baseProfile(overrides: Record<string, unknown> = {}) {
  return {
    dominant_stimulus: 'TRUST_BUILDING',
    dominant_stimulus_count: 5,
    dominant_revelation_turn: 2,
    avg_urgency_score: 0.5,
    hesitation_count: 0,
    language_switch_count: 0,
    templated_excerpt_count: 0,
    ...overrides,
  };
}

describe('SignalTiles stimulus colour', () => {
  it('colours the TRUST_BUILDING primary tactic with its real hue (previously gray)', () => {
    render(<SignalTiles profile={baseProfile()} anchors={[{ avg_urgency_score: 0.5 }]} conversationCount={10} />);
    const chip = screen.getByText('Trust Building');
    expect(chip.className).toContain('bg-emerald-500/20');
    expect(chip.className).not.toContain('bg-surface-dim');
  });

  it('colours the DOCUMENT_REQUEST primary tactic with its real hue (previously gray)', () => {
    render(
      <SignalTiles
        profile={baseProfile({ dominant_stimulus: 'DOCUMENT_REQUEST' })}
        anchors={[{ avg_urgency_score: 0.5 }]}
        conversationCount={10}
      />,
    );
    const chip = screen.getByText('Document Request');
    expect(chip.className).toContain('bg-cyan-500/20');
  });
});
