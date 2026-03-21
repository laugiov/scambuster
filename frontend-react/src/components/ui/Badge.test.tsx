import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Badge, statusToBadgeVariant } from './Badge';

describe('Badge', () => {
  it('renders label text', () => {
    render(<Badge label="OPEN" />);
    expect(screen.getByText('OPEN')).toBeInTheDocument();
  });

  it('applies engaging variant styles', () => {
    const { container } = render(<Badge label="active" variant="engaging" />);
    const badge = container.firstChild as HTMLElement;
    expect(badge.className).toContain('text-status-engaging');
  });

  it('applies default variant when no variant provided', () => {
    const { container } = render(<Badge label="test" />);
    const badge = container.firstChild as HTMLElement;
    expect(badge.className).toContain('text-on-surface-variant');
  });
});

describe('statusToBadgeVariant', () => {
  it('maps open to engaging', () => {
    expect(statusToBadgeVariant('open')).toBe('engaging');
  });

  it('maps closed to closed', () => {
    expect(statusToBadgeVariant('closed')).toBe('closed');
  });

  it('maps waiting to waiting', () => {
    expect(statusToBadgeVariant('waiting')).toBe('waiting');
  });

  it('maps abandoned to done', () => {
    expect(statusToBadgeVariant('abandoned')).toBe('done');
  });

  it('maps unknown status to default', () => {
    expect(statusToBadgeVariant('xyz')).toBe('default');
  });

  it('is case insensitive', () => {
    expect(statusToBadgeVariant('OPEN')).toBe('engaging');
    expect(statusToBadgeVariant('Closed')).toBe('closed');
  });
});
