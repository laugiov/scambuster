import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { FreshnessDot } from '../FreshnessDot';

describe('FreshnessDot', () => {
  it('renders a dot tagged with the recency bucket via data attribute', () => {
    const { container } = render(<FreshnessDot bucket="now" />);
    const dot = container.querySelector('[data-recency]');
    expect(dot).not.toBeNull();
    expect(dot?.getAttribute('data-recency')).toBe('now');
  });

  it('applies the success colour for now and recent', () => {
    const { container: c1 } = render(<FreshnessDot bucket="now" />);
    const { container: c2 } = render(<FreshnessDot bucket="recent" />);
    expect(c1.querySelector('span')?.className).toContain('bg-success');
    expect(c2.querySelector('span')?.className).toContain('bg-success');
  });

  it('applies the warning colour for stale', () => {
    const { container } = render(<FreshnessDot bucket="stale" />);
    expect(container.querySelector('span')?.className).toContain('bg-warning');
  });

  it('exposes a tooltip via title and aria-label', () => {
    const { container } = render(<FreshnessDot bucket="stale" />);
    const span = container.querySelector('span');
    expect(span?.getAttribute('title')).toMatch(/2\+/);
    expect(span?.getAttribute('aria-label')).toMatch(/2\+/);
  });
});
