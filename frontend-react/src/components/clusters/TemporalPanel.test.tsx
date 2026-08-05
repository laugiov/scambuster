import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TemporalPanel } from './TemporalPanel';
import type { ClusterTemporal } from '@/types/threatActor';

const { mockUse } = vi.hoisted(() => ({ mockUse: vi.fn() }));

vi.mock('@/hooks/useClusterTemporal', () => ({
  useClusterTemporal: (id: string) => mockUse(id),
}));

const temporal: ClusterTemporal = {
  message_count: 96,
  active_days: 27,
  first_activity: '2026-05-12T15:27:50+00:00',
  last_activity: '2026-07-03T13:10:07+00:00',
  active_span_days: 53,
  hour_of_day_histogram: { '16': 10 },
  peak_hour: 16,
  day_of_week_histogram: { '3': 20 },
  peak_day_of_week: 3,
  median_gap_hours: 1.495,
  busiest_day: '2026-06-17',
  max_messages_per_day: 10,
  burst_days: ['2026-05-20', '2026-06-17'],
  burst_count: 6,
  longest_dormancy_hours: 429.049,
};

describe('TemporalPanel', () => {
  beforeEach(() => mockUse.mockReset());

  it('renders the activity window, cadence and burst count', () => {
    mockUse.mockReturnValue({ data: temporal, isLoading: false });
    render(<TemporalPanel clusterId="c-1" />);

    expect(screen.getByTestId('temporal-panel')).toBeTruthy();
    expect(screen.getByTestId('temporal-hour-chart')).toBeTruthy(); // the daily-rhythm chart
    expect(screen.getByText('96')).toBeTruthy(); // inbound hero value
    expect(screen.getByText(/Inbound messages/i)).toBeTruthy();
    expect(screen.getByText('16:00')).toBeTruthy(); // peak hour
    expect(screen.getAllByText('Wed').length).toBeGreaterThan(0); // peak weekday (ISO dow 3) + strip
    expect(screen.getByTestId('temporal-burst-count').textContent).toContain('6');
  });

  it('renders an empty state when the cluster has no inbound activity', () => {
    mockUse.mockReturnValue({ data: null, isLoading: false });
    render(<TemporalPanel clusterId="c-1" />);

    expect(screen.getByTestId('temporal-empty')).toBeTruthy();
    expect(screen.queryByTestId('temporal-panel')).toBeNull();
  });

  it('renders nothing while loading', () => {
    mockUse.mockReturnValue({ data: undefined, isLoading: true });
    const { container } = render(<TemporalPanel clusterId="c-1" />);

    expect(container.firstChild).toBeNull();
  });
});
