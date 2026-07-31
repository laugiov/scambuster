import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AnchorReachRow } from './AnchorReachRow';

/**
 * Regression coverage for the STIMULUS_COLORS retirement: the dominant-stimulus
 * chip now sources its colour from stimulusColor() (the real 7-value enum), so
 * values that used to fall through the stale map to gray render a real hue.
 */
function baseAnchor(overrides: Record<string, unknown> = {}) {
  return {
    indicator_id: 'ind-1',
    ioc_type: 'iban',
    ioc_value: 'FR7612345678901234567890123',
    conv_count: 4,
    dominant_semantic_role: 'PAYMENT_DESTINATION',
    ...overrides,
  };
}

describe('AnchorReachRow stimulus colour', () => {
  it('colours PAYMENT_INITIATION with its real hue (previously gray)', () => {
    render(
      <AnchorReachRow
        anchor={baseAnchor({ dominant_stimulus: 'PAYMENT_INITIATION' })}
        totalConversations={10}
        isSelected={false}
        onSelect={() => {}}
        onOpenDetail={() => {}}
      />,
    );
    const chip = screen.getByText('Payment Initiation');
    expect(chip.className).toContain('bg-red-500/20');
    expect(chip.className).not.toContain('on-surface-dim/20');
  });

  it('colours TRUST_BUILDING with its real hue (previously gray)', () => {
    render(
      <AnchorReachRow
        anchor={baseAnchor({ dominant_stimulus: 'TRUST_BUILDING' })}
        totalConversations={10}
        isSelected={false}
        onSelect={() => {}}
        onOpenDetail={() => {}}
      />,
    );
    const chip = screen.getByText('Trust Building');
    expect(chip.className).toContain('bg-emerald-500/20');
  });
});
