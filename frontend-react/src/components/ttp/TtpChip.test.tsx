import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TtpChip } from './TtpChip';

describe('TtpChip', () => {
  it('renders the label on the phase colour with a confidence tooltip (confirmed variant)', () => {
    render(<TtpChip code="SB-T017" label="Payment demand" phase="payment-request" confidence={0.42} />);

    const chip = screen.getByTestId('ttp-chip');
    expect(chip).toHaveTextContent('Payment demand');
    expect(chip).toHaveAttribute('title', 'Payment request · 42%');
    expect(chip.className).toContain('bg-red-500/20');
    // Confirmed variant: solid, full opacity.
    expect(chip.className).not.toContain('border-dashed');
    expect(chip.className).not.toContain('opacity-70');
  });

  it('renders the review variant with a dashed border and reduced opacity', () => {
    render(<TtpChip code="SB-T017" label="Payment demand" phase="payment-request" confidence={0.3} status="review" />);

    const chip = screen.getByTestId('ttp-chip');
    expect(chip).toHaveTextContent('Payment demand');
    expect(chip.className).toContain('border-dashed');
    expect(chip.className).toContain('opacity-70');
  });

  it('keeps confirmed status solid and tooltips the phase alone without confidence', () => {
    render(<TtpChip code="SB-T001" label="Cold outreach" phase="hook" status="confirmed" />);

    const chip = screen.getByTestId('ttp-chip');
    expect(chip).toHaveAttribute('title', 'Hook');
    expect(chip.className).not.toContain('border-dashed');
  });

  it('falls back to the code when the label is empty', () => {
    render(<TtpChip code="SB-T001" label="" phase="hook" />);

    expect(screen.getByTestId('ttp-chip')).toHaveTextContent('SB-T001');
  });
});
