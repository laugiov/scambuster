import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { StatCard } from './StatCard';

describe('StatCard', () => {
  it('renders label and value', () => {
    render(<StatCard label="Active Campaigns" value={12} />);
    expect(screen.getByText('Active Campaigns')).toBeInTheDocument();
    expect(screen.getByText('12')).toBeInTheDocument();
  });

  it('renders string value', () => {
    render(<StatCard label="Score" value="0.82" />);
    expect(screen.getByText('0.82')).toBeInTheDocument();
  });

  it('renders subtitle when provided', () => {
    render(<StatCard label="IOCs" value={89} subtitle="6 unique types" />);
    expect(screen.getByText('6 unique types')).toBeInTheDocument();
  });

  it('does not render subtitle when not provided', () => {
    const { container } = render(<StatCard label="Test" value={0} />);
    const texts = container.querySelectorAll('p');
    expect(texts.length).toBe(2); // label + value only
  });
});
