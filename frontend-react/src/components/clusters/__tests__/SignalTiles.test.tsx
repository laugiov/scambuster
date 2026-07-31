import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { SignalTiles } from '../SignalTiles';

const baseProfile = {
  dominant_stimulus: 'DIRECT_REQUEST',
  dominant_stimulus_count: 3,
  dominant_revelation_turn: 1,
  avg_urgency_score: 0.4,
  hesitation_count: 0,
  language_switch_count: 0,
  templated_excerpt_count: 0,
  total_excerpt_variant_count: null,
};

describe('SignalTiles', () => {
  it('renders 3 tiles by default (no template signal)', () => {
    render(<SignalTiles profile={baseProfile} anchors={[]} conversationCount={3} />);
    expect(screen.getByTestId('signal-tile-tactic')).toBeDefined();
    expect(screen.getByTestId('signal-tile-reveal')).toBeDefined();
    expect(screen.getByTestId('signal-tile-urgency')).toBeDefined();
    expect(screen.queryByTestId('signal-tile-automation')).toBeNull();
  });

  it('renders the automation tile when templated_excerpt_count > 1', () => {
    render(
      <SignalTiles
        profile={{ ...baseProfile, templated_excerpt_count: 58, total_excerpt_variant_count: 5 }}
        anchors={[]}
        conversationCount={39}
      />,
    );
    const tile = screen.getByTestId('signal-tile-automation');
    expect(tile.textContent).toMatch(/Templated/);
    expect(tile.textContent).toMatch(/58 IOCs across 5 excerpt variants/);
  });

  it('shows the urgency placeholder when detector fires', () => {
    render(
      <SignalTiles
        profile={{ ...baseProfile, avg_urgency_score: 0.2 }}
        anchors={[{ avg_urgency_score: 0.2 }, { avg_urgency_score: 0.2 }]}
        conversationCount={3}
      />,
    );
    expect(screen.getByTestId('signal-tile-urgency-placeholder')).toBeDefined();
    expect(screen.queryByTestId('signal-tile-urgency')).toBeNull();
  });

  it('shows the real urgency value when detector does not fire', () => {
    render(<SignalTiles profile={baseProfile} anchors={[]} conversationCount={3} />);
    const tile = screen.getByTestId('signal-tile-urgency');
    expect(tile.textContent).toMatch(/40%/);
    expect(tile.textContent).toMatch(/medium pressure/);
  });

  it('renders "initial email" sub-caption for turn 1', () => {
    render(<SignalTiles profile={baseProfile} anchors={[]} conversationCount={3} />);
    expect(screen.getByTestId('signal-tile-reveal').textContent).toMatch(/initial email/);
  });

  it('falls back to dash when stimulus is missing', () => {
    render(
      <SignalTiles
        profile={{ ...baseProfile, dominant_stimulus: null, dominant_stimulus_count: 0 }}
        anchors={[]}
        conversationCount={3}
      />,
    );
    expect(screen.getByTestId('signal-tile-tactic').textContent).toMatch(/—/);
  });
});
