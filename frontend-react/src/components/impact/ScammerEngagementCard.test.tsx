import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ScammerEngagementCard } from './ScammerEngagementCard';
import type { ScammerEngagementResponse } from '@/hooks/useImpact';

// Mock useScammerEngagement
vi.mock('@/hooks/useImpact', async () => {
  const actual = await vi.importActual<typeof import('@/hooks/useImpact')>('@/hooks/useImpact');
  return {
    ...actual,
    useScammerEngagement: vi.fn(),
  };
});

import { useScammerEngagement } from '@/hooks/useImpact';

const mockedHook = vi.mocked(useScammerEngagement);

const fakeData: ScammerEngagementResponse = {
  global: { observable: 143, responded: 49, rate_pct: 34.3 },
  by_scam_type: [
    { scam_type: 'INVOICE_FRAUD', observable: 31, responded: 16, rate_pct: 51.6 },
    { scam_type: 'ADVANCE_FEE_419', observable: 28, responded: 8, rate_pct: 28.6 },
  ],
  params: {
    censoring_hours: 96,
    scam_type_filter: null,
    noise_subject_patterns: 5,
    noise_sender_patterns: 5,
    honeypot_addresses: 7,
  },
  methodology_note: 'Per real sender, ...',
};

describe('ScammerEngagementCard / Spec 096 C1', () => {
  it('renders the global rate and counterpart count when data is loaded', () => {
    // @ts-expect-error mock return shape is partial
    mockedHook.mockReturnValue({ data: fakeData, isLoading: false, error: null });

    render(<ScammerEngagementCard />);

    expect(screen.getByText('34.3%')).toBeInTheDocument();
    expect(screen.getByText(/49\/143/)).toBeInTheDocument();
  });

  it('renders the breakdown rows when no filter is applied', () => {
    // @ts-expect-error mock return shape is partial
    mockedHook.mockReturnValue({ data: fakeData, isLoading: false, error: null });

    render(<ScammerEngagementCard />);

    // Both scam types should be visible in the breakdown
    expect(screen.getByText('Invoice Fraud')).toBeInTheDocument();
    expect(screen.getByText('Advance Fee (419)')).toBeInTheDocument();
  });

  it('shows a loading state when isLoading=true', () => {
    // @ts-expect-error mock return shape is partial
    mockedHook.mockReturnValue({ data: undefined, isLoading: true, error: null });

    render(<ScammerEngagementCard />);

    expect(screen.getByText('…')).toBeInTheDocument();
  });

  it('shows an error state when error is non-null', () => {
    // @ts-expect-error mock return shape is partial
    mockedHook.mockReturnValue({ data: undefined, isLoading: false, error: new Error('boom') });

    render(<ScammerEngagementCard />);

    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('has an info tooltip with the methodology', () => {
    // @ts-expect-error mock return shape is partial
    mockedHook.mockReturnValue({ data: fakeData, isLoading: false, error: null });

    const { container } = render(<ScammerEngagementCard />);

    const tooltipIcon = container.querySelector('[title]');
    expect(tooltipIcon).toBeInTheDocument();
    expect(tooltipIcon?.getAttribute('title')).toMatch(/per real sender/i);
  });
});
