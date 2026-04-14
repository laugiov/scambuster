import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Loading } from './Loading';

describe('Loading', () => {
  it('renders without crashing', () => {
    render(<Loading />);
  });

  it('shows default loading text from i18n', () => {
    render(<Loading />);
    expect(screen.getByText('Loading...')).toBeInTheDocument();
  });

  it('shows custom message when provided', () => {
    render(<Loading message="Fetching IOCs..." />);
    expect(screen.getByText('Fetching IOCs...')).toBeInTheDocument();
  });

  it('renders spinner SVG', () => {
    const { container } = render(<Loading />);
    const svg = container.querySelector('svg');
    expect(svg).toBeInTheDocument();
    expect(svg?.classList.contains('animate-spin')).toBe(true);
  });
});
