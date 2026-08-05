import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { RiskBar } from './RiskBar';

describe('RiskBar', () => {
  it('renders the score number', () => {
    render(<RiskBar score={75} />);
    expect(screen.getByText('75')).toBeInTheDocument();
  });

  it('renders red color for high risk (>=70)', () => {
    const { container } = render(<RiskBar score={80} />);
    expect(container.querySelector('.bg-red-500')).toBeTruthy();
  });

  it('renders amber color for medium risk (40-69)', () => {
    const { container } = render(<RiskBar score={55} />);
    expect(container.querySelector('.bg-amber-500')).toBeTruthy();
  });

  it('renders green color for low risk (<40)', () => {
    const { container } = render(<RiskBar score={20} />);
    expect(container.querySelector('.bg-emerald-500')).toBeTruthy();
  });
});
